<?php

namespace App\Libraries;

use ReflectionClass;
use ReflectionMethod;

/**
 * Lists public controller methods for page/permission seeds.
 */
class PageInventory
{
    public const EXCLUDED_CONTROLLERS = [
        'BaseController',
        'Login',
        'Cron',
    ];

    public const EXCLUDED_METHODS = [
        'initController',
        'forceHTTPS',
        'cachePage',
        'loadHelpers',
        'validate',
        'validateData',
        'respond',
        'fail',
        'respondCreated',
        'respondDeleted',
        'respondUpdated',
        'respondNoContent',
        'failUnauthorized',
        'failForbidden',
        'failNotFound',
        'failValidationError',
        'failValidationErrors',
        'failResourceExists',
        'failResourceGone',
        'failTooManyRequests',
        'failServerError',
        'format',
        'setResponseFormat',
    ];

    /**
     * @return list<array{controller: string, method: string}>
     */
    public static function pages(): array
    {
        $pages = [];
        $files = glob(APPPATH . 'Controllers/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $controllerName = basename($file, '.php');
            if (in_array($controllerName, self::EXCLUDED_CONTROLLERS, true)) {
                continue;
            }

            $class = 'App\\Controllers\\' . $controllerName;
            if (! class_exists($class)) {
                require_once $file;
            }
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();
                if ($name === '' || $name[0] === '_') {
                    continue;
                }
                if (in_array($name, self::EXCLUDED_METHODS, true)) {
                    continue;
                }
                $pages[] = [
                    'controller' => $controllerName,
                    'method'     => $name,
                ];
            }
        }

        return $pages;
    }

    /**
     * Role ids from docs/seeds/role.csv (skips missing id 33).
     *
     * @return list<int>
     */
    public static function roleIds(): array
    {
        $path = ROOTPATH . 'docs/seeds/role.csv';
        if (! is_file($path)) {
            $path = '/Users/myt_waren/Downloads/role.csv';
        }

        $ids = [];
        if (is_file($path) && ($handle = fopen($path, 'rb')) !== false) {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $id = (int) ($row[0] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            fclose($handle);
        }

        if ($ids === []) {
            $ids = array_merge(range(1, 32), range(34, 53));
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
