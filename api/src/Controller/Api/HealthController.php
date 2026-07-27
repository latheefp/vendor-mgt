<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Throwable;

// Cake 5 has no global helper functions; env() must be imported.
use function Cake\Core\env;

/**
 * Liveness and readiness.
 *
 * `/api/health` is deliberately shallow — it answers "is PHP running".
 * `/api/health/ready` actually touches MySQL and the cache, because a pod
 * that cannot reach the database should be pulled from the load balancer
 * rather than left accepting ticket closures it cannot persist.
 */
class HealthController extends ApiController
{
    public function index(): Response
    {
        return $this->respond([
            'status' => 'ok',
            'app' => 'Grand VendorService API',
            'time' => date('c'),
        ]);
    }

    public function ready(): Response
    {
        $checks = [];
        $healthy = true;

        try {
            $connection = ConnectionManager::get('default');
            $connection->execute('SELECT 1')->fetch();
            $checks['database'] = ['status' => 'ok'];
        } catch (Throwable $e) {
            $healthy = false;
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        try {
            // Round-trips a real key so a shared Redis with the wrong
            // prefix or database index shows up here, not in production.
            $probe = 'healthcheck_' . bin2hex(random_bytes(4));
            Cache::write($probe, 'ok', 'default');
            $value = Cache::read($probe, 'default');
            Cache::delete($probe, 'default');

            $checks['cache'] = $value === 'ok'
                ? ['status' => 'ok', 'engine' => env('CACHE_ENGINE', 'redis')]
                : ['status' => 'error', 'message' => 'Write succeeded but read did not return the value'];

            if ($value !== 'ok') {
                $healthy = false;
            }
        } catch (Throwable $e) {
            $healthy = false;
            $checks['cache'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        return $this->respond(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            [],
            $healthy ? 200 : 503,
        );
    }
}
