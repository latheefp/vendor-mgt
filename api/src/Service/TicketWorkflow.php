<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Company\SettingCatalog;
use App\Domain\Exception\TicketTransitionException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * The ticket lifecycle: intake, assignment, holds and closure.
 *
 * Everything the desk and the field can do to a ticket goes through here
 * rather than through a controller writing to the table, for one reason:
 * every one of these actions has a side effect that must not be skippable.
 * An assignment writes an event; a hold recalculates the SLA pause; a
 * closure freezes money. A controller that patches the entity directly
 * gets the status right and silently drops the rest, and the omission is
 * invisible until an invoice is queried months later.
 *
 * What counts as valid at each step is per-company. Whether a serial
 * number is required at intake, whether a photo is required at closure,
 * whether the customer must confirm by OTP — all read from that company's
 * settings, because the answers are a matter of what each company's
 * agreement demands rather than of what this application prefers.
 */
class TicketWorkflow
{
    use LocatorAwareTrait;

    /**
     * Which statuses may follow which.
     *
     * Written down rather than left implicit because the expensive
     * transitions are the ones nobody thinks about: closing a ticket that
     * was never visited, or reopening one already invoiced. A map makes
     * both a rejection instead of a discrepancy.
     *
     * @var array<string, list<string>>
     */
    /**
     * The statuses a ticket rests in when no more work is owed on it.
     *
     * Named once because "is this job still live" is asked from several
     * places — a technician's open load, the desk's default list — and a
     * copy that missed one of these would quietly overcount.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = ['closed', 'cancelled', 'rejected'];

    private const TRANSITIONS = [
        'new' => ['assigned', 'on_hold', 'cancelled', 'rejected'],
        'assigned' => ['contacted', 'scheduled', 'visited', 'on_hold', 'cancelled', 'rejected'],
        'contacted' => ['scheduled', 'visited', 'on_hold', 'cancelled'],
        'scheduled' => ['visited', 'contacted', 'on_hold', 'cancelled'],
        'visited' => ['in_progress', 'awaiting_parts', 'closed', 'on_hold'],
        'in_progress' => ['awaiting_parts', 'closed', 'on_hold'],
        'awaiting_parts' => ['in_progress', 'closed', 'on_hold'],
        'on_hold' => ['assigned', 'contacted', 'scheduled', 'visited', 'in_progress', 'awaiting_parts', 'cancelled'],
        // Terminal until explicitly reopened, which is its own action.
        'closed' => ['reopened'],
        'reopened' => ['assigned', 'visited', 'in_progress', 'on_hold', 'closed'],
        'cancelled' => [],
        'rejected' => ['new'],
    ];

    public function __construct(
        private readonly CompanyConfigRepository $config = new CompanyConfigRepository(),
        private readonly RateCardRepository $rates = new RateCardRepository(),
        private readonly TicketNumberAllocator $numbers = new TicketNumberAllocator(),
    ) {
    }

    // -----------------------------------------------------------------
    // intake
    // -----------------------------------------------------------------

    /**
     * Take a new job in.
     *
     * @param array<string, mixed> $data
     * @return array{ok: true, ticket_id: int, ticket_no: string}|array{ok: false, errors: array<string, mixed>}
     */
    public function intake(array $data, ?int $actorUserId = null): array
    {
        $vendorId = (int)($data['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return ['ok' => false, 'errors' => ['vendor_id' => ['Select the company this job belongs to.']]];
        }

        $errors = $this->intakeErrors($vendorId, $data);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $customer = $this->resolveCustomer($data);
        if (!is_int($customer)) {
            return ['ok' => false, 'errors' => $customer];
        }

        // The vendor may have handed us the job hours before it reached
        // this form. Every SLA clock runs from when they did, not from now.
        $receivedAt = isset($data['received_at'])
            ? new DateTime((string)$data['received_at'])
            : DateTime::now();

        $onDate = $receivedAt->format('Y-m-d');

        $tickets = $this->fetchTable('Tickets');
        $connection = $tickets->getConnection();

        return $connection->transactional(
            function () use ($tickets, $vendorId, $customer, $data, $receivedAt, $onDate, $actorUserId): array {
                $agreementId = null;
                $rateCardId = null;
                $contactDue = null;
                $visitDue = null;
                $closeDue = null;

                try {
                    $terms = $this->rates->agreementTerms($vendorId, $onDate);
                    $windows = $terms->windows();

                    $agreementId = $terms->id;
                    $rateCardId = $this->rates->activeCardId($vendorId, $onDate);

                    // Due dates come from this company's agreement, not from
                    // constants. Dianora is 2/48/48; the next company will
                    // not be, and a hardcoded 24 here would quietly commit
                    // us to terms nobody signed.
                    $contactDue = $receivedAt->addMinutes((int)round($windows->contactHours * 60));
                    $visitDue = $receivedAt->addMinutes((int)round($windows->visitHours * 60));
                    $closeDue = $receivedAt->addMinutes((int)round($windows->closeHours * 60));
                } catch (RecordNotFoundException) {
                    // No agreement or no published card yet. The job is real
                    // and still has to be worked, so intake proceeds with the
                    // clocks unset rather than turning a configuration gap
                    // into a refused customer.
                }

                $ticketNo = $this->numbers->next($vendorId, $receivedAt->format('Ym'));

                $ticket = $tickets->newEntity([
                    'ticket_no' => $ticketNo,
                    'vendor_id' => $vendorId,
                    'vendor_ticket_ref' => $data['vendor_ticket_ref'] ?? null,
                    'vendor_agreement_id' => $agreementId,
                    'rate_card_id' => $rateCardId,
                    'service_center_id' => (int)$data['service_center_id'],
                    'customer_id' => $customer,
                    'product_id' => $data['product_id'] ?? null,
                    'product_category_id' => $data['product_category_id'] ?? null,
                    'brand_id' => $data['brand_id'] ?? null,
                    'model_no' => $data['model_no'] ?? null,
                    'serial_no' => $data['serial_no'] ?? null,
                    'size_inch' => $data['size_inch'] ?? null,
                    'purchase_date' => $data['purchase_date'] ?? null,
                    'warranty_scope' => $data['warranty_scope'] ?? 'unknown',
                    'job_type_id' => (int)$data['job_type_id'],
                    'symptom_id' => $data['symptom_id'] ?? null,
                    'status' => 'new',
                    'priority' => $data['priority'] ?? 'normal',
                    'reported_issue' => $data['reported_issue'] ?? null,
                    'received_at' => $receivedAt,
                    'contact_due_at' => $contactDue,
                    'visit_due_at' => $visitDue,
                    'close_due_at' => $closeDue,
                    'vendor_branch_label' => $data['vendor_branch_label'] ?? null,
                    'vendor_complaint_type' => $data['vendor_complaint_type'] ?? null,
                    'video_proof_required' => $this->videoProofRequired($data),
                    'vendor_payload' => $data['vendor_payload'] ?? null,
                    'source' => $data['source'] ?? 'desk',
                    'created_by_user_id' => $actorUserId,
                ]);

                if (!$tickets->save($ticket)) {
                    // Roll the sequence forward rather than back: a gap in
                    // ticket numbers is harmless, reusing one is not.
                    return ['ok' => false, 'errors' => $ticket->getErrors()];
                }

                $this->flagRepeatComplaint($ticket, $vendorId, $onDate);

                $this->logEvent($ticket->id, 'created', null, 'new', $actorUserId, 'Ticket taken in.', [
                    'source' => $ticket->source,
                    'rate_card_id' => $rateCardId,
                    'vendor_agreement_id' => $agreementId,
                ]);

                return ['ok' => true, 'ticket_id' => (int)$ticket->id, 'ticket_no' => $ticketNo];
            },
        );
    }

    /**
     * Intake requirements, as this company defines them.
     *
     * @param array<string, mixed> $data
     * @return array<string, list<string>>
     */
    private function intakeErrors(int $vendorId, array $data): array
    {
        $errors = [];

        if ((int)($data['service_center_id'] ?? 0) <= 0) {
            $errors['service_center_id'] = ['Select the service centre this job is dispatched from.'];
        }
        if ((int)($data['job_type_id'] ?? 0) <= 0) {
            $errors['job_type_id'] = ['Select what kind of job this is.'];
        }

        $settings = $this->config->settings($vendorId);

        // Warranty status is decided from the serial number and the bill
        // date. A company that reimburses in-warranty work without either
        // does exist, so this is a setting rather than an assumption.
        if ($settings->bool(SettingCatalog::TICKET_REQUIRE_SERIAL, true)) {
            if (trim((string)($data['serial_no'] ?? '')) === '') {
                $errors['serial_no'] = ['This company requires the unit serial number at intake.'];
            }
        }

        if ($settings->bool(SettingCatalog::TICKET_REQUIRE_BILL_DATE, true)) {
            if (trim((string)($data['purchase_date'] ?? '')) === '') {
                $errors['purchase_date'] = ['This company requires the purchase date at intake.'];
            }
        }

        return $errors;
    }

    /**
     * Some symptoms cannot be assessed without a video from the customer.
     *
     * Driven by the symptom master list, which is itself per-company — so a
     * company that does not accept video evidence simply never flags one.
     *
     * @param array<string, mixed> $data
     */
    private function videoProofRequired(array $data): bool
    {
        if (isset($data['video_proof_required'])) {
            return (bool)$data['video_proof_required'];
        }

        $symptomId = (int)($data['symptom_id'] ?? 0);
        if ($symptomId <= 0) {
            return false;
        }

        $symptom = $this->fetchTable('Symptoms')->find()
            ->select(['requires_video_proof'])
            ->where(['id' => $symptomId])
            ->disableHydration()
            ->first();

        return (bool)($symptom['requires_video_proof'] ?? false);
    }

    /**
     * Mark a ticket that is the same customer coming back.
     *
     * The window is a company term (clause 7 gives Dianora 90 days), so a
     * repeat is defined by that company's agreement rather than by a
     * constant. Matching is on customer plus unit: the same customer with a
     * different television is a new job, not a repeat.
     */
    private function flagRepeatComplaint(object $ticket, int $vendorId, string $onDate): void
    {
        try {
            $terms = $this->rates->agreementTerms($vendorId, $onDate);
        } catch (RecordNotFoundException) {
            return;
        }

        $since = (new DateTime($ticket->received_at))
            ->subDays($terms->repeatComplaintWindowDays)
            ->format('Y-m-d H:i:s');

        $conditions = [
            'vendor_id' => $vendorId,
            'customer_id' => $ticket->customer_id,
            'id !=' => $ticket->id,
            'closed_at >=' => $since,
            'status' => 'closed',
        ];

        // Serial number is the reliable key. Without one, fall back to the
        // model, which is weaker but better than treating every visit to a
        // repeat customer as unrelated.
        if (!empty($ticket->serial_no)) {
            $conditions['serial_no'] = $ticket->serial_no;
        } elseif (!empty($ticket->model_no)) {
            $conditions['model_no'] = $ticket->model_no;
        } else {
            return;
        }

        $previous = $this->fetchTable('Tickets')->find()
            ->select(['id'])
            ->where($conditions)
            ->orderByDesc('closed_at')
            ->disableHydration()
            ->first();

        if ($previous === null) {
            return;
        }

        $this->fetchTable('Tickets')->updateAll(
            ['is_repeat' => true, 'parent_ticket_id' => (int)$previous['id']],
            ['id' => $ticket->id],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return int|array<string, list<string>>  customer id, or field errors
     */
    private function resolveCustomer(array $data): int|array
    {
        if (!empty($data['customer_id'])) {
            return (int)$data['customer_id'];
        }

        $customerData = $data['customer'] ?? null;
        if (!is_array($customerData)) {
            return ['customer' => ['Provide customer details or an existing customer_id.']];
        }

        $customers = $this->fetchTable('Customers');
        $phone = trim((string)($customerData['phone'] ?? ''));

        // Phone is the identity that actually repeats in this trade, and
        // matching on it is what makes repeat-complaint detection work at
        // all — a second row for the same person hides the history.
        if ($phone !== '') {
            $existing = $customers->find()->where(['phone' => $phone])->first();
            if ($existing !== null) {
                $customers->saveOrFail($customers->patchEntity($existing, $customerData));

                return (int)$existing->id;
            }
        }

        $customer = $customers->newEntity($customerData + ['source' => 'desk']);
        if (!$customers->save($customer)) {
            return ['customer' => ['The customer record could not be saved.']];
        }

        return (int)$customer->id;
    }

    // -----------------------------------------------------------------
    // assignment
    // -----------------------------------------------------------------

    /**
     * Put a technician on a job.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, list<string>>}
     */
    public function assign(int $ticketId, int $technicianId, ?int $actorUserId = null, bool $force = false): array
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId, contain: ['JobTypes']);

        $technician = $this->fetchTable('Technicians')->find()
            ->where(['id' => $technicianId])
            ->first();

        if ($technician === null || !$technician->is_active) {
            return ['ok' => false, 'errors' => ['technician_id' => ['That technician is not active.']]];
        }

        $errors = [];

        // Skills gate assignment because panel replacement is not something
        // every technician can do, and finding that out on site costs a
        // second visit — which is unbillable and eats the SLA window.
        $skills = $technician->skills;
        if (is_string($skills)) {
            $skills = json_decode($skills, true);
        }
        $jobTypeCode = $ticket->job_type?->code;

        if (is_array($skills) && $skills !== [] && $jobTypeCode !== null) {
            if (!in_array($jobTypeCode, $skills, true)) {
                $errors['technician_id'] = [sprintf(
                    '%s is not marked as able to do %s work.',
                    $technician->name,
                    $jobTypeCode,
                )];
            }
        }

        $openCount = $tickets->find()
            ->where([
                'assigned_technician_id' => $technicianId,
                'status NOT IN' => self::TERMINAL_STATUSES,
                'id !=' => $ticketId,
            ])
            ->count();

        if ($openCount >= (int)$technician->max_open_tickets) {
            $errors['technician_id'] = array_merge($errors['technician_id'] ?? [], [sprintf(
                '%s already has %d open jobs, which is their limit.',
                $technician->name,
                $openCount,
            )]);
        }

        // Both checks are advisory: a dispatcher who knows the technician
        // is finishing early, or is the only one nearby, can override. What
        // matters is that the override is deliberate and recorded.
        if ($errors !== [] && !$force) {
            return ['ok' => false, 'errors' => $errors];
        }

        $from = $ticket->status;
        $to = in_array($from, ['new', 'rejected'], true) ? 'assigned' : $from;

        if ($to !== $from) {
            $this->assertCanTransition($from, $to);
        }

        $ticket->set('assigned_technician_id', $technicianId);
        $ticket->set('assigned_at', DateTime::now());
        $ticket->set('assigned_by_user_id', $actorUserId);
        $ticket->set('status', $to);

        $tickets->saveOrFail($ticket);

        $this->logEvent($ticketId, 'assigned', $from, $to, $actorUserId, sprintf(
            'Assigned to %s.%s',
            $technician->name,
            $errors !== [] ? ' Overridden by the dispatcher.' : '',
        ), [
            'technician_id' => $technicianId,
            'overridden' => $errors !== [],
            'override_warnings' => $errors,
        ]);

        return ['ok' => true];
    }

    /**
     * Record the first contact with the customer, which stops the contact
     * clock.
     */
    public function recordContact(int $ticketId, ?int $actorUserId = null, ?string $notes = null): void
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);

