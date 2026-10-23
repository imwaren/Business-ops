<?php
namespace App\Controllers;

use App\Core\MYT_Controller;

class Files extends MYT_Controller
{
    protected $db;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->_load_essentials();
    }

    private function _load_essentials()
    {
        $this->db = db_connect();
    }

    public function get_file()
    {
        $url = $this->request->getVar('file') ?: null;

        if (empty($url) || $url == "null" || $url == "undefined") {
            return $this->_create_response(FAIL, ['message' => 'Invalid Request: file parameter is required.']);
        }

        // Normalize double slashes AFTER protocol
        $url = preg_replace('#(?<!:)//+#', '/', $url);

        // Ensure the URL starts with VM4 endpoint
        if (! str_starts_with($url, rtrim(VM4_ENDPOINT, '/'))) {
            throw new \CodeIgniter\Exceptions\PageForbiddenException('Invalid file URL');
        }
                                                                     // Convert full URL to relative path for file.php
        $filePath = str_replace(rtrim(VM4_ENDPOINT, '/'), '', $url); // remove base URL
        $filePath = preg_replace('#^/uploads/#', '', $filePath);     // remove leading 'uploads/'
        $filePath = ltrim($filePath, '/');                           // remove any remaining slash
        $filePath = preg_replace('#//+#', '/', $filePath);           // normalize double slashes
        if (str_contains($filePath, '..')) {
            return $this->_create_response(FAIL, ['message' => 'Invalid file path.']);
        }

        // Build file.php URL with token
        $fileUrl = VM4_ENDPOINT . "file.php?file=" . urlencode($filePath) . "&token=" . VM4_SECRET_TOKEN;

        // Fetch file from file.php
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'CI4-Fetcher',
        ]);

        $fileContent = curl_exec($ch);
        $mimeType    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error       = curl_error($ch);

        curl_close($ch);

        if ($httpCode !== 200 || ! $fileContent) {
            log_message('error', "VM4 file fetch failed HTTP {$httpCode}: {$error}");
            return $this->_create_response(FAIL, ['message' => 'Unable to fetch file.']);
        }

        $safeName = preg_replace('/[\r\n"\\\\]/', '', basename($filePath));

        // Serve the file to browser
        return $this->response
            ->setHeader('Content-Type', $mimeType ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', 'inline; filename="' . $safeName . '"')
            ->setBody($fileContent);
    }

    //Helper function to fetch file content programmatically
    public static function fetchFileContent($url)
    {
        // Normalize double slashes AFTER protocol
        $url = preg_replace('#(?<!:)//+#', '/', $url);

        // Ensure the URL starts with VM4 endpoint
        if (! str_starts_with($url, rtrim(VM4_ENDPOINT, '/'))) {
            throw new \Exception('Invalid file URL');
        }

        // Convert URL to file.php path
        $filePath = str_replace(rtrim(VM4_ENDPOINT, '/'), '', $url);
        $filePath = preg_replace('#^/uploads/#', '', $filePath);
        $filePath = ltrim($filePath, '/');
        $filePath = preg_replace('#//+#', '/', $filePath);
        if (str_contains($filePath, '..')) {
            throw new \Exception('Invalid file path');
        }

        // Build file.php URL with token
        $fileUrl = VM4_ENDPOINT . "file.php?file=" . urlencode($filePath) . "&token=" . VM4_SECRET_TOKEN;

        // Curl fetch
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'CI4-Fetcher',
        ]);

        $content = curl_exec($ch);
        $mime    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_error($ch);

        curl_close($ch);

        if ($code !== 200 || ! $content) {
            throw new \Exception("Failed to fetch file from VM4. Error: {$error} (HTTP {$code})");
        }

        return [
            'content' => $content,
            'mime'    => $mime,
            'name'    => basename($filePath),
        ];
    }

}
