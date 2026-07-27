<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * Session-cookie login for the SPA.
 *
 * No JWT. The client is same-origin, so it rides Cake's session cookie —
 * HttpOnly and SameSite=Lax, therefore unreadable by injected script. The
 * only thing JavaScript reads is the CSRF token, which is not a credential.
 *
 * Lockout is implemented here rather than left as a "later" item because
 * field technicians share and lose phones, and this login is all that
 * stands between a mislaid handset and somebody else's job list, customer
 * addresses and phone numbers.
 */
class AuthController extends ApiController
{
    /** Failed attempts before the account is temporarily locked. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** How long a lock lasts. Long enough to defeat guessing, short enough
     *  that a technician mid-round is not stranded for the day. */
    private const LOCKOUT_MINUTES = 15;

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Reaching the login endpoint obviously cannot require a login.
        $this->Authentication->allowUnauthenticated(['login', 'csrf']);
    }

    /**
     * GET /api/auth/csrf
     *
     * Bootstraps the SPA. Cake sets the CSRF cookie on any response, so the
     * client calls this once on load and then has a token for its first
     * POST — which is the login itself.
     */
    public function csrf(): Response
    {
        return $this->respond([
            'csrf_cookie' => 'gvsCsrfToken',
            'csrf_header' => 'X-CSRF-Token',
            // The cookie value is URL-encoded. Clients MUST decodeURIComponent
            // it before echoing it back, or every POST fails the token check.
            'url_encoded' => true,
        ]);
    }

    /**
     * POST /api/auth/login  { "email": "...", "password": "..." }
     */
    public function login(): Response
    {
        $result = $this->Authentication->getResult();
        $email = trim((string)$this->request->getData('email'));

        if ($result === null || !$result->isValid()) {
            $this->recordFailedAttempt($email);

            /*
             * One message for every failure mode — wrong address, wrong
             * password, disabled account, locked account. Distinguishing them
             * would tell an attacker which of our technicians' addresses are
             * real.
             */
            return $this->fail(
                'invalid_credentials',
                'That email address and password do not match an active account.',
                401,
            );
        }

        /** @var \App\Model\Entity\User $identity */
        $identity = $result->getData();

        $user = $this->fetchTable('Users')->get($identity->id, contain: ['Roles', 'ServiceCenters']);

        // Successful login clears the failure counter and any expired lock.
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = new DateTime();
        $user->last_login_ip = $this->request->clientIp();
        $this->fetchTable('Users')->save($user);

        return $this->respond($this->presentUser($user));
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): Response
    {
        $this->Authentication->logout();

        return $this->respond(['logged_out' => true]);
    }

    /**
     * GET /api/auth/me
     *
     * The SPA calls this on boot to decide whether to show the login screen
     * or the app, and which shell to render.
     */
    public function me(): Response
    {
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return $this->fail('unauthenticated', 'Not signed in.', 401);
        }

        $user = $this->fetchTable('Users')->get(
            $identity->getIdentifier(),
            contain: ['Roles', 'ServiceCenters'],
        );

        return $this->respond($this->presentUser($user));
    }

    /**
     * The shape the client needs, and nothing more.
     *
     * `landing` is server-decided on purpose. Which shell a person sees is
     * an authorisation question, so the answer comes from the same place
     * that enforces it — not from a role string the client interprets.
     *
     * @return array<string, mixed>
     */
    private function presentUser(\App\Model\Entity\User $user): array
    {
        $roleCode = $user->role?->code ?? 'technician';

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => [
                'code' => $roleCode,
                'name' => $user->role?->name,
            ],
            'service_center' => $user->service_center === null ? null : [
                'id' => $user->service_center->id,
                'code' => $user->service_center->code,
                'name' => $user->service_center->name,
            ],
            'must_change_password' => (bool)$user->must_change_password,
            'landing' => $this->landingFor($roleCode),
            'permissions' => $user->role?->permissions ?? [],
        ];
    }

    /**
     * Desk staff and field technicians get deliberately different shells.
     *
     * A dense dispatch board squeezed onto a phone is a bad field UI, and a
     * thumb-sized single-job screen is a bad desk UI. Same application, two
     * front ends, decided here at sign-in.
     */
    private function landingFor(string $roleCode): string
    {
        return match ($roleCode) {
            'technician' => '/field',
            default => '/desk',
        };
    }

    /**
     * Count a failed attempt and lock the account once it has had too many.
     *
     * Deliberately silent — the response is identical whether or not the
     * address exists, so this cannot be used to enumerate accounts.
     */
    private function recordFailedAttempt(string $email): void
    {
        if ($email === '') {
            return;
        }

        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['email' => $email])->first();

        if ($user === null) {
            return;
        }

        $user->failed_login_count = (int)$user->failed_login_count + 1;

        if ($user->failed_login_count >= self::MAX_FAILED_ATTEMPTS) {
            $user->locked_until = (new DateTime())->modify(
                sprintf('+%d minutes', self::LOCKOUT_MINUTES),
            );
            $user->failed_login_count = 0;
        }

        $users->save($user);
    }
}
