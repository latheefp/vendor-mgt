<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Table\AppSettingsTable;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use DateTimeZone;

/**
 * The portal's own settings: timezone and date/time display conventions.
 *
 * A single row (see AppSettingsTable::getSingleton) rather than a
 * collection — there is one portal, so there is nothing to index or
 * delete here, only view and edit.
 */
class AppSettingsController extends ApiController
{
    /**
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['view', 'edit']);
    }

    /**
     * GET /api/app-settings
     */
    public function view(): Response
    {
        $settings = $this->fetchTable('AppSettings')->getSingleton();

        return $this->respond($settings, [
            'timezones' => DateTimeZone::listIdentifiers(),
            'date_formats' => AppSettingsTable::DATE_FORMATS,
            'time_formats' => AppSettingsTable::TIME_FORMATS,
        ]);
    }

    /**
     * PUT /api/app-settings
     */
    public function edit(): Response
    {
        $table = $this->fetchTable('AppSettings');
        $settings = $table->getSingleton();

        $data = (array)$this->request->getData();
        $settings = $table->patchEntity($settings, $data, [
            'fields' => ['timezone', 'date_format', 'time_format', 'logo_base64', 'favicon_base64'],
        ]);

        if ($settings->hasErrors()) {
            return $this->fail('validation_error', 'Invalid settings data.', 422, $settings->getErrors());
        }

        if (!$table->save($settings)) {
            return $this->fail('save_failed', 'Could not update settings.', 400, $settings->getErrors());
        }

        return $this->respond($settings);
    }
}
