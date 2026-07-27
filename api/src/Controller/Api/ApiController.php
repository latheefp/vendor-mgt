<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Base controller for every JSON endpoint.
 *
 * Responses use a consistent envelope so the React client has exactly one
 * shape to handle:
 *
 *   success: { "data": ..., "meta": {...} }
 *   failure: { "error": { "code": "...", "message": "...", "fields": {...} } }
 *
 * `fields` is the important half. Cake's validation and application rules
 * are the single source of truth for what is valid, and returning them
 * per-field lets react-hook-form render them against the right input
 * without the frontend reimplementing any rule.
 */
class ApiController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Authentication.Authentication');
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // No templates on this side of the app; everything is JSON.
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * A placeholder from the route template.
     *
     * Routes here are declared with `{id}` rather than a greedy `*`, which
     * puts the value in the request's params and NOT in the action's
     * argument list. An action typed `foo(string $id)` therefore fails to
     * be invoked at all — Cake cannot fill the argument and rejects the
     * request before the method runs.
     *
     * So every action takes its placeholders as optional arguments and
     * resolves them through here: the argument wins when Cake did pass one
     * (a route with an explicit `pass`), and the request supplies it
     * otherwise.
     */
    protected function routeParam(string $name, ?string $passed = null): string
    {
        if ($passed !== null && $passed !== '') {
            return $passed;
        }

        return (string)$this->request->getParam($name, '');
    }

    /**
     * The signed-in user, or null on the endpoints still open.
     */
    protected function currentUserId(): ?int
    {
        $identity = $this->request->getAttribute('identity');

        return $identity !== null ? (int)$identity->getIdentifier() : null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function respond(mixed $data, array $meta = [], int $status = 200): Response
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function fail(
        string $code,
        string $message,
        int $status = 400,
        array $fields = [],
        mixed $detail = null,
    ): Response {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        if ($detail !== null) {
            $error['detail'] = $detail;
        }

        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
