<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Users management API controller.
 */
class UsersController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'view', 'add', 'edit', 'delete']);
    }

    /**
     * GET /api/users
     */
    public function index(): Response
    {
        $usersTable = $this->fetchTable('Users');
        $query = $usersTable->find()
            ->contain(['Roles', 'ServiceCenters', 'Technicians']);

        $roleId = $this->request->getQuery('role_id');
        if ($roleId) {
            $query->where(['Users.role_id' => (int)$roleId]);
        }

        $serviceCenterId = $this->request->getQuery('service_center_id');
        if ($serviceCenterId) {
            $query->where(['Users.service_center_id' => (int)$serviceCenterId]);
        }

        $search = $this->request->getQuery('search');
        if ($search) {
            $query->where([
                'OR' => [
                    'Users.name LIKE' => '%' . $search . '%',
                    'Users.email LIKE' => '%' . $search . '%',
                    'Users.phone LIKE' => '%' . $search . '%',
                ],
            ]);
        }

        $users = $query->orderBy(['Users.name' => 'ASC'])->all();

        return $this->respond($users);
    }

    /**
     * GET /api/users/{id}
     */
    public function view(string $id): Response
    {
        $user = $this->fetchTable('Users')->find()
            ->where(['Users.id' => (int)$id])
            ->contain(['Roles', 'ServiceCenters', 'Technicians'])
            ->first();

        if ($user === null) {
            return $this->fail('not_found', 'User not found.', 404);
        }

        return $this->respond($user);
    }

    /**
     * POST /api/users
     */
    public function add(): Response
    {
        $usersTable = $this->fetchTable('Users');
        $data = (array)$this->request->getData();

        if (empty($data['password'])) {
            $data['password'] = 'Password123!';
        }

        $user = $usersTable->newEntity($data);
        if ($user->hasErrors()) {
            return $this->fail('validation_error', 'Invalid user data.', 422, $user->getErrors());
        }

        if (!$usersTable->save($user)) {
            return $this->fail('save_failed', 'Could not create user.', 400, $user->getErrors());
        }

        $user = $usersTable->get($user->id, contain: ['Roles', 'ServiceCenters']);
        return $this->respond($user, [], 201);
    }

    /**
     * PUT /api/users/{id}
     */
    public function edit(string $id): Response
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()->where(['id' => (int)$id])->first();

        if ($user === null) {
            return $this->fail('not_found', 'User not found.', 404);
        }

        $data = (array)$this->request->getData();
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user = $usersTable->patchEntity($user, $data);
        if ($user->hasErrors()) {
            return $this->fail('validation_error', 'Invalid user data.', 422, $user->getErrors());
        }

        if (!$usersTable->save($user)) {
            return $this->fail('save_failed', 'Could not update user.', 400, $user->getErrors());
        }

        $user = $usersTable->get($user->id, contain: ['Roles', 'ServiceCenters']);
        return $this->respond($user);
    }

    /**
     * DELETE /api/users/{id}
     */
    public function delete(string $id): Response
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()->where(['id' => (int)$id])->first();

        if ($user === null) {
            return $this->fail('not_found', 'User not found.', 404);
        }

        $user->is_active = !$user->is_active;
        $usersTable->save($user);

        return $this->respond(['id' => $user->id, 'is_active' => $user->is_active]);
    }
}
