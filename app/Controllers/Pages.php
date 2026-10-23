<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Libraries\PageInventory;
use App\Models\Page;

class Pages extends MYT_Controller
{

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper('filesystem');

        //Load models, library & configs
        $this->page = new Page();
    }

    /**
     * Insert Pages
     */
    public function insert_pages()
    {
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $data = PageInventory::pages();

        $response = ($data && $this->page->insert_batch($data)) ?
            $this->_create_response(SUCCESS, ['message' => 'Controllers and methods inserted.']) :
            $this->_create_response(FAIL, ['Something went wrong.']);

        return $response;
    }
}
