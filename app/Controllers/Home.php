<?php

namespace App\Controllers;

use App\Core\MYT_Controller;

class Home extends MYT_Controller
{

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * Index
     */
    public function index()
    {
        return view('welcome_message');
    }
}
