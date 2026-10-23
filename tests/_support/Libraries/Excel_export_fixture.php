<?php

namespace Tests\Support\Libraries;

use App\Libraries\Excel_export;
use RuntimeException;

class Excel_export_fixture extends Excel_export
{
    public $launch_failure = false;
    public $render_failure = false;
    public $publication_failure = false;
    public $corrupt_copy = false;
    public $rename_failure = false;
    public $after_publish_failure = false;
    public $invalid_workbook = false;
    public $publications = [];
    public $alive = true;
    public $during_publish;
    private $publishing_job;

    public function directory(): string
    {
        return $this->directory;
    }

    public function document_root(): string
    {
        return realpath(dirname($this->directory)) . '/web';
    }

    public function file_path(array $job): string
    {
        $relative_path = 'assets/exports/excel/' . $this->namespace . '/' . $job['export_id'] . '/' . $job['file_name'];
        return $this->document_root() . '/' . $relative_path;
    }

    public function matching_php(): string
    {
        return $this->php_cli();
    }

    protected function public_root(): string
    {
        return $this->document_root() . '/assets/exports/excel';
    }

    protected function launch(string $id): int
    {
        if ($this->launch_failure) {
            throw new RuntimeException('secret-token must never be exposed');
        }
        return getmypid();
    }

    protected function render(string $path, $snapshot, string $source, callable $check): void
    {
        if ($this->render_failure) {
            throw new RuntimeException('private report values');
        }
        if ($this->invalid_workbook) {
            file_put_contents($path, 'Not a workbook');
            return;
        }
        parent::render($path, $snapshot, $source, $check);
    }

    protected function process_alive(int $pid): bool
    {
        return $this->alive;
    }

    protected function publish(string $source, array $job): void
    {
        $this->publishing_job = $job;
        parent::publish($source, $job);
        if ($this->after_publish_failure) {
            throw new RuntimeException('private publication failure after rename');
        }
        $path = $this->file_path($job);
        $this->publications[] = ['bytes' => file_get_contents($path), 'name' => $job['file_name'], 'path' => $path];
    }

    protected function copy_workbook(string $source, string $staging): void
    {
        if ($this->publication_failure) {
            file_put_contents($staging, 'partial bytes');
            throw new RuntimeException('secret and private filesystem path');
        }
        parent::copy_workbook($source, $staging);
        if ($this->during_publish) {
            ($this->during_publish)($staging, $this->publishing_job);
        }
        if ($this->corrupt_copy) {
            file_put_contents($staging, 'corrupted copy');
        }
    }

    protected function publish_file(string $staging, string $destination): void
    {
        if ($this->rename_failure) {
            throw new RuntimeException('private rename failure');
        }
        parent::publish_file($staging, $destination);
    }
}