        $from = $ticket->status;

        // Only the first contact counts. Overwriting it on the third call
        // attempt would quietly repair a breached window.
        if ($ticket->first_contact_at === null) {
            $ticket->set('first_contact_at', DateTime::now());
        }

        if (in_array($from, ['new', 'assigned'], true)) {
            $ticket->set('status', 'contacted');
        }

        $tickets->saveOrFail($ticket);

        $this->logEvent(
            $ticketId,
            'contacted',
            $from,
            $ticket->status,
            $actorUserId,
            $notes ?? 'Customer contacted.',
        );
    }

    // -----------------------------------------------------------------
    // holds
    // -----------------------------------------------------------------

    /**
     * Stop the clock.
     *
     * @return array{ok: true, hold_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function startHold(
        int $ticketId,
        int $holdReasonId,
        ?int $actorUserId = null,
        ?string $notes = null,
    ): array {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);

        $open = $this->fetchTable('TicketHolds')->find()
            ->where(['ticket_id' => $ticketId, 'ended_at IS' => null])
            ->count();

        if ($open > 0) {
            return ['ok' => false, 'errors' => ['hold' => ['This ticket is already on hold.']]];
        }

        $reason = $this->fetchTable('HoldReasons')->find()
            ->where([
                'id' => $holdReasonId,
                // A company may only use a reason from its own resolved
                // list — its own overrides plus the shared baseline.
                'OR' => ['vendor_id IS' => null, 'vendor_id' => $ticket->vendor_id],
            ])
            ->first();

        if ($reason === null) {
            return ['ok' => false, 'errors' => ['hold_reason_id' => ['Not a hold reason this company recognises.']]];
        }

        $from = $ticket->status;
        $now = DateTime::now();

        $holds = $this->fetchTable('TicketHolds');
        $hold = $holds->newEntity([
            'ticket_id' => $ticketId,
            'hold_reason_id' => $holdReasonId,
            'reason_code' => $reason->code,
            'notes' => $notes,
            'started_at' => $now,
            'started_by_user_id' => $actorUserId,
        ]);
        $holds->saveOrFail($hold);

        // Only a reason flagged as pausing actually stops the clock. Being
        // short-staffed is a real delay and a real hold, but it is ours,
        // and marking it as excusable is how an agreement gets terminated.
        if ($reason->pauses_sla) {
            $this->assertCanTransition($from, 'on_hold');
            $ticket->set('status', 'on_hold');
            $tickets->saveOrFail($ticket);
        }

        $this->logEvent($ticketId, 'hold_started', $from, $ticket->status, $actorUserId, sprintf(
            'On hold: %s.%s',
            $reason->name,
            $reason->pauses_sla ? '' : ' This reason does not stop the SLA clock.',
        ), [
            'hold_id' => (int)$hold->id,
            'reason_code' => $reason->code,
            'pauses_sla' => (bool)$reason->pauses_sla,
            'requires_vendor_notice' => (bool)$reason->requires_vendor_notice,
        ]);

        return ['ok' => true, 'hold_id' => (int)$hold->id];
    }

    /**
     * Restart the clock and bank the paused minutes.
     */
    public function endHold(int $ticketId, ?int $actorUserId = null, ?string $resumeStatus = null): array
    {
        $holds = $this->fetchTable('TicketHolds');

        $hold = $holds->find()
            ->where(['ticket_id' => $ticketId, 'ended_at IS' => null])
            ->orderByDesc('started_at')
            ->first();

        if ($hold === null) {
            return ['ok' => false, 'errors' => ['hold' => ['This ticket is not on hold.']]];
        }

        $now = DateTime::now();
        $minutes = max(0, (int)round(($now->getTimestamp() - $hold->started_at->getTimestamp()) / 60));

        $hold->set('ended_at', $now);
        $hold->set('paused_minutes', $minutes);
        $hold->set('ended_by_user_id', $actorUserId);
        $holds->saveOrFail($hold);

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);
        $from = $ticket->status;

        $reason = $hold->hold_reason_id !== null
            ? $this->fetchTable('HoldReasons')->find()->where(['id' => $hold->hold_reason_id])->first()
            : null;

        // The accumulated total is a cached sum for the board and for
        // reports. The authoritative pause accounting is still done per
        // window by SlaCalculator, which only subtracts a hold from a
        // window it actually overlaps.
        if ($reason === null || $reason->pauses_sla) {
            $ticket->set('sla_paused_minutes', (int)$ticket->sla_paused_minutes + $minutes);
        }

        if ($from === 'on_hold') {
            $resumeStatus ??= $ticket->visited_at !== null ? 'in_progress' : 'assigned';
            $this->assertCanTransition($from, $resumeStatus);
            $ticket->set('status', $resumeStatus);
        }

        $tickets->saveOrFail($ticket);

        $this->logEvent($ticketId, 'hold_ended', $from, $ticket->status, $actorUserId, sprintf(
            'Hold released after %d minutes.',
            $minutes,
        ), ['hold_id' => (int)$hold->id, 'paused_minutes' => $minutes]);

        return ['ok' => true, 'paused_minutes' => $minutes];
    }

    // -----------------------------------------------------------------
    // comments
    // -----------------------------------------------------------------

    /**
     * Add a free-text note to a ticket.
     *
     * Comments live on `ticket_events` alongside the status changes rather
     * than in a table of their own, and that is the point: "customer says
     * the fault is intermittent" and "hold started, spare on order" are the
     * same story, and splitting them across two tables means nobody ever
     * reads them in order. It also inherits the trail's guarantee — the
     * table is append-only, so a note cannot be quietly rewritten after a
     * dispute starts.
     *
     * `description` on that table is 255 characters and `logEvent` truncates
     * to fit, so the full text is written to the payload and the column
     * carries the preview the timeline renders.
     *
     * Closed tickets accept comments. A query arriving three weeks after
     * closure is exactly when a note matters most, and refusing it would
     * only move the conversation to WhatsApp.
     *
     * @return array{ok: true, event_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function comment(
        int $ticketId,
        string $body,
        ?int $actorUserId = null,
        string $visibility = 'internal',
        ?float $latitude = null,
        ?float $longitude = null,
    ): array {
        $body = trim($body);

        if ($body === '') {
            return ['ok' => false, 'errors' => ['body' => ['Write something first.']]];
        }

        if (mb_strlen($body) > 5000) {
            return ['ok' => false, 'errors' => ['body' => ['Keep a comment under 5000 characters.']]];
        }

        // Internal by default. A note shared with the company is a statement
        // we can be held to, so it has to be chosen rather than defaulted
        // into.
        if (!in_array($visibility, ['internal', 'vendor', 'customer'], true)) {
            return ['ok' => false, 'errors' => ['visibility' => [
                'Use internal, vendor or customer.',
            ]]];
        }

        $tickets = $this->fetchTable('Tickets');
        if ($tickets->find()->where(['id' => $ticketId])->count() === 0) {
            return ['ok' => false, 'errors' => ['ticket_id' => ['No such ticket.']]];
        }

        $events = $this->fetchTable('TicketEvents');
        $event = $events->newEntity([
            'ticket_id' => $ticketId,
            'event_type' => 'comment',
            'actor_user_id' => $actorUserId,
            'description' => mb_substr($body, 0, 255),
            'payload' => ['body' => $body, 'visibility' => $visibility],
            'occurred_at' => DateTime::now(),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        if (!$events->save($event)) {
            return ['ok' => false, 'errors' => $event->getErrors()];
        }

        return ['ok' => true, 'event_id' => (int)$event->id];
    }

    /**
     * A ticket's comments, newest first.
     *
     * Separate from the timeline because the desk reads them differently:
     * the timeline answers "what happened to this job", the comment list
     * answers "what has anyone said about it". Same rows, two questions.
     *
     * @return list<array<string, mixed>>
     */
    public function comments(int $ticketId, ?string $visibility = null): array
    {
        $rows = $this->fetchTable('TicketEvents')->find()
            ->select([
                'TicketEvents.id',
                'TicketEvents.description',
                'TicketEvents.payload',
                'TicketEvents.occurred_at',
                'TicketEvents.actor_user_id',
                'actor_name' => 'ActorUsers.name',
            ])
            ->leftJoinWith('ActorUsers')
            ->where([
                'TicketEvents.ticket_id' => $ticketId,
                'TicketEvents.event_type' => 'comment',
            ])
            ->orderByDesc('TicketEvents.occurred_at')
            ->orderByDesc('TicketEvents.id')
            ->disableHydration()
            ->all()
            ->toList();

        $comments = [];
        foreach ($rows as $row) {
            $payload = $row['payload'];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            $payload = is_array($payload) ? $payload : [];

            $rowVisibility = (string)($payload['visibility'] ?? 'internal');

            if ($visibility !== null && $rowVisibility !== $visibility) {
                continue;
            }

            $comments[] = [
                'id' => (int)$row['id'],
                // The payload holds the whole comment; description is only
                // the 255-character preview the timeline shows.
                'body' => (string)($payload['body'] ?? $row['description'] ?? ''),
                'visibility' => $rowVisibility,
                'author_id' => $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null,
                'author_name' => $row['actor_name'] ?? 'System',
                'occurred_at' => $row['occurred_at'],
            ];
        }

        return $comments;
    }

    // -----------------------------------------------------------------
    // status
    // -----------------------------------------------------------------

    /**
     * Move a ticket to a new status.
     */
    public function transition(
        int $ticketId,
        string $to,
        ?int $actorUserId = null,
        ?string $notes = null,
        array $payload = [],
    ): void {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);
        $from = $ticket->status;

        $this->assertCanTransition($from, $to);

        $ticket->set('status', $to);

        if ($to === 'visited' && $ticket->visited_at === null) {
            $ticket->set('visited_at', DateTime::now());
        }

        if ($to === 'cancelled') {
            $ticket->set('cancelled_at', DateTime::now());
            $ticket->set('cancellation_reason', $notes);
        }

        if ($to === 'reopened') {
            $ticket->set('reopened_count', (int)$ticket->reopened_count + 1);
            // Deliberately leaves closed_at and the frozen charges alone.
            // The original closure happened and was invoiced; a reopen is a
            // new stretch of work on the same ticket, not a retraction.
        }

        $tickets->saveOrFail($ticket);

        // A move is worth reading back on its own. `$notes` is optional and
        // the operations screen sends none, so describe the move ourselves
        // and keep the note as the reason when there is one.
        $description = sprintf(
            'Status moved from %s to %s.',
            str_replace('_', ' ', $from),
            str_replace('_', ' ', $to),
        );
        if ($notes !== null && trim($notes) !== '') {
            $description .= ' ' . trim($notes);
        }

        $this->logEvent($ticketId, 'status_changed', $from, $to, $actorUserId, $description, $payload);
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    private function assertCanTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        if (!$this->canTransition($from, $to)) {
            throw new TicketTransitionException($from, $to, self::TRANSITIONS[$from] ?? []);
        }
    }

    // -----------------------------------------------------------------
    // events
    // -----------------------------------------------------------------

    /**
     * Append to the ticket's history.
     *
     * `ticket_events` is append-only. Nothing in this class updates or
     * deletes a row from it, because the table is what we put in front of a
     * company disputing an SLA breach — and a history that can be edited
     * proves nothing.
     *
     * @param array<string, mixed> $payload
     */
    public function logEvent(
        int $ticketId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $actorUserId,
        ?string $description = null,
        array $payload = [],
        ?float $latitude = null,
        ?float $longitude = null,
    ): void {
        $events = $this->fetchTable('TicketEvents');

        $events->saveOrFail($events->newEntity([
            'ticket_id' => $ticketId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'payload' => $payload !== [] ? $payload : null,
            'occurred_at' => DateTime::now(),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]));
    }
}
