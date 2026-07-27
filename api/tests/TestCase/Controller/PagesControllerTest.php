<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\Constraint\Response\StatusCode;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * PagesControllerTest class
 */
class PagesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * testDisplay method
     *
     * @return void
     */
    public function testDisplay()
    {
        Configure::write('debug', true);
        $this->get('/pages/home');
        $this->assertResponseOk();
        $this->assertResponseContains('CakePHP');
        $this->assertResponseContains('<html>');
    }

    /**
     * Uncaught exceptions come back in the API error envelope, not as an
     * HTML page — see App\Error\ApiExceptionRenderer. In production the
     * message is generic, because the framework's own messages name our
     * files.
     *
     * @return void
     */
    public function testMissingTemplate()
    {
        Configure::write('debug', false);
        $this->get('/pages/not_existing');

        $this->assertResponseError();
        $this->assertContentType('application/json');
        $this->assertResponseNotContains('<html>');
        $this->assertResponseContains('"error"');
    }

    /**
     * In debug the same envelope carries the exception class and trace, so
     * the failing request is still diagnosable from the client.
     *
     * @return void
     */
    public function testMissingTemplateInDebug()
    {
        Configure::write('debug', true);
        $this->get('/pages/not_existing');

        $this->assertResponseFailure();
        $this->assertContentType('application/json');
        $this->assertResponseContains('MissingTemplateException');
        $this->assertResponseContains('not_existing.php');
    }

    /**
     * Test directory traversal protection
     *
     * @return void
     */
    public function testDirectoryTraversalProtection()
    {
        $this->get('/pages/../Layout/ajax');
        $this->assertResponseCode(403);
        $this->assertResponseContains('Forbidden');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedError()
    {
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertResponseCode(403);
        $this->assertResponseContains('CSRF');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedOk()
    {
        // The application renames the CSRF cookie in Application::middleware;
        // the default name this helper assumes has never matched it, so the
        // token was being sent under a name the middleware ignores.
        $this->enableCsrfToken('gvsCsrfToken');
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertThat(403, $this->logicalNot(new StatusCode($this->_response)));
        $this->assertResponseNotContains('CSRF');
    }
}
