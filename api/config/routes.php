<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    /*
     * All API routes live under /api.
     *
     * The prefix is never stripped anywhere in the chain. In development
     * Vite proxies /api straight here; in production FrankenPHP serves
     * both the API and the built SPA from one origin. Declaring the scope
     * here means Cake matches the path exactly as it arrives and any URL
     * it generates keeps the prefix — no rewrite rules anywhere.
     */
    $routes->scope('/api', function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);

        // Liveness and readiness. `ready` touches MySQL and the cache so a
        // pod that cannot reach them is pulled from the load balancer.
        $builder->get('/health', ['controller' => 'Health', 'action' => 'index', 'prefix' => 'Api']);
        $builder->get('/health/ready', ['controller' => 'Health', 'action' => 'ready', 'prefix' => 'Api']);

        // ---- authentication -------------------------------------
        // Session cookie, not JWT. The SPA is same-origin, so the
        // cookie stays HttpOnly and no credential is ever exposed to
        // JavaScript.
        $builder->get('/auth/csrf', ['controller' => 'Auth', 'action' => 'csrf', 'prefix' => 'Api']);
        $builder->post('/auth/login', ['controller' => 'Auth', 'action' => 'login', 'prefix' => 'Api']);
        $builder->post('/auth/logout', ['controller' => 'Auth', 'action' => 'logout', 'prefix' => 'Api']);
        $builder->get('/auth/me', ['controller' => 'Auth', 'action' => 'me', 'prefix' => 'Api']);

        // ---- tickets --------------------------------------------
        $builder->get('/tickets/dashboard-stats', ['controller' => 'Tickets', 'action' => 'dashboardStats', 'prefix' => 'Api']);
        $builder->get('/tickets/options', ['controller' => 'Tickets', 'action' => 'options', 'prefix' => 'Api']);
        $builder->get('/tickets', ['controller' => 'Tickets', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/tickets', ['controller' => 'Tickets', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/tickets/{id}', ['controller' => 'Tickets', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/tickets/{id}', ['controller' => 'Tickets', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/assign', ['controller' => 'Tickets', 'action' => 'assign', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/checkin', ['controller' => 'Tickets', 'action' => 'checkin', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/checkout', ['controller' => 'Tickets', 'action' => 'checkout', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/otp/send', ['controller' => 'Tickets', 'action' => 'sendOtp', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/otp/verify', ['controller' => 'Tickets', 'action' => 'verifyOtp', 'prefix' => 'Api']);
        $builder->get('/tickets/{id}/attachments', ['controller' => 'Tickets', 'action' => 'attachments', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/attachments', ['controller' => 'Tickets', 'action' => 'addAttachment', 'prefix' => 'Api']);
        // Files stream through the app rather than being linked into
        // webroot, so who may read them stays a decision we can make.
        $builder->get('/attachments/{attachment_id}', ['controller' => 'Tickets', 'action' => 'serveAttachment', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/spares', ['controller' => 'Tickets', 'action' => 'addSpare', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/spares/{spare_id}/return', ['controller' => 'Tickets', 'action' => 'returnSpare', 'prefix' => 'Api']);
        // Withdrawing a line recorded in error. Only until the charges
        // freeze — after that the correction is an adjustment.
        $builder->delete('/tickets/{id}/spares/{spare_id}', ['controller' => 'Tickets', 'action' => 'removeSpare', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/contact', ['controller' => 'Tickets', 'action' => 'contact', 'prefix' => 'Api']);
        // Holds are what make an SLA window defensible, so starting and
        // releasing one are first-class actions rather than status edits.
        $builder->post('/tickets/{id}/hold', ['controller' => 'Tickets', 'action' => 'hold', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/release', ['controller' => 'Tickets', 'action' => 'release', 'prefix' => 'Api']);
        // Status as a plain edit. The action endpoints above still move the
        // status as a side effect; this is for the moves that have no action
        // behind them — cancelling, parking on parts, scheduling.
        $builder->get('/tickets/{id}/status', ['controller' => 'Tickets', 'action' => 'statusOptions', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/status', ['controller' => 'Tickets', 'action' => 'changeStatus', 'prefix' => 'Api']);
        // What the job would earn, and what evidence is still missing.
        $builder->get('/tickets/{id}/preview', ['controller' => 'Tickets', 'action' => 'preview', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/close', ['controller' => 'Tickets', 'action' => 'close', 'prefix' => 'Api']);
        // The frozen ledger, and the one sanctioned way to change it after
        // the fact. Editing a frozen line would rewrite an invoice already
        // sent, so a correction is always a new row.
        $builder->get('/tickets/{id}/charges', ['controller' => 'Tickets', 'action' => 'charges', 'prefix' => 'Api']);
        // Extra work agreed while the job is open (BOQ) and a correction to
        // a bill already sent are different events, so they are different
        // endpoints. Routing both at /adjustments once left the desk unable
        // to price extra work without freezing the ticket mid-job.
        $builder->post('/tickets/{id}/charges', ['controller' => 'Tickets', 'action' => 'addServiceLine', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/adjustments', ['controller' => 'Tickets', 'action' => 'addAdjustment', 'prefix' => 'Api']);
        $builder->delete('/tickets/{id}/charges/{charge_id}', ['controller' => 'Tickets', 'action' => 'removeCharge', 'prefix' => 'Api']);
        // Notes live on the same append-only trail as the status changes, so
        // the timeline reads as one story.
        $builder->get('/tickets/{id}/comments', ['controller' => 'Tickets', 'action' => 'comments', 'prefix' => 'Api']);
        $builder->post('/tickets/{id}/comments', ['controller' => 'Tickets', 'action' => 'addComment', 'prefix' => 'Api']);

        // ---- companies -------------------------------------------
        // Everything that makes one company differ from the next. The
        // second company we sign should be data entry, not a release.
        $builder->get('/companies', ['controller' => 'Companies', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/companies', ['controller' => 'Companies', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/companies/{id}', ['controller' => 'Companies', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/companies/{id}', ['controller' => 'Companies', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->delete('/companies/{id}', ['controller' => 'Companies', 'action' => 'delete', 'prefix' => 'Api']);

        // Operational dials, and the vocabulary the desk picks from.
        $builder->get('/companies/{id}/settings', ['controller' => 'Companies', 'action' => 'settings', 'prefix' => 'Api']);
        $builder->put('/companies/{id}/settings', ['controller' => 'Companies', 'action' => 'settings', 'prefix' => 'Api']);
        $builder->get('/companies/{id}/master-lists', ['controller' => 'Companies', 'action' => 'masterLists', 'prefix' => 'Api']);
        $builder->post('/companies/{id}/master-lists/{list}/fork', ['controller' => 'Companies', 'action' => 'forkMasterList', 'prefix' => 'Api']);

        // Rate cards: versioned, and immutable once published.
        $builder->get('/companies/{id}/rate-cards', ['controller' => 'Companies', 'action' => 'rateCards', 'prefix' => 'Api']);
        $builder->post('/companies/{id}/rate-cards', ['controller' => 'Companies', 'action' => 'createRateCard', 'prefix' => 'Api']);
        $builder->get('/companies/{id}/rate-cards/{card_id}', ['controller' => 'Companies', 'action' => 'rateCard', 'prefix' => 'Api']);
        $builder->post('/companies/{id}/rate-cards/{card_id}/items', ['controller' => 'Companies', 'action' => 'addRateCardItem', 'prefix' => 'Api']);
        $builder->delete('/companies/{id}/rate-cards/{card_id}/items/{item_id}', ['controller' => 'Companies', 'action' => 'deleteRateCardItem', 'prefix' => 'Api']);
        $builder->post('/companies/{id}/rate-cards/{card_id}/sla-rules', ['controller' => 'Companies', 'action' => 'addSlaRule', 'prefix' => 'Api']);
        $builder->delete('/companies/{id}/rate-cards/{card_id}/sla-rules/{rule_id}', ['controller' => 'Companies', 'action' => 'deleteSlaRule', 'prefix' => 'Api']);
        $builder->post('/companies/{id}/rate-cards/{card_id}/publish', ['controller' => 'Companies', 'action' => 'publishRateCard', 'prefix' => 'Api']);

        // ---- users management -----------------------------------
        $builder->get('/users', ['controller' => 'Users', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/users', ['controller' => 'Users', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/users/{id}', ['controller' => 'Users', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/users/{id}', ['controller' => 'Users', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->delete('/users/{id}', ['controller' => 'Users', 'action' => 'delete', 'prefix' => 'Api']);

        // ---- technicians ------------------------------------------
        $builder->get('/technicians', ['controller' => 'Technicians', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/technicians', ['controller' => 'Technicians', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/technicians/{id}', ['controller' => 'Technicians', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/technicians/{id}', ['controller' => 'Technicians', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->delete('/technicians/{id}', ['controller' => 'Technicians', 'action' => 'delete', 'prefix' => 'Api']);

        // ---- groups & permissions (roles) ------------------------
        $builder->get('/roles', ['controller' => 'Roles', 'action' => 'index', 'prefix' => 'Api']);
        $builder->get('/roles/permissions-catalog', ['controller' => 'Roles', 'action' => 'permissionsCatalog', 'prefix' => 'Api']);
        $builder->post('/roles', ['controller' => 'Roles', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/roles/{id}', ['controller' => 'Roles', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/roles/{id}', ['controller' => 'Roles', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->delete('/roles/{id}', ['controller' => 'Roles', 'action' => 'delete', 'prefix' => 'Api']);

        // ---- products & appliance categories --------------------
        $builder->get('/product-categories', ['controller' => 'Products', 'action' => 'categories', 'prefix' => 'Api']);
        $builder->post('/product-categories', ['controller' => 'Products', 'action' => 'addCategory', 'prefix' => 'Api']);
        $builder->put('/product-categories/{id}', ['controller' => 'Products', 'action' => 'editCategory', 'prefix' => 'Api']);
        $builder->get('/brands', ['controller' => 'Products', 'action' => 'brands', 'prefix' => 'Api']);
        $builder->post('/brands', ['controller' => 'Products', 'action' => 'addBrand', 'prefix' => 'Api']);
        $builder->put('/brands/{id}', ['controller' => 'Products', 'action' => 'editBrand', 'prefix' => 'Api']);
        $builder->get('/products', ['controller' => 'Products', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/products', ['controller' => 'Products', 'action' => 'add', 'prefix' => 'Api']);
        $builder->get('/products/{id}', ['controller' => 'Products', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/products/{id}', ['controller' => 'Products', 'action' => 'edit', 'prefix' => 'Api']);
        $builder->delete('/products/{id}', ['controller' => 'Products', 'action' => 'delete', 'prefix' => 'Api']);

        // ---- master lists & operations --------------------------
        $builder->get('/master-lists', ['controller' => 'MasterLists', 'action' => 'index', 'prefix' => 'Api']);
        $builder->post('/master-lists/{list}', ['controller' => 'MasterLists', 'action' => 'addItem', 'prefix' => 'Api']);
        $builder->put('/master-lists/{list}/{id}', ['controller' => 'MasterLists', 'action' => 'editItem', 'prefix' => 'Api']);
        $builder->delete('/master-lists/{list}/{id}', ['controller' => 'MasterLists', 'action' => 'toggleItem', 'prefix' => 'Api']);

        // ---- portal-wide configuration (timezone, date/time format) ----
        $builder->get('/app-settings', ['controller' => 'AppSettings', 'action' => 'view', 'prefix' => 'Api']);
        $builder->put('/app-settings', ['controller' => 'AppSettings', 'action' => 'edit', 'prefix' => 'Api']);

        // Clause 4: live exposure against the agreed credit limit.
        $builder->get('/companies/{id}/credit-exposure', ['controller' => 'Settlement', 'action' => 'creditExposure', 'prefix' => 'Api']);

        // The same question across every company, and one stage earlier:
        // work closed but never invoiced does not appear on an invoice
        // list at all, so it cannot be chased from one.
        $builder->get('/receivables', ['controller' => 'Settlement', 'action' => 'receivables', 'prefix' => 'Api']);

        // The same question on the technician side: what has been earned
        // but not yet claimed by a payout run.
        $builder->get('/technician-dues', ['controller' => 'Settlement', 'action' => 'technicianDues', 'prefix' => 'Api']);

        // Income, expenses and net margin for a period — what the service
        // centre actually kept.
        $builder->get('/reports/profit-loss', ['controller' => 'Settlement', 'action' => 'profitAndLoss', 'prefix' => 'Api']);

        // ---- the service centre's own cash position --------------
        // A different figure from the P&L above: this only moves when
        // cash genuinely does — an invoice payment landing, a technician
        // payout actually being paid, or a desk correction.
        $builder->get('/service-centers/savings', ['controller' => 'Savings', 'action' => 'balances', 'prefix' => 'Api']);
        $builder->get('/service-centers/{id}/savings', ['controller' => 'Savings', 'action' => 'balance', 'prefix' => 'Api']);
        $builder->post('/service-centers/{id}/savings/adjust', [
            'controller' => 'Savings', 'action' => 'adjustSavings', 'prefix' => 'Api',
        ]);

        // ---- a technician's own wallet ----------------------------
        // Self-scoped to whoever is signed in — see WalletController for
        // why that check lives in the controller rather than a shared
        // authorization layer.
        $builder->get('/wallet/me', ['controller' => 'Wallet', 'action' => 'me', 'prefix' => 'Api']);
        $builder->post('/wallet/me/withdraw', ['controller' => 'Wallet', 'action' => 'withdraw', 'prefix' => 'Api']);

        // ---- settlement -----------------------------------------
        // Both runs copy charge lines frozen at closure. Neither prices
        // anything, which is what keeps an invoice agreeing with the
        // tickets behind it after the rate card moves on.
        $builder->get('/invoices', ['controller' => 'Settlement', 'action' => 'invoices', 'prefix' => 'Api']);
        $builder->get('/invoices/preview', ['controller' => 'Settlement', 'action' => 'previewInvoice', 'prefix' => 'Api']);
        $builder->post('/invoices/generate', ['controller' => 'Settlement', 'action' => 'generateInvoice', 'prefix' => 'Api']);
        $builder->get('/invoices/{id}', ['controller' => 'Settlement', 'action' => 'invoice', 'prefix' => 'Api']);
        $builder->post('/invoices/{id}/send', ['controller' => 'Settlement', 'action' => 'sendInvoice', 'prefix' => 'Api']);
        $builder->post('/invoices/{id}/payment', ['controller' => 'Settlement', 'action' => 'recordPayment', 'prefix' => 'Api']);
        // Restating a line on a draft. PATCH rather than PUT: the line is
        // not being replaced, one agreed amount on it is.
        $builder->patch('/invoices/{id}/lines/{line_id}', [
            'controller' => 'Settlement', 'action' => 'overrideInvoiceLine', 'prefix' => 'Api',
        ]);
        $builder->delete('/invoices/{id}/lines/{line_id}', [
            'controller' => 'Settlement', 'action' => 'resetInvoiceLine', 'prefix' => 'Api',
        ]);

        $builder->get('/payouts', ['controller' => 'Settlement', 'action' => 'payouts', 'prefix' => 'Api']);
        $builder->post('/payouts/generate', ['controller' => 'Settlement', 'action' => 'generatePayout', 'prefix' => 'Api']);
        $builder->get('/payouts/{id}', ['controller' => 'Settlement', 'action' => 'payout', 'prefix' => 'Api']);
        $builder->post('/payouts/{id}/approve', ['controller' => 'Settlement', 'action' => 'approvePayout', 'prefix' => 'Api']);
        $builder->post('/payouts/{id}/pay', ['controller' => 'Settlement', 'action' => 'payPayout', 'prefix' => 'Api']);
        $builder->patch('/payouts/{id}/lines/{line_id}', [
            'controller' => 'Settlement', 'action' => 'overridePayoutLine', 'prefix' => 'Api',
        ]);
        $builder->delete('/payouts/{id}/lines/{line_id}', [
            'controller' => 'Settlement', 'action' => 'resetPayoutLine', 'prefix' => 'Api',
        ]);

        // ---- spare stock ----------------------------------------
        // Fitting a part to a job is a ticket action and lives up there
        // with the rest of the work. Everything below is what happens to a
        // part while nobody is looking at it.
        $builder->get('/spares/catalogue', ['controller' => 'Spares', 'action' => 'catalogue', 'prefix' => 'Api']);
        $builder->post('/spares/catalogue', ['controller' => 'Spares', 'action' => 'addPart', 'prefix' => 'Api']);
        $builder->get('/spares/stock', ['controller' => 'Spares', 'action' => 'stock', 'prefix' => 'Api']);
        $builder->get('/spares/holdings', ['controller' => 'Spares', 'action' => 'holdings', 'prefix' => 'Api']);
        $builder->post('/spares/receive', ['controller' => 'Spares', 'action' => 'receive', 'prefix' => 'Api']);
        $builder->post('/spares/issue', ['controller' => 'Spares', 'action' => 'issue', 'prefix' => 'Api']);
        $builder->post('/spares/return-good', ['controller' => 'Spares', 'action' => 'returnGood', 'prefix' => 'Api']);
        $builder->post('/spares/write-off', ['controller' => 'Spares', 'action' => 'writeOff', 'prefix' => 'Api']);
        // A physical count. POST rather than PUT: what is recorded is the
        // difference it revealed, which is a new fact about a date, not a
        // replacement for the balance.
        $builder->post('/spares/count', ['controller' => 'Spares', 'action' => 'count', 'prefix' => 'Api']);

        // ---- spare ageing ---------------------------------------
        // Clauses 9 and 10. Both are money that leaks silently if nobody
        // is looking at a list, so both get one.
        $builder->get('/spares/defective-returns', ['controller' => 'Settlement', 'action' => 'defectiveReturns', 'prefix' => 'Api']);
        // Clause 10 against stock still in our possession — the case the
        // clause is actually about. The report below it ages parts already
        // fitted to a job, which is a different and much smaller question.
        $builder->get('/spares/ageing', ['controller' => 'Spares', 'action' => 'ageing', 'prefix' => 'Api']);
        $builder->get('/spares/fitted-ageing', ['controller' => 'Settlement', 'action' => 'spareAgeing', 'prefix' => 'Api']);
        // Clause 9 settles per consignment, so both ends of it are batches.
        $builder->post('/spares/defective-returns', ['controller' => 'Spares', 'action' => 'returnDefectives', 'prefix' => 'Api']);
        $builder->post('/spares/defective-credits', ['controller' => 'Spares', 'action' => 'recordDefectiveCredit', 'prefix' => 'Api']);

        $builder->get('/spares/{id}/movements', ['controller' => 'Spares', 'action' => 'movements', 'prefix' => 'Api']);

        // ---- pricing --------------------------------------------
        // Same engine that produces the frozen charge lines at closure, so
        // a quote and an invoice can never disagree.
        $builder->post('/rates/preview', ['controller' => 'Rates', 'action' => 'preview', 'prefix' => 'Api']);
    });

    $routes->scope('/', function (RouteBuilder $builder): void {
        /*
         * Here, we are connecting '/' (base path) to a controller called 'Pages',
         * its action called 'display', and we pass a param to select the view file
         * to use (in this case, templates/Pages/home.php)...
         */
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

        /*
         * ...and connect the rest of 'Pages' controller's URLs.
         */
        $builder->connect('/pages/*', 'Pages::display');

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * It is NOT recommended to use fallback routes after your initial prototyping phase!
         * See https://book.cakephp.org/5/en/development/routing.html#fallbacks-method for more information
         */
        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
