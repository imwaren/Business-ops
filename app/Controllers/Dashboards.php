<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Endorsement;
use App\Models\Endorsement_detail;
use App\Models\Endorsement_service;
use App\Models\Endorsement_attachment;
use App\Models\Patient_master;
use App\Models\User;

class Dashboards extends MYT_Controller
{
    protected $request;
    protected $endorsement;
    protected $endorsement_detail;
    protected $endorsement_service;
    protected $endorsement_attachment;
    protected $patient_master;
    protected $user;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->endorsement = new Endorsement();
        $this->endorsement_detail = new Endorsement_detail();
        $this->endorsement_service = new Endorsement_service();
        $this->endorsement_attachment = new Endorsement_attachment();
        $this->patient_master = new Patient_master();
        $this->user = new User();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Proxy for fetching payment images to avoid CORS issues
     */
    public function get_file()
    {
        // Get the image URL from query parameters
        $imageUrl = $this->request->getGet('url');
        
        if (empty($imageUrl)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Image URL is required'
            ]);
        }

        // Validate the URL belongs to your domain (security measure)
        $allowedDomain = 'accounting-back.myt-emr.com';
        if (parse_url($imageUrl, PHP_URL_HOST) !== $allowedDomain) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Unauthorized image source'
            ]);
        }

        // Initialize HTTP client
        $client = \Config\Services::curlrequest();
        
        try {
            // Fetch the image
            $response = $client->get($imageUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36'
                ]
            ]);

            // Get the image content
            $imageContent = $response->getBody();
            
            // Clean the Content-Type header to remove any newlines or unwanted characters
            $contentType = $response->getHeader('Content-Type');
            $contentType = trim(preg_replace('/\s+/', ' ', $contentType)); // Remove newlines and extra spaces
            
            // If we can't determine content type, default to JPEG
            if (empty($contentType)) {
                $contentType = 'image/jpeg';
            }

            // Return the image directly
            return $this->response
                ->setStatusCode(200)
                ->setContentType($contentType)
                ->setBody($imageContent);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to fetch image: ' . $e->getMessage()
            ]);
        }
    }
}
