<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Master lists API controller for Districts, Symptoms, Resolutions, Hold Reasons, Job Types, Service Centers.
 */
class MasterListsController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'addItem', 'editItem', 'toggleItem']);
    }

    /**
     * GET /api/master-lists
     */
    public function index(): Response
    {
        $districts = $this->fetchTable('Districts')->find()->orderBy(['name' => 'ASC'])->all();
        $symptoms = $this->fetchTable('Symptoms')->find()->orderBy(['name' => 'ASC'])->all();
        $resolutions = $this->fetchTable('Resolutions')->find()->orderBy(['name' => 'ASC'])->all();
        $holdReasons = $this->fetchTable('HoldReasons')->find()->orderBy(['name' => 'ASC'])->all();
        $jobTypes = $this->fetchTable('JobTypes')->find()->orderBy(['sort_order' => 'ASC', 'name' => 'ASC'])->all();
        $serviceCenters = $this->fetchTable('ServiceCenters')->find()->orderBy(['name' => 'ASC'])->all();

        return $this->respond([
            'districts' => $districts,
            'symptoms' => $symptoms,
            'resolutions' => $resolutions,
            'hold_reasons' => $holdReasons,
            'job_types' => $jobTypes,
            'service_centers' => $serviceCenters,
        ]);
    }

    /**
     * POST /api/master-lists/{list}
     */
    public function addItem(string $list): Response
    {
        $tableMap = [
            'districts' => 'Districts',
            'symptoms' => 'Symptoms',
            'resolutions' => 'Resolutions',
            'hold_reasons' => 'HoldReasons',
            'job_types' => 'JobTypes',
            'service_centers' => 'ServiceCenters',
        ];

        if (!isset($tableMap[$list])) {
            return $this->fail('not_found', 'Invalid master list specified.', 404);
        }

        $table = $this->fetchTable($tableMap[$list]);
        $data = (array)$this->request->getData();

        $entity = $table->newEntity($data);
        if ($entity->hasErrors()) {
            return $this->fail('validation_error', 'Invalid list item data.', 422, $entity->getErrors());
        }

        if (!$table->save($entity)) {
            return $this->fail('save_failed', 'Could not create list item.', 400, $entity->getErrors());
        }

        return $this->respond($entity, [], 201);
    }

    /**
     * PUT /api/master-lists/{list}/{id}
     */
    public function editItem(string $list, string $id): Response
    {
        $tableMap = [
            'districts' => 'Districts',
            'symptoms' => 'Symptoms',
            'resolutions' => 'Resolutions',
            'hold_reasons' => 'HoldReasons',
            'job_types' => 'JobTypes',
            'service_centers' => 'ServiceCenters',
        ];

        if (!isset($tableMap[$list])) {
            return $this->fail('not_found', 'Invalid master list specified.', 404);
        }

        $table = $this->fetchTable($tableMap[$list]);
        $entity = $table->find()->where(['id' => (int)$id])->first();

        if ($entity === null) {
            return $this->fail('not_found', 'List item not found.', 404);
        }

        $data = (array)$this->request->getData();
        $entity = $table->patchEntity($entity, $data);

        if ($entity->hasErrors()) {
            return $this->fail('validation_error', 'Invalid list item data.', 422, $entity->getErrors());
        }

        if (!$table->save($entity)) {
            return $this->fail('save_failed', 'Could not update list item.', 400, $entity->getErrors());
        }

        return $this->respond($entity);
    }

    /**
     * DELETE /api/master-lists/{list}/{id}
     */
    public function toggleItem(string $list, string $id): Response
    {
        $tableMap = [
            'districts' => 'Districts',
            'symptoms' => 'Symptoms',
            'resolutions' => 'Resolutions',
            'hold_reasons' => 'HoldReasons',
            'job_types' => 'JobTypes',
            'service_centers' => 'ServiceCenters',
        ];

        if (!isset($tableMap[$list])) {
            return $this->fail('not_found', 'Invalid master list specified.', 404);
        }

        $table = $this->fetchTable($tableMap[$list]);
        $entity = $table->find()->where(['id' => (int)$id])->first();

        if ($entity === null) {
            return $this->fail('not_found', 'List item not found.', 404);
        }

        if (isset($entity->is_active)) {
            $entity->is_active = !$entity->is_active;
            $table->save($entity);
        }

        return $this->respond($entity);
    }
}
