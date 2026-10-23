<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Permission;

class Permissions extends MYT_Controller
{
    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);

        //Load models, library & configs
        $this->permission = new Permission();
    }

    /**
     * Insert permissions
     */
    public function insert_permissions()
    {
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }
        $roles = $this->request->getVar('roles');
        $pages = $this->request->getVar('pages');

        $permission_data = [];
        foreach ($roles as $index => $role_id) {

            foreach ($pages[$index] as $page_id) {

                $permission_data[] = [
                    'role_id' => $role_id,
                    'page_id' => $page_id,
                    'is_allowed' => 0
                ];

            }

        }

        $response = ($permission_data AND $this->permission->insert_batch($permission_data)) ? 
            $this->_create_response(SUCCESS, ['message' => 'Permissions inserted for chosen pages.']) :
            $this->_create_response(FAIL, ['Something went wrong.']) ;

        return $response;
    }
}