<?php

use function Cake\Core\env;

/*
 * Environment-driven local configuration.
 *
 * Every value comes from the environment so the same image runs in
 * docker compose and in k8s with nothing but a ConfigMap/Secret swap.
 * Do not hardcode credentials here.
 */

if (!function_exists('gvsRedisCache')) {
    /**
     * Build one cache config on the SHARED Redis instance.
     *
     * Isolation is deliberately belt-and-braces: REDIS_PREFIX namespaces
     * every key, REDIS_DATABASE puts us on our own numbered database, and
     * $subPrefix separates our own configs from each other so that
     * clearing model metadata cannot evict live sessions.
     *
     * Falls back to file-backed caching when CACHE_ENGINE is not redis, so
     * the app still boots if Redis is unreachable.
     *
     * @return array<string, mixed>
     */
    function gvsRedisCache(string $subPrefix, string $duration): array
    {
        $prefix = env('REDIS_PREFIX', 'gvs:') . $subPrefix;

        if (env('CACHE_ENGINE', 'redis') !== 'redis') {
            return [
                'className' => 'File',
                'path' => CACHE,
                'prefix' => str_replace(':', '_', $prefix),
                'duration' => $duration,
            ];
        }

        return [
            'className' => 'Redis',
            'host' => env('REDIS_HOST', 'redis'),
            'port' => (int)env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD') ?: false,
            'database' => (int)env('REDIS_DATABASE', '0'),
            'prefix' => $prefix,
            'duration' => $duration,
            // Never let a Redis blip take the whole request down; a cache
            // miss is recoverable, a fatal error mid-closure is not.
            'fallback' => false,
        ];
    }
}

