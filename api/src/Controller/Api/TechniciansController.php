<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Field technicians API controller.
 */
class TechniciansController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'view', 'add', 'edit', 'delete']);
    }

    /**
     * GET /api/technicians
     */
    public function index(): Response
    {
        $techniciansTable = $this->fetchTable('Technicians');
        $query = $techniciansTable->find()
            ->contain(['ServiceCenters', 'Users']);

        $serviceCenterId = $this->request->getQuery('service_center_id');
        if ($serviceCenterId) {
            $query->where(['Technicians.service_center_id' => (int)$serviceCenterId]);
        }

        $isActive = $this->request->getQuery('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->where(['Technicians.is_active' => (bool)(int)$isActive]);
        }

        $search = $this->request->getQuery('search');
        if ($search) {
            $query->where([
                'OR' => [
                    'Technicians.name LIKE' => '%' . $search . '%',
                    'Technicians.code LIKE' => '%' . $search . '%',
                    'Technicians.phone LIKE' => '%' . $search . '%',
                    'Technicians.email LIKE' => '%' . $search . '%',
                ],
            ]);
        }

        $technicians = $query->orderBy(['Technicians.name' => 'ASC'])->all();

        return $this->respond($technicians);
    }

    /**
     * GET /api/technicians/{id}
     */
    public function view(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);
        $technician = $this->fetchTable('Technicians')->find()
            ->where(['Technicians.id' => $id])
            ->contain(['ServiceCenters', 'Users', 'TechnicianRates'])
            ->first();

        if ($technician === null) {
            return $this->fail('not_found', 'Technician not found.', 404);
        }

        return $this->respond($technician);
    }

    /**
     * POST /api/technicians
     */
    public function add(): Response
    {
        $techniciansTable = $this->fetchTable('Technicians');
        $data = (array)$this->request->getData();

        $technician = $techniciansTable->newEntity($data);
        if ($technician->hasErrors()) {
            return $this->fail('validation_error', 'Invalid technician data.', 422, $technician->getErrors());
        }

        if (!$techniciansTable->save($technician)) {
            return $this->fail('save_failed', 'Could not create technician.', 400, $technician->getErrors());
        }

        $technician = $techniciansTable->get($technician->id, contain: ['ServiceCenters', 'Users']);
        return $this->respond($technician, [], 201);
    }

    /**
     * PUT /api/technicians/{id}
     */
    public function edit(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);
        $techniciansTable = $this->fetchTable('Technicians');
        $technician = $techniciansTable->find()->where(['id' => $id])->first();

        if ($technician === null) {
            return $this->fail('not_found', 'Technician not found.', 404);
        }

        $data = (array)$this->request->getData();
        $technician = $techniciansTable->patchEntity($technician, $data);

        if ($technician->hasErrors()) {
            return $this->fail('validation_error', 'Invalid technician data.', 422, $technician->getErrors());
        }

        if (!$techniciansTable->save($technician)) {
            return $this->fail('save_failed', 'Could not update technician.', 400, $technician->getErrors());
        }

        $technician = $techniciansTable->get($technician->id, contain: ['ServiceCenters', 'Users']);
        return $this->respond($technician);
    }

    /**
     * DELETE /api/technicians/{id}
     *
     * Payouts, cash collections and rate history hang off a technician, so
     * removing the row outright would either cascade into money records or
     * fail on the foreign key. Deactivating keeps the history intact and
     * takes them off the dispatch board's assignment list.
     */
    public function delete(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);
        $techniciansTable = $this->fetchTable('Technicians');
        $technician = $techniciansTable->find()->where(['id' => $id])->first();

        if ($technician === null) {
            return $this->fail('not_found', 'Technician not found.', 404);
        }

        $technician->is_active = !$technician->is_active;
        $techniciansTable->save($technician);

        return $this->respond(['id' => $technician->id, 'is_active' => $technician->is_active]);
    }
}
