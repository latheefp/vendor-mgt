<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Roles & Permissions API controller.
 */
class RolesController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'view', 'add', 'edit', 'delete', 'permissionsCatalog']);
    }

    /**
     * GET /api/roles
     */
    public function index(): Response
    {
        $rolesTable = $this->fetchTable('Roles');
        $roles = $rolesTable->find()
            ->select([
                'Roles.id',
                'Roles.code',
                'Roles.name',
                'Roles.description',
                'Roles.permissions',
                'Roles.is_system',
                'Roles.created',
                'Roles.modified',
                'user_count' => $rolesTable->Users->find()->where(['Users.role_id = Roles.id'])->select(['count' => 'COUNT(*)']),
            ])
            ->orderBy(['Roles.name' => 'ASC'])
            ->all();

        return $this->respond($roles);
    }

    /**
     * GET /api/roles/permissions-catalog
     */
    public function permissionsCatalog(): Response
    {
        $catalog = [
            'tickets' => [
                'label' => 'Tickets & Work Orders',
                'permissions' => [
                    ['code' => 'tickets.view', 'name' => 'View Tickets', 'description' => 'View all ticket details and timeline'],
                    ['code' => 'tickets.create', 'name' => 'Create Tickets', 'description' => 'Intake and create new support/service tickets'],
                    ['code' => 'tickets.assign', 'name' => 'Assign Technicians', 'description' => 'Assign tickets to field technicians'],
                    ['code' => 'tickets.hold', 'name' => 'Hold & Release', 'description' => 'Place tickets on hold or release SLA clock'],
                    ['code' => 'tickets.close', 'name' => 'Close Tickets', 'description' => 'Complete and close tickets with resolution'],
                    ['code' => 'tickets.own.view', 'name' => 'View Own Assigned Tickets', 'description' => 'For field technicians to view assigned work'],
                    ['code' => 'tickets.own.checkin', 'name' => 'Checkin & Evidence', 'description' => 'Perform geo check-in and capture photos/OTP'],
                ],
            ],
            'financials' => [
                'label' => 'Invoicing & Payouts',
                'permissions' => [
                    ['code' => 'invoices.view', 'name' => 'View Invoices', 'description' => 'View company invoices and payment status'],
                    ['code' => 'invoices.generate', 'name' => 'Generate Invoices', 'description' => 'Run invoice generation for companies'],
                    ['code' => 'invoices.payment', 'name' => 'Record Payments', 'description' => 'Record payment received from companies'],
                    ['code' => 'payouts.view', 'name' => 'View Technician Payouts', 'description' => 'View technician earnings and payout runs'],
                    ['code' => 'payouts.generate', 'name' => 'Generate Payouts', 'description' => 'Generate technician payout calculations'],
                    ['code' => 'payouts.approve', 'name' => 'Approve & Pay Payouts', 'description' => 'Approve and record technician payments'],
                ],
            ],
            'companies' => [
                'label' => 'Companies & Rate Cards',
                'permissions' => [
                    ['code' => 'companies.view', 'name' => 'View Company Profiles', 'description' => 'View company agreements and terms'],
                    ['code' => 'companies.manage', 'name' => 'Manage Companies', 'description' => 'Add/Edit companies and settings'],
                    ['code' => 'rates.view', 'name' => 'View Rate Cards', 'description' => 'View company rate cards and SLA rules'],
                    ['code' => 'rates.manage', 'name' => 'Manage Rate Cards', 'description' => 'Create, edit, and publish rate cards & SLA rules'],
                ],
            ],
            'catalog' => [
                'label' => 'Products & Appliances',
                'permissions' => [
                    ['code' => 'products.view', 'name' => 'View Catalog', 'description' => 'View appliance categories and products'],
                    ['code' => 'products.manage', 'name' => 'Manage Products & Categories', 'description' => 'Add/Edit appliance categories and models'],
                ],
            ],
            'system' => [
                'label' => 'System & Security Settings',
                'permissions' => [
                    ['code' => 'users.view', 'name' => 'View User Accounts', 'description' => 'View system user accounts and roles'],
                    ['code' => 'users.manage', 'name' => 'Manage Users & Reset Passwords', 'description' => 'Create/Edit users and reset passwords'],
                    ['code' => 'roles.manage', 'name' => 'Manage Groups & Permissions', 'description' => 'Create/Edit custom user roles and permissions'],
                    ['code' => 'masterlists.manage', 'name' => 'Manage Master Lists', 'description' => 'Manage districts, symptoms, resolutions & hold reasons'],
                ],
            ],
        ];

        return $this->respond($catalog);
    }

    /**
     * GET /api/roles/{id}
     */
    public function view(string $id): Response
    {
        $role = $this->fetchTable('Roles')->find()
            ->where(['id' => (int)$id])
            ->contain(['Users'])
            ->first();

        if ($role === null) {
            return $this->fail('not_found', 'Role not found.', 404);
        }

        return $this->respond($role);
    }

    /**
     * POST /api/roles
     */
    public function add(): Response
    {
        $rolesTable = $this->fetchTable('Roles');
        $data = (array)$this->request->getData();

        if (is_array($data['permissions'] ?? null)) {
            $data['permissions'] = $data['permissions'];
        }

        $data['is_system'] = false;

        $role = $rolesTable->newEntity($data);
        if ($role->hasErrors()) {
            return $this->fail('validation_error', 'Invalid role data.', 422, $role->getErrors());
        }

        if (!$rolesTable->save($role)) {
            return $this->fail('save_failed', 'Could not create role.', 400, $role->getErrors());
        }

        return $this->respond($role, [], 201);
    }

    /**
     * PUT /api/roles/{id}
     */
    public function edit(string $id): Response
    {
        $rolesTable = $this->fetchTable('Roles');
        $role = $rolesTable->find()->where(['id' => (int)$id])->first();

        if ($role === null) {
            return $this->fail('not_found', 'Role not found.', 404);
        }

        $data = (array)$this->request->getData();

        // System roles preserve code
        if ($role->is_system && isset($data['code'])) {
            unset($data['code']);
        }

        $role = $rolesTable->patchEntity($role, $data);
        if ($role->hasErrors()) {
            return $this->fail('validation_error', 'Invalid role data.', 422, $role->getErrors());
        }

        if (!$rolesTable->save($role)) {
            return $this->fail('save_failed', 'Could not update role.', 400, $role->getErrors());
        }

        return $this->respond($role);
    }

    /**
     * DELETE /api/roles/{id}
     */
    public function delete(string $id): Response
    {
        $rolesTable = $this->fetchTable('Roles');
        $role = $rolesTable->find()->where(['id' => (int)$id])->first();

        if ($role === null) {
            return $this->fail('not_found', 'Role not found.', 404);
        }

        if ($role->is_system) {
            return $this->fail('forbidden', 'System roles cannot be deleted.', 403);
        }

        $userCount = $rolesTable->Users->find()->where(['role_id' => $role->id])->count();
        if ($userCount > 0) {
            return $this->fail('conflict', 'Cannot delete role assigned to active users.', 409);
        }

        $rolesTable->delete($role);

        return $this->respond(['id' => (int)$id, 'deleted' => true]);
    }
}
