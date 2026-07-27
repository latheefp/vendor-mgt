<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App;

use App\Middleware\HostHeaderMiddleware;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Http\BaseApplication;
use Psr\Http\Message\ServerRequestInterface;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 *
 * @extends \Cake\Http\BaseApplication<\App\Application>
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        // Call parent to load bootstrap from files.
        parent::bootstrap();

        $this->addPlugin('Authentication');

        // By default, does not allow fallback classes.
        FactoryLocator::add(
            'Table',
            (new TableLocator())->allowFallbackClass(false),
        );
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            // In production, ensures App.fullBaseUrl is configured and validates
            // the incoming Host header against it.
            ->add(new HostHeaderMiddleware())

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware.
            // If you have a large number of routes connected, turning on routes
            // caching in production could improve performance.
            // See https://github.com/CakeDC/cakephp-cached-routing
            ->add(new RoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            // available as array through $request->getData()
            // https://book.cakephp.org/5/en/controllers/middleware.html#body-parser-middleware
            ->add(new BodyParserMiddleware())

            /*
             * CSRF protection.
             *
             * `httponly` is deliberately FALSE here, which is the one place
             * this application relaxes a cookie flag — and it is safe only
             * because of how the rest of it is set up.
             *
             * The React SPA is served from the same origin as the API, so it
             * authenticates with Cake's session cookie rather than a token in
             * localStorage. That means it needs to read the CSRF token and
             * echo it back in the X-CSRF-Token header, which requires the
             * token cookie to be readable by JavaScript.
             *
             * What protects us is that the CSRF cookie is not a credential:
             * the SESSION cookie stays HttpOnly and SameSite=Lax, so an
             * attacker on another origin can neither read the token nor ride
             * the session. Reading your own CSRF token from your own origin
             * is the intended double-submit pattern.
             */
            ->add(new CsrfProtectionMiddleware([
                'cookieName' => 'gvsCsrfToken',
                'httponly' => false,
                'secure' => filter_var(env('SESSION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN),
                'samesite' => 'Lax',
            ]))

            /*
             * Identity resolution. Must come after routing (it needs the
             * matched route) and after CSRF, so a forged cross-site POST is
             * rejected before it ever reaches an authenticated action.
             */
            ->add(new AuthenticationMiddleware($this));

        return $middlewareQueue;
    }

    /**
     * Session-cookie authentication for a same-origin SPA.
     *
     * No JWT anywhere. The React client is served from the same origin as
     * this API, so it authenticates with Cake's session cookie, which stays
     * HttpOnly and SameSite=Lax and is therefore unreadable by any script —
     * including one injected via XSS. A token in localStorage would not be.
     *
     * `unauthenticatedRedirect` is deliberately unset: this is an API, so an
     * anonymous request gets a 401 JSON body rather than a 302 to a login
     * page the client cannot render.
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService([
            'unauthenticatedRedirect' => null,
            'queryParam' => null,
        ]);

        $fields = [
            'username' => 'email',
            'password' => 'password',
        ];

        // Session first: once identified, later requests cost no password
        // hashing at all.
        $service->loadAuthenticator('Authentication.Session', [
            'sessionKey' => 'Auth',
        ]);

        // Then the login form itself.
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => $fields,
            'loginUrl' => '/api/auth/login',
            'identifier' => [
                'className' => 'Authentication.Password',
                'fields' => $fields,
                'resolver' => [
                    'className' => 'Authentication.Orm',
                    'userModel' => 'Users',
                    'finder' => 'active',
                ],
            ],
        ]);

        return $service;
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        // Allow your Tables to be dependency injected
        //$container->delegate(new \Cake\ORM\Locator\TableContainer());
    }

    /**
     * Register custom event listeners here
     *
     * @param \Cake\Event\EventManagerInterface $eventManager
     * @return \Cake\Event\EventManagerInterface
     * @link https://book.cakephp.org/5/en/core-libraries/events.html#registering-listeners
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        // $eventManager->on(new SomeCustomListenerClass());

        return $eventManager;
    }
}
