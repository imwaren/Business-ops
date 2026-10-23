<?php

namespace App\Libraries;

use RuntimeException;
use ZipArchive;

/**
 * Two-pass XLSX writer: private JSONL row spools, then streamed worksheet XML.
 * Report queries and authentication deliberately do not belong in this class.
 */
class Excel_workbook
{
    private $tables = [];
    private $paths = [];
    private $files = [];
    private $sheets = [];
    private $hyperlinks;
    private $current_sheet;
    private $hyperlink_count = 0;
    private $row_id = 0;
    private $prefix;
    private $check;
    private $row_limit;
    private $column_limit;
    private $text_limit;

    public function __construct(int $row_limit = 1048576, int $column_limit = 16384, int $text_limit = 32767)
    {
        if ($row_limit < 2 || $row_limit > 1048576 || $column_limit < 6 || $column_limit > 16384 || $text_limit < 64 || $text_limit > 32767) {
            throw new RuntimeException('Invalid worksheet limits.');
        }
        $this->row_limit = $row_limit;
        $this->column_limit = $column_limit;
        $this->text_limit = $text_limit;
    }

    public function write(string $path, $response, string $source, ?callable $check = null): void
    {
        $this->tables = $this->paths = $this->files = $this->sheets = [];
        $this->row_id = 0;
        $this->prefix = $path . '.work.' . bin2hex(random_bytes(5));
        $this->check = $check ?? static function () {};
        $zip = new ZipArchive();
        $opened = false;
        $completed = false;
        try {
            $summary = $this->table('@export/summary', 'Summary');
            $this->add_row($summary, ['field' => 'source', 'value' => $source], null, 0, '/');
            if (is_array($response)) {
                $this->list_rows($this->table('/data', 'Data'), $response, null, '/');
            } elseif (is_object($response)) {
                foreach (get_object_vars($response) as $key => $value) {
                    $pointer = '/' . $this->pointer((string) $key);
                    if (is_array($value)) {
                        $table = $this->table($pointer, $key === 'data' ? 'Data' : (string) $key);
                        $this->list_rows($table, $value, null, $pointer);
                        $this->add_row($summary, ['field' => $key, 'value' => $this->link($table), 'type' => 'array'], null, 0, $pointer, true);
                    } elseif (is_object($value)) {
                        $this->metadata($summary, $value, $pointer);
                    } else {
                        $this->add_row($summary, ['field' => $key, 'value' => $value, 'type' => gettype($value)], null, 0, $pointer);
                    }
                }
            } else {
                $this->add_row($summary, ['field' => 'response', 'value' => $response], null, 0, '/');
            }
            // Include a schema index. Row IDs link children even across continuation sheets.
            foreach ($this->tables as $table) {
                if ($table['id'] !== $summary) {
                    $this->add_row($summary, [
                        'field' => 'worksheet', 'value' => $this->link($table['id']),
                        'source_path' => $table['path'], 'row_count' => $table['count'],
                    ], null, 0, '/@worksheets', true);
                }
            }
            $this->add_row($summary, ['field' => 'layout', 'value' => 'The first four columns are export row ID, parent row ID, original index and source path. Source columns follow, unchanged. Empty cells explicitly present in XML represent null; absent cells represent missing fields. Long_text chunks and continuation sheets retain overflow.'], null, 0, '/@layout');

            if (file_put_contents($path, '') === false || !chmod($path, 0600)) {
                throw new RuntimeException('Cannot create private workbook.');
            }
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create workbook.');
            }
            $opened = true;
            chmod($path, 0600);
            // Rendering may add Long_text rows, so render that table last.
            for ($id = 0; $id < count($this->tables); $id++) {
                if ($this->tables[$id]['path'] !== '@export/long_text') {
                    $this->render_table($zip, $id);
                }
            }
            if (isset($this->paths['@export/long_text'])) {
                $this->render_table($zip, $this->paths['@export/long_text']);
            }
            $this->package($zip);
            if (!$zip->close()) {
                throw new RuntimeException('Cannot finish workbook.');
            }
            $opened = false;
            $completed = true;
        } finally {
            if ($opened) {
                try {
                    $zip->close();
                } catch (\Throwable $exception) {
                    // Preserve the original render/package failure while cleaning private files.
                }
            }
            if (!$completed && is_file($path)) {
                unlink($path);
            }
            if (is_resource($this->hyperlinks)) {
                fclose($this->hyperlinks);
            }
            foreach ($this->files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function metadata(int $summary, object $object, string $path): void
    {
        if (get_object_vars($object) === []) {
            $this->add_row($summary, ['field' => $path, 'value' => '{}', 'type' => 'object'], null, 0, $path);
        }
        foreach (get_object_vars($object) as $key => $value) {
            $pointer = $path . '/' . $this->pointer((string) $key);
            if (is_object($value)) {
                $this->metadata($summary, $value, $pointer);
            } elseif (is_array($value)) {
                $table = $this->table($pointer, (string) $key);
                $this->list_rows($table, $value, null, $pointer);
                $this->add_row($summary, ['field' => $pointer, 'value' => $this->link($table), 'type' => 'array'], null, 0, $pointer, true);
            } else {
                $this->add_row($summary, ['field' => $pointer, 'value' => $value, 'type' => gettype($value)], null, 0, $pointer);
            }
        }
    }

    private function list_rows(int $table, array $rows, ?string $parent, string $path): void
    {
        foreach ($rows as $index => $row) {
            $this->add_row($table, $row, $parent, $index, $path . '/' . $this->pointer((string) $index));
        }
    }

    private function add_row(int $table, $row, ?string $parent, $index, string $path, bool $references = false): void
    {
        ($this->check)();
        $id = 'r' . ++$this->row_id;
        $values = [];
        $object = is_object($row) ? get_object_vars($row) : (is_array($row) && !array_is_list($row) ? $row : null);
        if ($object === null) {
            $object = ['value' => $row];
        }
        foreach ($object as $key => $value) {
            $key = (string) $key;
            if ($references && is_array($value) && isset($value['sheet'], $value['label'])) {
                $values[$key] = $value;
            } elseif (is_array($value) || is_object($value)) {
                $child_path = $this->tables[$table]['path'] . '/' . $this->pointer($key);
                $child = $this->table($child_path, $key);
                $values[$key] = $this->link($child);
                if (is_array($value)) {
                    $this->list_rows($child, $value, $id, $path . '/' . $this->pointer($key));
                    if ($value === []) {
                        $values[$key]['label'] .= ' []';
                    }
                } else {
                    $this->add_row($child, $value, $id, 0, $path . '/' . $this->pointer($key));
                }
            } else {
                $values[$key] = $value;
            }
            // Prefix keys prevents PHP from canonicalizing numeric source field names.
            $this->tables[$table]['headers']['f:' . $key] = $key;
        }
        $record = ['meta' => [$id, $parent, $index, $path], 'values' => (object) $values];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($this->tables[$table]['spool'], $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Cannot spool report rows.');
        }
        $this->tables[$table]['count']++;
    }

    private function table(string $path, string $label): int
    {
        if (isset($this->paths[$path])) {
            return $this->paths[$path];
        }
        $id = count($this->tables);
        $name = preg_replace('/[\x00-\x1f\[\]:*?\/\\\\]/u', '_', $label) ?: 'Table';
        $name = trim($name, " '\t\n\r");
        $name = mb_substr($name, 0, 14) ?: 'Table';
        while ($this->length($name) > 14) {
            $name = mb_substr($name, 0, -1);
        }
        // ASCII suffixes reserve room for horizontal/vertical continuation identifiers.
        if ($id > 0) {
            $name .= '_' . $id;
        }
        $file = $this->prefix . '.table' . $id;
        $this->files[] = $file;
        if (file_put_contents($file, '') === false) {
            throw new RuntimeException('Cannot create report spool.');
        }
        chmod($file, 0600);
        $this->tables[$id] = ['id' => $id, 'path' => $path, 'name' => $name, 'spool' => $file, 'headers' => [], 'count' => 0];
        $this->paths[$path] = $id;
        return $id;
    }

    private function link(int $table): array
    {
        return ['sheet' => $this->tables[$table]['name'], 'label' => 'Worksheet: ' . $this->tables[$table]['name']];
    }

    private function render_table(ZipArchive $zip, int $id): void
    {
        $table = $this->tables[$id];
        $headers = array_values($table['headers']);
        $metadata = ['__export_row_id', '__export_parent_id', '__export_index', '__export_path'];
        foreach ($metadata as &$name) {
            while (in_array($name, $headers, true)) {
                $name = '_' . $name;
            }
        }
        unset($name);
        $chunks = array_chunk($headers, $this->column_limit - 4) ?: [[]];
        foreach ($chunks as $column_part => $fields) {
            $input = fopen($table['spool'], 'rb');
            if (!$input) {
                throw new RuntimeException('Cannot read report spool.');
            }
            $output = null;
            $row_number = 0;
            $row_part = 0;
            try {
                do {
                    ($this->check)();
                    $line = fgets($input);
                    $record = $line !== false ? json_decode($line, true, 512, JSON_THROW_ON_ERROR) : null;
                    $values = $record ? $record['meta'] : [];
                    if ($record) {
                        foreach ($fields as $field) {
                            $values[] = array_key_exists($field, $record['values']) ? $record['values'][$field] : ['missing' => true];
                        }
                    }
                    $links_needed = count(array_filter($values, function ($value) {
                        return (is_array($value) && isset($value['sheet'])) || (is_string($value) && $this->length($value) > $this->text_limit);
                    }));
                    if (!$output || ($line !== false && ($row_number >= $this->row_limit || $this->hyperlink_count + $links_needed > 65530))) {
                        if ($output) {
                            $this->finish_sheet($zip, $output, $sheet);
                        }
                        $suffix = ($column_part ? '_c' . ($column_part + 1) : '') . ($row_part ? '_r' . ($row_part + 1) : '');
                        $sheet = ['name' => $table['name'] . $suffix, 'path' => $this->prefix . '.sheet' . count($this->sheets) . '.xml'];
                        $link_path = $sheet['path'] . '.links';
                        $this->files[] = $link_path;
                        $this->hyperlinks = fopen($link_path, 'w+b');
                        if (!$this->hyperlinks) {
                            throw new RuntimeException('Cannot stream worksheet links.');
                        }
                        chmod($link_path, 0600);
                        if (mb_strlen($sheet['name'], 'UTF-8') > 31) {
                            throw new RuntimeException('Worksheet continuation exceeds naming limits.');
                        }
                        $this->hyperlink_count = 0;
                        $this->current_sheet = $sheet['name'];
                        $this->sheets[] = $sheet;
                        $this->files[] = $sheet['path'];
                        $output = fopen($sheet['path'], 'wb');
                        if (!$output) {
                            throw new RuntimeException('Cannot stream worksheet.');
                        }
                        chmod($sheet['path'], 0600);
                        $this->put($output, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>');
                        $row_number = 1;
                        $this->write_row($output, array_merge($metadata, $fields), 1, 'header' . $id, true);
                        $row_part++;
                    }
                    if ($line !== false) {
                        $this->write_row($output, $values, ++$row_number, $record['meta'][0]);
                    }
                } while ($line !== false);
                $this->finish_sheet($zip, $output, $sheet);
                $output = null;
            } finally {
                fclose($input);
                if (is_resource($output)) {
                    fclose($output);
                }
            }
        }
    }

    private function write_row($handle, array $values, int $number, string $parent, bool $header = false): void
    {
        $this->put($handle, '<row r="' . $number . '">');
        foreach ($values as $column => $value) {
            if (is_array($value) && isset($value['missing'])) {
                continue;
            }
            $reference = $this->column($column + 1) . $number;
            $style = $header ? ' s="1"' : '';
            if ($value === null) {
                $cell = '<c r="' . $reference . '"' . $style . '/>';
            } elseif (is_bool($value)) {
                $cell = '<c r="' . $reference . '" t="b"' . $style . '><v>' . (int) $value . '</v></c>';
            } elseif (is_int($value) || (is_float($value) && is_finite($value))) {
                if (is_int($value) && strlen(ltrim((string) $value, '-')) > 15) {
                    $exact = $this->table('@export/numeric_values', 'Numeric_values');
                    $this->add_row($exact, ['worksheet' => $this->current_sheet, 'cell' => $reference, 'type' => 'integer', 'text' => (string) $value], $parent, 0, '/@numeric_values');
                }
                $cell = '<c r="' . $reference . '" t="n"' . $style . '><v>' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . '</v></c>';
            } else {
                $text = is_array($value) ? $value['label'] : (string) $value;
                if ($this->length($text) > $this->text_limit) {
                    $long = $this->table('@export/long_text', 'Long_text');
                    // The chunks themselves fit, so this cannot recursively create overflow.
                    foreach ($this->text_chunks($text) as $part => $chunk) {
                        $this->add_row($long, ['worksheet' => $this->current_sheet, 'cell' => $reference, 'part' => $part + 1, 'text' => $chunk], $parent, $part, '@export/long_text');
                    }
                    $text = 'Long_text:' . $parent . ':' . $reference;
                    $value = $this->link($long);
                }
                $cell = '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $this->xml($text) . '</t></is></c>';
            }
            if (is_array($value) && isset($value['sheet'])) {
                $this->hyperlink_count++;
                $this->put($this->hyperlinks, '<hyperlink ref="' . $reference . '" location="' . $this->xml("'" . str_replace("'", "''", $value['sheet']) . "'!A1") . '"/>');
            }
            $this->put($handle, $cell);
        }
        $this->put($handle, '</row>');
    }

    private function finish_sheet(ZipArchive $zip, $output, array $sheet): void
    {
        $this->put($output, '</sheetData>');
        if (ftell($this->hyperlinks) > 0) {
            $this->put($output, '<hyperlinks>');
            rewind($this->hyperlinks);
            if (stream_copy_to_stream($this->hyperlinks, $output) === false) {
                throw new RuntimeException('Cannot write worksheet links.');
            }
            $this->put($output, '</hyperlinks>');
        }
        fclose($this->hyperlinks);
        $this->hyperlinks = null;
        $this->put($output, '</worksheet>');
        if (!fclose($output) || !$zip->addFile($sheet['path'], 'xl/worksheets/sheet' . (array_search($sheet, $this->sheets, true) + 1) . '.xml')) {
            throw new RuntimeException('Cannot package worksheet.');
        }
    }

    private function package(ZipArchive $zip): void
    {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $book = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $index => $sheet) {
            $n = $index + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $book .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $entries = [
            '[Content_Types].xml' => $types . '</Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $book . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $rels,
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        foreach ($entries as $name => $xml) {
            if (!$zip->addFromString($name, $xml)) {
                throw new RuntimeException('Cannot package workbook metadata.');
            }
        }
    }

    private function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function text_chunks(string $text): array
    {
        // Half the limit in Unicode characters fits even when every character is a surrogate pair.
        $chunks = [];
        $size = max(1, intdiv($this->text_limit, 2));
        for ($i = 0, $length = mb_strlen($text); $i < $length; $i += $size) {
            $chunks[] = mb_substr($text, $i, $size);
        }
        return $chunks;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/_x[0-9a-fA-F]{4}_/', '_x005F$0', $value);
        $value = preg_replace_callback('/[\x00-\x08\x0b\x0c\x0d\x0e-\x1f\x{fffe}\x{ffff}]/u', static function ($match) {
            return sprintf('_x%04X_', mb_ord($match[0]));
        }, $value);
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function pointer(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private function column(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private function put($handle, string $text): void
    {
        if (fwrite($handle, $text) !== strlen($text)) {
            throw new RuntimeException('Cannot write workbook.');
        }
    }
}
