<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Company\SettingCatalog;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Security;
use finfo;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Proof that the work happened: where, when, and that the customer agrees.
 *
 * All three exist for the same reason. When a company disputes a job —
 * "our customer says nobody came" — the only useful answer is a check-in
 * recorded at the customer's coordinates, photographs taken on site, and a
 * confirmation the customer themselves gave. Anything less is our word
 * against theirs, and under an agreement that can be terminated on notice
 * that is not a position worth being in.
 *
 * Which of the three is mandatory is a per-company setting, because the
 * cost is real: OTP needs signal the customer may not have, and a company
 * doing installations in rural Kerala may reasonably accept a photograph
 * instead. What is not optional is recording whatever was captured.
 */
class EvidenceService
{
    use LocatorAwareTrait;

    /**
     * How far from the customer a check-in may be and still be treated as
     * on site.
     *
     * Generous on purpose. Consumer GPS is routinely 50m out and much
     * worse indoors, which is exactly where a television is. This is a
     * flag for review, never a block — refusing a genuine technician entry
     * to a building teaches them to stop using the app.
     */
    private const CHECKIN_TOLERANCE_M = 500;

    public function __construct(
        private readonly CompanyConfigRepository $config = new CompanyConfigRepository(),
        private readonly TicketWorkflow $workflow = new TicketWorkflow(),
    ) {
    }

    // -----------------------------------------------------------------
    // geo check-in
    // -----------------------------------------------------------------

    /**
     * Record the technician arriving on site.
     *
     * @return array{ok: true, distance_m: int|null, within_tolerance: bool}
     *        |array{ok: false, errors: array<string, list<string>>}
     */
    public function checkIn(
        int $ticketId,
        float $latitude,
        float $longitude,
        ?int $accuracyMetres = null,
        ?int $actorUserId = null,
    ): array {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId, contain: ['Customers']);

        if ($ticket->assigned_technician_id === null) {
            return ['ok' => false, 'errors' => ['ticket' => ['Nobody is assigned to this job yet.']]];
        }

        // First check-in wins. A technician who re-opens the app should not
        // be able to move their own arrival time later, which is precisely
        // the timestamp the visit SLA is measured against.
        if ($ticket->checkin_at !== null) {
            return [
                'ok' => true,
                'distance_m' => $ticket->checkin_distance_m,
                'within_tolerance' => ($ticket->checkin_distance_m ?? 0) <= self::CHECKIN_TOLERANCE_M,
            ];
        }

        $distance = null;
        if ($ticket->customer?->latitude !== null && $ticket->customer?->longitude !== null) {
            $distance = $this->distanceMetres(
                $latitude,
                $longitude,
                (float)$ticket->customer->latitude,
                (float)$ticket->customer->longitude,
            );
        }

        $now = DateTime::now();

        $ticket->set('checkin_at', $now);
        $ticket->set('checkin_latitude', $latitude);
        $ticket->set('checkin_longitude', $longitude);
        $ticket->set('checkin_accuracy_m', $accuracyMetres);
        $ticket->set('checkin_distance_m', $distance);

        // Arrival is what the visit clock measures, so it is set here
        // rather than waiting for someone to press a separate button.
        if ($ticket->visited_at === null) {
            $ticket->set('visited_at', $now);
        }

        $from = $ticket->status;
        if ($this->workflow->canTransition($from, 'visited')) {
            $ticket->set('status', 'visited');
        }

        $tickets->saveOrFail($ticket);

        $withinTolerance = $distance === null || $distance <= self::CHECKIN_TOLERANCE_M;

        $this->workflow->logEvent(
            $ticketId,
            'checked_in',
            $from,
            $ticket->status,
            $actorUserId,
            $distance === null
                ? 'Checked in on site. No customer coordinates to compare against.'
                : sprintf('Checked in %dm from the customer address.', $distance),
            [
                'distance_m' => $distance,
                'accuracy_m' => $accuracyMetres,
                'within_tolerance' => $withinTolerance,
            ],
            $latitude,
            $longitude,
        );

