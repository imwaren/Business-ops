<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class SecurityHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper((string) $request->getMethod()) === 'OPTIONS') {
            $response = service('response')->setStatusCode(204);

            return $this->after($request, $response);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        try {
            $response->setHeader('X-Frame-Options', 'DENY');
            $response->setHeader('X-Content-Type-Options', 'nosniff');
            $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
            $response->setHeader(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
            );

            $isSecure = $request instanceof IncomingRequest && $request->isSecure();
            $forceTls = false;
            if (function_exists('myt_env')) {
                $forceTls = filter_var(myt_env('app.forceGlobalSecureRequests', false), FILTER_VALIDATE_BOOLEAN);
            }
            if ($isSecure || $forceTls) {
                $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }

            if (function_exists('cors_apply_to_response')) {
                cors_apply_to_response($request, $response);
            }
        } catch (Throwable $e) {
            // Headers/CORS must never take down OPTIONS or the request.
        }

        return $response;
    }
}
