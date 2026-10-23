<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Validation\CreditCardRules;
use CodeIgniter\Validation\FileRules;
use CodeIgniter\Validation\FormatRules;
use CodeIgniter\Validation\Rules;
use Config\MYTCustomRules;

class Validation extends BaseConfig
{
    // --------------------------------------------------------------------
    // Setup
    // --------------------------------------------------------------------

    /**
     * Specifies the views that are used to display the
     * errors.
     *
     * @var array<string, string>
     */
    public array $templates = [
        'list'   => 'CodeIgniter\Validation\Views\list',
        'single' => 'CodeIgniter\Validation\Views\single',
    ];

    /**
     * Validation rules for creating users
     */
    public array $users_create = [
        'username' => [
            'label' => 'username',
            'rules' => 'required|trim'
        ],
        'full_name' => [
            'label' => 'full_name',
            'rules' => 'required|trim'
        ],
        'password' => [
            'label' => 'password',
            'rules' => 'required|trim|min_length[8]|max_length[255]'
        ],
        'role_id' => [
            'label' => 'role_id',
            'rules' => 'required|trim'
        ],
        'role_name' => [
            'label' => 'role_name',
            'rules' => 'required|trim'
        ],
    ];

    /**
     * Validation rules for updating users
     */
    public array $users_update = [];
    public array $login = [];
    public array $users_change_password = [];

    /**
     * Stores the classes that contain the rules
     *
     * @var string[]
     */
    public array $ruleSets = [
        Rules::class,
        FormatRules::class,
        FileRules::class,
        CreditCardRules::class,
        MYTCustomRules::class,
    ];

    // --------------------------------------------------------------------
    // Constructor
    // --------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct(); // Make sure to call the parent constructor

        $this->users_update = [
            'user_id' => [
                'label' => 'user_id',
                'rules' => 'required|trim',
            ],
            'username' => [
                'label' => 'username',
                'rules' => 'trim',
            ],
            'full_name' => [
                'label' => 'full_name',
                'rules' => 'trim',
            ],
            'password' => [
                'label' => 'password',
                'rules' => 'trim',
            ],
            'role_id' => [
                'label' => 'role_id',
                'rules' => 'trim',
            ],
            'role_name' => [
                'label' => 'role_name',
                'rules' => 'trim',
            ],
        ] + $this->users_create;
        $this->login = [
            'email' => [
                'label' => 'email',
                'rules' => 'required|trim|valid_email|max_length[255]',
            ],
            'password' => [
                'label' => 'password',
                'rules' => 'required|trim|max_length[255]',
            ],
        ];
        $this->users_change_password = [
            'user_id' => [
                'label' => 'user_id',
                'rules' => 'required|integer',
            ],
            'password' => [
                'label' => 'password',
                'rules' => 'required|trim|min_length[8]|max_length[255]',
            ],
        ];
    }
}

