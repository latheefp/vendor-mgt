<?php
declare(strict_types=1);

namespace App\Error;

use Cake\Error\ErrorRendererInterface;
use Cake\Error\PhpError;

/**
 * Renders PHP errors to nothing at all.
 *
 * Cake's default web renderer echoes warnings and notices straight into the
 * response as they happen. In an app that serves only JSON that is not a
 * debugging aid, it is corruption — and worse than it looks: the echo starts
 * the response body, so PHP flushes an implicit `200 OK`. A request that
 * later dies on a fatal exception then reaches the browser as a 200 whose
 * body is an HTML error page, and the client — correctly — reports that the
 * server sent something it cannot parse. The actual 500 is lost.
 *
 * Errors are still trapped and still logged; ErrorTrap's logger runs
 * independently of its renderer. The only thing dropped is the echo.
 */
class SilentErrorRenderer implements ErrorRendererInterface
{
    /**
     * @inheritDoc
     */
    public function render(PhpError $error, bool $debug): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function write(string $out): void
    {
    }
}