return [
    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    'Security' => [
        'salt' => env('SECURITY_SALT', '__SALT__'),
    ],

    'App' => [
        'namespace' => 'App',
        'encoding' => env('APP_ENCODING', 'UTF-8'),
        'defaultLocale' => env('APP_DEFAULT_LOCALE', 'en_IN'),
        'defaultTimezone' => env('APP_DEFAULT_TIMEZONE', 'Asia/Kolkata'),
        'fullBaseUrl' => env('APP_FULL_BASE_URL', 'http://localhost'),
    ],

    'Datasources' => [
        'default' => [
            'className' => \Cake\Database\Connection::class,
            'driver' => \Cake\Database\Driver\Mysql::class,
            'persistent' => false,
            'host' => env('DB_HOST', 'mysql'),
            'port' => env('DB_PORT', '3306'),
            'username' => env('DB_USERNAME', 'vendorservice'),
            'password' => env('DB_PASSWORD', 'secret'),
            'database' => env('DB_NAME', 'vendorservice'),
            'encoding' => 'utf8mb4',
            'timezone' => env('DB_TIMEZONE', '+05:30'),
            'flags' => [],
            'cacheMetadata' => true,
            'log' => filter_var(env('DB_LOG_QUERIES', false), FILTER_VALIDATE_BOOLEAN),

            /*
             * Identifier quoting stays off so the SQL in the query log is
             * readable during reconciliation work.
             */
            'quoteIdentifiers' => false,
            'url' => env('DATABASE_URL', null),
        ],

        /*
         * Tests run against real MySQL. The money engine and the SLA clock
         * both depend on exact integer and DATETIME semantics, which SQLite
         * does not reproduce faithfully.
         */
        'test' => [
            'className' => \Cake\Database\Connection::class,
            'driver' => \Cake\Database\Driver\Mysql::class,
            'persistent' => false,
            'host' => env('DB_HOST', 'mysql'),
            'port' => env('DB_PORT', '3306'),
            'username' => env('DB_USERNAME', 'vendorservice'),
            'password' => env('DB_PASSWORD', 'secret'),
            'database' => env('TEST_DB_NAME', 'vendorservice_test'),
            'encoding' => 'utf8mb4',
            'timezone' => env('DB_TIMEZONE', '+05:30'),
            'cacheMetadata' => true,
            'quoteIdentifiers' => false,
            'log' => false,
            'url' => env('DATABASE_TEST_URL', null),
        ],
    ],

    /*
     * Cache and sessions on Redis.
     *
     * The Redis instance is SHARED with other applications, so every key
     * carries REDIS_PREFIX and we sit on our own REDIS_DATABASE index.
     * Each config gets its own sub-prefix on top of that, so flushing the
     * model metadata cache cannot take active sessions with it.
     *
     * Sessions belong here rather than on disk because the API runs more
     * than one pod: a file-backed session would log a technician out
     * whenever the load balancer moved them, which in the field reads as
     * the app losing their job halfway through.
     */
    'Cache' => [
        'default' => gvsRedisCache('default_', '+1 hours'),

        // Cake internals. Long durations are safe — the framework clears
        // these itself when schema or translations change.
        '_cake_translations_' => gvsRedisCache('translations_', '+1 years'),
        '_cake_model_' => gvsRedisCache('model_', '+1 years'),

        // Session store. Its own sub-prefix so that clearing any other
        // cache cannot sign people out.
        'session' => gvsRedisCache('session_', '+12 hours'),

        // Resolved rate cards and agreement terms. Deliberately short:
        // pricing must never be served from a stale card.
        'rates' => gvsRedisCache('rates_', '+10 minutes'),
    ],

    'Session' => [
        'defaults' => 'cake',
        'handler' => filter_var(env('SESSION_REDIS', true), FILTER_VALIDATE_BOOLEAN)
            && env('CACHE_ENGINE', 'redis') === 'redis'
                ? ['engine' => 'Cake\Http\Session\CacheSession', 'config' => 'session']
                : null,
        'ini' => [
            'session.cookie_secure' => filter_var(env('SESSION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN),
            'session.cookie_httponly' => true,
            // Same-origin deployment (Traefik routes /api and / to the same
            // host), so Lax is both sufficient and safer than None.
            'session.cookie_samesite' => 'Lax',
            'session.name' => env('SESSION_COOKIE_NAME', 'GVSSESSID'),
        ],
        'timeout' => (int)env('SESSION_TIMEOUT_MINUTES', '720'),
    ],

    'EmailTransport' => [
        'default' => [
            // No local mail sink. With EMAIL_HOST unset, mail is rendered and
            // logged instead of sent, so a missing SMTP server never turns into
            // a 30s connect timeout in the middle of a request.
            'className' => env('EMAIL_HOST')
                ? \Cake\Mailer\Transport\SmtpTransport::class
                : \Cake\Mailer\Transport\DebugTransport::class,
            'host' => env('EMAIL_HOST') ?: null,
            'port' => (int)env('EMAIL_PORT', '587'),
            'timeout' => 30,
            'username' => env('EMAIL_USERNAME') ?: null,
            'password' => env('EMAIL_PASSWORD') ?: null,
            'client' => null,
            'tls' => filter_var(env('EMAIL_TLS', false), FILTER_VALIDATE_BOOLEAN),
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],

    'Email' => [
        'default' => [
            'transport' => 'default',
            'from' => env('EMAIL_FROM', 'service@grandvendorservice.local'),
            'charset' => 'utf-8',
            'headerCharset' => 'utf-8',
        ],
    ],

    /*
     * Evidence photo storage. Closure photos are dispute evidence, so in
     * production these belong in versioned object storage (Cloudflare R2),
     * never on a container volume. A blank endpoint falls back to local
     * disk, which is acceptable in development only.
     */
    'Storage' => [
        'endpoint' => env('S3_ENDPOINT') ?: null,
        'bucket' => env('S3_BUCKET', 'gvs-evidence'),
        'key' => env('S3_KEY') ?: null,
        'secret' => env('S3_SECRET') ?: null,
        'region' => env('S3_REGION', 'auto'),
    ],
];
