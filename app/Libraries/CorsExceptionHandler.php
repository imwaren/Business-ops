<?php

namespace App\Libraries;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Exceptions as ExceptionsConfig;
use Throwable;

/**
 * Delegates to CI's handler after attaching CORS so 4xx/5xx are visible to the SPA.
 */
class CorsExceptionHandler implements ExceptionHandlerInterface
{
    private ExceptionsConfig $config;

    public function __construct(ExceptionsConfig $config)
    {
        $this->config = $config;
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode
    ): void {
        try {
            if (function_exists('cors_apply_to_response')) {
                cors_apply_to_response($request, $response);
            }
        } catch (Throwable $e) {
            // Keep rendering the original exception.
        }

        require_once ROOTPATH . 'app/Config/BootstrapGuard.php';
        if ($statusCode === 500 && $request instanceof IncomingRequest
            && strtoupper((string) $request->getMethod()) !== 'OPTIONS'
            && bootstrap_error_details_enabled(ROOTPATH)) {
            $payload = bootstrap_error_payload($exception, $exception->getMessage(), ROOTPATH);
            $response->setStatusCode(500)
                ->setContentType('application/json', 'UTF-8')
                ->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))
                ->send();

            if (ENVIRONMENT !== 'testing') {
                exit($exitCode);
            }
            return;
        }

        (new ExceptionHandler($this->config))->handle(
            $exception,
            $request,
            $response,
            $statusCode,
            $exitCode
        );
    }
}
