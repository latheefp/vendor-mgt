<?php
declare(strict_types=1);

namespace App\Error;

use Cake\Core\Configure;
use Cake\Core\Exception\HttpErrorCodeInterface;
use Cake\Error\Debugger;
use Cake\Error\ExceptionRendererInterface;
use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use Cake\Http\ResponseEmitter;
use Cake\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Renders every uncaught exception in the API's error envelope.
 *
 * The client has exactly one failure shape to handle:
 *
 *   { "error": { "code": "...", "message": "...", "detail": {...} } }
 *
 * so an unhandled exception has to arrive in that shape too. The framework
 * default renders an HTML page instead, which the client can only report as
 * "the server returned an unexpected response" — true, but it tells whoever
 * is reading the screen nothing about what actually broke.
 *
 * `detail` carries the exception class, file and trace, and only in debug
 * mode. In production the message for a non-HTTP exception is deliberately
 * generic: SQL errors quote the failing query, which names our tables.
 */
class ApiExceptionRenderer implements ExceptionRendererInterface
{
    /**
     * ExceptionTrap constructs renderers with the request and the trap's own
     * config as well. Neither is needed here — the response shape does not
     * vary by route — but the signature has to accept them.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly Throwable $error,
        ?ServerRequest $request = null,
        array $config = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(): ResponseInterface|string
    {
        $debug = (bool)Configure::read('debug');

        // HttpExceptions are deliberate — 401, 403, 404, 405. Their status
        // and message are part of the API contract and are safe to pass on
        // whether or not we are in debug mode. HttpErrorCodeInterface covers
        // the framework's own routing failures (a missing controller or
        // action is a 404, not a crash), which carry a status in getCode()
        // without extending HttpException.
        $isHttp = $this->error instanceof HttpException
            || $this->error instanceof HttpErrorCodeInterface;
        $status = $isHttp ? (int)$this->error->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $error = [
            'code' => $isHttp ? 'http_error' : 'server_error',
            'message' => $isHttp || $debug
                ? $this->error->getMessage()
                : 'The server could not complete that request.',
        ];

        if ($debug) {
            $error['detail'] = [
                'exception' => $this->error::class,
                'file' => Debugger::trimPath($this->error->getFile()),
                'line' => $this->error->getLine(),
                'trace' => explode("\n", $this->error->getTraceAsString()),
            ];
        }

        return (new Response())
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(
                (string)json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
    }

    /**
     * @inheritDoc
     */
    public function write(ResponseInterface|string $output): void
    {
        if (is_string($output)) {
            echo $output;

            return;
        }

        (new ResponseEmitter())->emit($output);
    }
}