        return ['ok' => true, 'distance_m' => $distance, 'within_tolerance' => $withinTolerance];
    }

    /**
     * Great-circle distance in metres.
     *
     * Haversine rather than a projection: Kerala spans about 1.5 degrees of
     * latitude and the errors that matter here are metres, so the extra
     * accuracy of anything more elaborate is lost in GPS noise anyway.
     */
    public function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6_371_000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return (int)round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Record the technician leaving.
     */
    public function checkOut(int $ticketId, ?int $actorUserId = null): void
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);

        if ($ticket->checkout_at !== null) {
            return;
        }

        $ticket->set('checkout_at', DateTime::now());
        $tickets->saveOrFail($ticket);

        $this->workflow->logEvent(
            $ticketId,
            'checked_out',
            $ticket->status,
            $ticket->status,
            $actorUserId,
            'Left site.',
        );
    }

    // -----------------------------------------------------------------
    // photographs
    // -----------------------------------------------------------------

    /** Anything larger is a video someone picked by mistake. */
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

    /**
     * What a browser can actually produce from a phone camera, plus the one
     * video type worth accepting. Checked against the file's own bytes, not
     * against the Content-Type the client claimed.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'video/mp4' => 'mp4',
    ];

    /**
     * Take an uploaded file, store it, and record it as evidence.
     *
     * The bytes land under `webroot/uploads/tickets/{id}/` with a generated
     * name. Never the name the browser sent: that string is attacker-chosen
     * and goes into a filesystem path, so `../../config/app_local.php` is a
     * real request somebody will eventually make. The original is kept in a
     * column, where it is data rather than a path.
     *
     * The type is read from the file's own leading bytes. A client that says
     * `image/jpeg` while uploading a PHP script is the oldest trick there
     * is, and the declared Content-Type is exactly what it controls.
     *
     * @return array{ok: true, attachment_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function upload(
        int $ticketId,
        string $kind,
        UploadedFileInterface $file,
        ?int $actorUserId = null,
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'errors' => ['file' => [
                $file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
                    ? 'That file is too large for the server to accept.'
                    : 'The file did not arrive intact. Try again.',
            ]]];
        }

        $size = (int)$file->getSize();
        if ($size <= 0) {
            return ['ok' => false, 'errors' => ['file' => ['That file is empty.']]];
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'errors' => ['file' => [sprintf(
                'Keep it under %dMB. That one is %.1fMB.',
                (int)(self::MAX_UPLOAD_BYTES / 1024 / 1024),
                $size / 1024 / 1024,
            )]]];
        }

        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->getContents();

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return ['ok' => false, 'errors' => ['file' => [sprintf(
                'That is a %s. Attach a photo (JPEG, PNG, WebP or HEIC) or an MP4.',
                $mime === '' ? 'file of unknown type' : $mime,
            )]]];
        }

        // Hashed before writing, so a duplicate is rejected without ever
        // touching the disk.
        $sha256 = hash('sha256', $bytes);

        $duplicate = $this->fetchTable('TicketAttachments')->find()
            ->select(['id', 'kind'])
            ->where(['ticket_id' => $ticketId, 'sha256' => $sha256])
            ->disableHydration()
            ->first();

        if ($duplicate !== null) {
            return ['ok' => false, 'errors' => ['file' => [sprintf(
                'This exact file is already attached to this ticket as "%s".',
                $duplicate['kind'],
            )]]];
        }

        $relativeDir = sprintf('uploads/tickets/%d', $ticketId);
        $absoluteDir = WWW_ROOT . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return ['ok' => false, 'errors' => ['file' => ['The upload directory could not be created.']]];
        }

        $name = sprintf('%s-%s.%s', substr($sha256, 0, 16), bin2hex(random_bytes(4)), self::ALLOWED_MIME[$mime]);
        $relativePath = $relativeDir . '/' . $name;

        if (file_put_contents($absoluteDir . '/' . $name, $bytes) === false) {
            return ['ok' => false, 'errors' => ['file' => ['The file could not be written.']]];
        }

        // Only for images. getimagesize() on an MP4 emits a warning and
        // returns false, which is noise rather than information.
        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $dimensions = getimagesize($absoluteDir . '/' . $name);
            if ($dimensions !== false) {
                [$width, $height] = $dimensions;
            }
        }

        return $this->attach($ticketId, $kind, [
            'storage_disk' => 'local',
            'storage_path' => $relativePath,
            'original_name' => $this->safeOriginalName($file->getClientFilename()),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
        ], $actorUserId);
    }

    /**
     * Keep the browser's filename as a label, stripped of anything that
     * makes it useful as a path.
     */
    private function safeOriginalName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return mb_substr(preg_replace('/[^\w.\- ]/u', '', basename($name)) ?? '', 0, 255) ?: null;
    }

    /**
     * Attach a photograph or document to a ticket.
     *
     * The hash is the point of the metadata. Two "after" photos with the
     * same SHA-256 is one photo uploaded twice, which is the cheapest
     * possible way to fake evidence of a second visit.
     *
     * @param array<string, mixed> $meta
     * @return array{ok: true, attachment_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function attach(int $ticketId, string $kind, array $meta, ?int $actorUserId = null): array
    {
        $allowedKinds = ['serial_plate', 'before', 'after', 'signature', 'spare', 'invoice', 'video', 'other'];

        if (!in_array($kind, $allowedKinds, true)) {
            return ['ok' => false, 'errors' => ['kind' => ['Not a kind of evidence we record.']]];
        }

        if (empty($meta['storage_path'])) {
            return ['ok' => false, 'errors' => ['storage_path' => ['The stored file path is required.']]];
        }

        $attachments = $this->fetchTable('TicketAttachments');

        if (!empty($meta['sha256'])) {
            $duplicate = $attachments->find()
                ->select(['id', 'kind'])
                ->where(['ticket_id' => $ticketId, 'sha256' => $meta['sha256']])
                ->disableHydration()
                ->first();

            if ($duplicate !== null) {
                return ['ok' => false, 'errors' => ['sha256' => [sprintf(
                    'This exact file is already attached to this ticket as "%s".',
                    $duplicate['kind'],
                )]]];
            }
        }

        $attachment = $attachments->newEntity([
            'ticket_id' => $ticketId,
            'kind' => $kind,
            'storage_disk' => $meta['storage_disk'] ?? 'local',
            'storage_path' => $meta['storage_path'],
            'original_name' => $meta['original_name'] ?? null,
            'mime_type' => $meta['mime_type'] ?? null,
            'size_bytes' => $meta['size_bytes'] ?? null,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'sha256' => $meta['sha256'] ?? null,
            'exif_taken_at' => $meta['exif_taken_at'] ?? null,
            'captured_latitude' => $meta['captured_latitude'] ?? null,
            'captured_longitude' => $meta['captured_longitude'] ?? null,
            'uploaded_by_user_id' => $actorUserId,
            'uploaded_at' => DateTime::now(),
        ]);

        if (!$attachments->save($attachment)) {
            return ['ok' => false, 'errors' => $attachment->getErrors()];
        }

        // A symptom that needed a video is unblocked by receiving one; the
        // ticket was legitimately on hold waiting for it.
        //
        // The bytes decide, not the label. A technician who films the fault
        // and leaves the picker on "after" has still sent the video, and the
        // duplicate-hash check means they cannot simply upload it again
        // under the right label to fix it.
        if ($kind === 'video' || str_starts_with((string)($meta['mime_type'] ?? ''), 'video/')) {
            $this->fetchTable('Tickets')->updateAll(
                ['video_proof_received_at' => DateTime::now()],
                ['id' => $ticketId, 'video_proof_received_at IS' => null],
            );
        }

        $this->workflow->logEvent($ticketId, 'attachment_added', null, null, $actorUserId, sprintf(
            'Attached %s evidence.',
            $kind,
        ), ['attachment_id' => (int)$attachment->id, 'kind' => $kind]);

        return ['ok' => true, 'attachment_id' => (int)$attachment->id];
    }

    // -----------------------------------------------------------------
    // customer OTP
    // -----------------------------------------------------------------

    /**
     * How many wrong codes before the OTP is dead.
     *
     * A 6-digit code guessed at random has a 1-in-a-million chance per
     * attempt; unlimited attempts turn that into a certainty, and the
     * thing being confirmed is that a job was done and is billable.
     */
    private const OTP_MAX_ATTEMPTS = 5;
    private const OTP_TTL_MINUTES = 30;

    /**
     * Issue a closure code to the customer.
     *
     * The code is hashed, never stored in the clear: the technician
     * standing next to the customer has database access through the app,
     * and a readable code makes the confirmation meaningless.
     *
     * @return array{ok: true, sent_to: string, expires_in_minutes: int, code: string|null}
     *        |array{ok: false, errors: array<string, list<string>>}
     */
    public function issueClosureOtp(int $ticketId, ?int $actorUserId = null, bool $returnCode = false): array
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId, contain: ['Customers']);

        $phone = (string)($ticket->customer?->phone ?? '');
        if ($phone === '') {
            return ['ok' => false, 'errors' => ['customer' => ['The customer has no phone number on record.']]];
        }

        // random_int, not rand: the codes must not be predictable from one
        // another, and rand() is seeded well enough to make them so.
        $code = (string)random_int(100000, 999999);

        $ticket->set('closure_otp_hash', password_hash($code, PASSWORD_DEFAULT));
        $ticket->set('closure_otp_sent_at', DateTime::now());
        $ticket->set('closure_otp_attempts', 0);
        $ticket->set('closure_otp_verified_at', null);

        $tickets->saveOrFail($ticket);

        $this->workflow->logEvent($ticketId, 'otp_sent', null, null, $actorUserId, sprintf(
            'Closure code sent to %s.',
            $this->maskPhone($phone),
        ), ['phone' => $this->maskPhone($phone)]);

        return [
            'ok' => true,
            'sent_to' => $this->maskPhone($phone),
            'expires_in_minutes' => self::OTP_TTL_MINUTES,
            // Only ever returned in development, where there is no SMS
            // gateway. The caller decides; the default is not to.
            'code' => $returnCode ? $code : null,
        ];
    }

    /**
     * Check the code the customer read out.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, list<string>>}
     */
    public function verifyClosureOtp(int $ticketId, string $code, ?int $actorUserId = null): array
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);

        if ($ticket->closure_otp_verified_at !== null) {
            return ['ok' => true];
        }

        if ($ticket->closure_otp_hash === null || $ticket->closure_otp_sent_at === null) {
            return ['ok' => false, 'errors' => ['otp' => ['No code has been sent for this ticket.']]];
        }

        if ((int)$ticket->closure_otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            return ['ok' => false, 'errors' => ['otp' => ['Too many wrong attempts. Send a new code.']]];
        }

        $expiresAt = (new DateTime($ticket->closure_otp_sent_at))->addMinutes(self::OTP_TTL_MINUTES);
        if (DateTime::now() > $expiresAt) {
            return ['ok' => false, 'errors' => ['otp' => ['That code has expired. Send a new one.']]];
        }

        if (!password_verify($code, (string)$ticket->closure_otp_hash)) {
            // Counted before returning, so a failed attempt costs the
            // guesser an attempt even if they abandon the request.
            $ticket->set('closure_otp_attempts', (int)$ticket->closure_otp_attempts + 1);
            $tickets->saveOrFail($ticket);

            $remaining = self::OTP_MAX_ATTEMPTS - (int)$ticket->closure_otp_attempts;

            return ['ok' => false, 'errors' => ['otp' => [sprintf(
                'That code is not right. %d attempt(s) left.',
                max(0, $remaining),
            )]]];
        }

        $ticket->set('closure_otp_verified_at', DateTime::now());
        // The hash has done its job and is no longer needed. Clearing it
        // means a leaked backup cannot be used to replay a confirmation.
        $ticket->set('closure_otp_hash', null);
        $tickets->saveOrFail($ticket);

        $this->workflow->logEvent(
            $ticketId,
            'otp_verified',
            null,
            null,
            $actorUserId,
            'Customer confirmed the job by code.',
        );

        return ['ok' => true];
    }

    /**
     * What this company still needs before the job can be closed.
     *
     * Returned as a list so the field app can show all of it at once —
     * a technician standing in a customer's front room should find out
     * about the missing photo and the missing OTP in the same breath.
     *
     * @return list<string>
     */
    public function outstandingRequirements(int $ticketId): array
    {
        $ticket = $this->fetchTable('Tickets')->get($ticketId);
        $settings = $this->config->settings((int)$ticket->company_id);

        $missing = [];

        if ($settings->bool(SettingCatalog::CLOSURE_REQUIRE_PHOTO, true)) {
            $photos = $this->fetchTable('TicketAttachments')->find()
                ->where(['ticket_id' => $ticketId, 'kind IN' => ['after', 'before', 'serial_plate']])
                ->count();

            if ($photos === 0) {
                $missing[] = 'At least one photograph of the work.';
            }
        }

        if ($settings->bool(SettingCatalog::CLOSURE_REQUIRE_SIGNATURE, false)) {
            $signature = $this->fetchTable('TicketAttachments')->find()
                ->where(['ticket_id' => $ticketId, 'kind' => 'signature'])
                ->count();

            if ($signature === 0) {
                $missing[] = 'The customer\'s signature.';
            }
        }

        if ($settings->bool(SettingCatalog::CLOSURE_REQUIRE_OTP, false)) {
            if ($ticket->closure_otp_verified_at === null) {
                $missing[] = 'A closure code confirmed by the customer.';
            }
        }

        if ($ticket->video_proof_required && $ticket->video_proof_received_at === null) {
            $missing[] = 'The symptom video the customer was asked for.';
        }

        return $missing;
    }

    /**
     * Show enough of a phone number to be recognisable, not enough to dial.
     */
    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        return $length <= 4 ? $phone : str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
