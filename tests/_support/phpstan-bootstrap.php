<?php

if (! function_exists('myt_env')) {
    function myt_env(string $key, $default = '')
    {
        return $default;
    }
}

if (! defined('FRONT_END_URL')) {
    define('FRONT_END_URL', 'https://example.com');
}

if (! function_exists('service')) {
    /**
     * @return \CodeIgniter\HTTP\Response
     */
    function service(string $name)
    {
        throw new RuntimeException('phpstan stub');
    }
}
