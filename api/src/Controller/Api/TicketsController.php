<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Exception\TicketTransitionException;
use App\Service\CompanyConfigRepository;
use App\Service\EvidenceService;
use App\Service\SpareService;
use App\Service\TicketAdjustmentService;
use App\Service\TicketClosureService;
use App\Service\TicketWorkflow;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * The ticket lifecycle, over HTTP.
 *
 * Deliberately thin. Every action here resolves ids, hands the request to
 * a service and shapes the answer — none of them decide anything. That
 * split exists because the rules being applied are not presentational:
 * whether a ticket may be closed, what a hold does to the SLA clock, what
 * a job earned. A controller that reimplemented any of it would drift from
 * the version the field app and the invoice run use, and the divergence
 * would only show up in the money.
 *
 * What is valid at each step is per-company throughout — the required
 * evidence, the SLA windows, the vocabulary on the dropdowns. None of it
 * is hardcoded here; it is read from the company the ticket belongs to.
 */
class TicketsController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated([
            'index', 'options', 'view', 'add', 'edit', 'assign', 'contact',
            'checkin', 'checkout', 'sendOtp', 'verifyOtp', 'addAttachment',
            'addSpare', 'removeSpare', 'returnSpare', 'hold', 'release', 'close', 'preview',
            'charges', 'addAdjustment', 'removeCharge', 'comments', 'addComment',
            'statusOptions', 'changeStatus',
            'attachments', 'serveAttachment', 'dashboardStats',
        ]);
    }

    /**
     * GET /api/tickets/dashboard-stats
     */
    public function dashboardStats(): Response
    {
        $ticketsTable = $this->fetchTable('Tickets');

        $statusCountsRaw = $ticketsTable->find()
            ->select(['status', 'count' => 'COUNT(*)'])
            ->groupBy(['status'])
            ->disableHydration()
            ->all();

        // Seeded from the lifecycle itself rather than a hand-written list,
        // which had drifted: it carried a "received" status that does not
        // exist and omitted half the ones that do, so those columns read
        // zero however many tickets were sitting in them.
        $byStatus = array_fill_keys(
            array_merge(...array_values(TicketWorkflow::STATUS_BUCKETS)),
            0,
        );
        foreach ($statusCountsRaw as $row) {
            $byStatus[(string)$row['status']] = (int)$row['count'];
        }

        $totalTickets = array_sum($byStatus);

        // Every terminal status, not just closed and cancelled — a rejected
        // job was being counted as open, which overstated the board.
        $openTickets = $totalTickets - array_sum(array_intersect_key(
            $byStatus,
            array_flip(TicketWorkflow::TERMINAL_STATUSES),
        ));
        $unassignedTickets = $ticketsTable->find()->where(['assigned_technician_id IS' => null, 'status !=' => 'closed'])->count();

        $todayStr = date('Y-m-d');
        $closedToday = $ticketsTable->find()
            ->where(['status' => 'closed', 'closed_at >=' => $todayStr . ' 00:00:00'])
            ->count();

        // Grouped by status as well as company, in one pass. A total per
        // company answers "who sends us work"; the split answers "who is
        // waiting on us", which is the one that changes what the desk does
        // this morning.
        $companyCountsRaw = $ticketsTable->find()
            ->select([
                'company_id',
                'company_name' => 'Companies.name',
                'company_code' => 'Companies.code',
                'status',
                'count' => 'COUNT(*)',
            ])
            ->join(['Companies' => ['table' => 'companies', 'type' => 'INNER', 'conditions' => 'Companies.id = Tickets.company_id']])
            ->groupBy(['company_id', 'Companies.name', 'Companies.code', 'status'])
            ->disableHydration()
            ->all();

        $emptyBuckets = array_fill_keys(array_keys(TicketWorkflow::STATUS_BUCKETS), 0);
        $byCompany = [];

        foreach ($companyCountsRaw as $row) {
            $companyId = (int)$row['company_id'];
            $count = (int)$row['count'];

            $byCompany[$companyId] ??= [
                'company_id' => $companyId,
                'company_name' => (string)$row['company_name'],
                'company_code' => (string)$row['company_code'],
                'count' => 0,
                'open' => 0,
            ] + $emptyBuckets;

            $byCompany[$companyId][TicketWorkflow::statusBucket((string)$row['status'])] += $count;
            $byCompany[$companyId]['count'] += $count;
        }

        foreach ($byCompany as &$company) {
            // What still owes work — the figure the board is actually run
            // on. Derived rather than counted separately so it can never
            // disagree with the columns printed beside it.
            $company['open'] = $company['count'] - $company['closed'] - $company['cancelled'];
        }
        unset($company);

        // Busiest first: the company with the most open work is the one the
        // desk needs at the top, not whichever was onboarded first.
        $byCompany = array_values($byCompany);
        usort($byCompany, static fn (array $a, array $b): int => $b['open'] <=> $a['open']
            ?: $b['count'] <=> $a['count']);

        $pendingSpares = $this->fetchTable('TicketSpares')->find()
            ->where(['is_defective_return' => true, 'defective_returned_at IS' => null])
            ->count();

        $recentEvents = $this->fetchTable('TicketEvents')->find()
            ->select(['TicketEvents.id', 'TicketEvents.ticket_id', 'TicketEvents.event_type', 'TicketEvents.description', 'TicketEvents.occurred_at', 'ticket_no' => 'Tickets.ticket_no'])
            ->join(['Tickets' => ['table' => 'tickets', 'type' => 'INNER', 'conditions' => 'Tickets.id = TicketEvents.ticket_id']])
            ->orderByDesc('TicketEvents.occurred_at')
            ->limit(8)
            ->disableHydration()
            ->all();

        return $this->respond([
            'total_tickets' => $totalTickets,
            'open_tickets' => $openTickets,
            'unassigned_tickets' => $unassignedTickets,
            'closed_today' => $closedToday,
            'pending_spares' => $pendingSpares,
            'by_status' => $byStatus,
            'by_company' => $byCompany,
            'recent_events' => $recentEvents,
        ]);
    }

    /**
     * GET /api/tickets
     */
    public function index(): Response
    {
        $query = $this->fetchTable('Tickets')->find()
            ->contain([
                'Customers', 'Companies', 'ServiceCenters', 'JobTypes',
                'AssignedTechnicians', 'Brands', 'ProductCategories',
            ]);

        $search = trim((string)$this->request->getQuery('q', ''));
        if ($search !== '') {
            $query->where(['OR' => [
                'Tickets.ticket_no LIKE' => '%' . $search . '%',
                'Tickets.company_ticket_ref LIKE' => '%' . $search . '%',
                'Customers.name LIKE' => '%' . $search . '%',
                'Customers.phone LIKE' => '%' . $search . '%',
                'Tickets.model_no LIKE' => '%' . $search . '%',
                'Tickets.serial_no LIKE' => '%' . $search . '%',
            ]]);
        }

        // `active` and `inactive` are groups rather than statuses: the desk
        // works from "what still owes work", and a list that has to be
        // narrowed one status at a time buries a live job among the closed
        // ones. A real status name still filters to exactly that status.
        $status = (string)$this->request->getQuery('status', '');
        if ($status === 'active') {
            $query->where(['Tickets.status NOT IN' => TicketWorkflow::TERMINAL_STATUSES]);
        } elseif ($status === 'inactive') {
            $query->where(['Tickets.status IN' => TicketWorkflow::TERMINAL_STATUSES]);
        } elseif ($status !== '') {
            $query->where(['Tickets.status' => $status]);
        }

        foreach ([
            'priority' => 'Tickets.priority',
            'company_id' => 'Tickets.company_id',
            'technician_id' => 'Tickets.assigned_technician_id',
            'service_center_id' => 'Tickets.service_center_id',
        ] as $param => $column) {
            $value = $this->request->getQuery($param);
            if ($value !== null && $value !== '') {
                $query->where([$column => $value]);
            }
        }

        // The board's real question is "what is about to breach", which is
        // an ordering by deadline rather than by age.
        $order = (string)$this->request->getQuery('order', 'created');
        $query->orderBy($order === 'due'
            ? ['Tickets.close_due_at' => 'ASC']
            : ['Tickets.created' => 'DESC']);

        $limit = min(100, max(1, (int)$this->request->getQuery('limit', 20)));
        $page = max(1, (int)$this->request->getQuery('page', 1));

        $total = $query->count();

        return $this->respond($query->limit($limit)->page($page)->all(), [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / max(1, $limit)),
        ]);
    }

    /**
     * GET /api/tickets/options
     *
     * The dropdowns for the intake form.
     *
     * Pass `company_id` and the master lists come back as that company sees
     * them — its own entries shadowing the shared baseline. Without it the
     * shared baseline alone is returned, which is right for a form where
     * the company has not been picked yet and wrong for anything else: an
     * unfiltered list offers the desk symptoms and hold reasons another
     * company's agreement recognises and this one's does not.
     */
    public function options(): Response
    {
        $companyId = (int)$this->request->getQuery('company_id', 0);

        $companies = $this->fetchTable('Companies')->find()
            ->select(['id', 'code', 'name'])
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $serviceCenters = $this->fetchTable('ServiceCenters')->find()
            ->select(['id', 'code', 'name'])
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $brands = $this->fetchTable('Brands')->find()
            ->select(['id', 'code', 'name', 'company_id'])
            ->where($companyId > 0 ? ['company_id' => $companyId] : [])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $districts = $this->fetchTable('Districts')->find()
            ->select(['id', 'code', 'name'])
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $states = $this->fetchTable('States')->find()
            ->select(['id', 'code', 'name'])
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $technicians = $this->fetchTable('Technicians')->find()
            ->select(['id', 'code', 'name', 'phone', 'service_center_id', 'skills'])
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $config = new CompanyConfigRepository();
        $lists = $config->masterLists($companyId);

        return $this->respond([
            'companies' => $companies,
            'service_centers' => $serviceCenters,
            'brands' => $brands,
            'districts' => $districts,
            'states' => $states,
            'technicians' => $technicians,
            'job_types' => $lists['job_types'],
            'product_categories' => $lists['product_categories'],
            'symptoms' => $lists['symptoms'],
            'resolutions' => $lists['resolutions'],
            'hold_reasons' => $lists['hold_reasons'],
            'technician_expense_types' => $lists['technician_expense_types'],
            'warranty_scopes' => [
                ['code' => 'in_warranty', 'name' => 'In Warranty'],
                ['code' => 'out_of_warranty', 'name' => 'Out of Warranty'],
                ['code' => 'not_applicable', 'name' => 'Not Applicable'],
                ['code' => 'unknown', 'name' => 'Unknown'],
            ],
            'priorities' => [
                ['code' => 'low', 'name' => 'Low'],
                ['code' => 'normal', 'name' => 'Normal'],
                ['code' => 'high', 'name' => 'High'],
                ['code' => 'urgent', 'name' => 'Urgent'],
            ],
            // What this company demands at intake and at closure, so the
            // form can mark the required fields rather than letting the
            // operator find out by being rejected. Resolved even with no
            // company picked yet — with company_id 0 this settles on the
            // platform layer, which is what seeds the form's defaults
            // (e.g. default district) before a company is chosen.
            'requirements' => $config->settings($companyId)->toArray(),
        ]);
    }

    /**
     * GET /api/tickets/{id}
     */
    public function view(?string $id = null): Response
    {
        $ticket = $this->findTicket($id);

        if ($ticket === null) {
            return $this->fail('not_found', 'Ticket not found.', 404);
        }

        return $this->respond($ticket);
    }

    /**
     * POST /api/tickets
     */
    public function add(): Response
    {
        $result = (new TicketWorkflow())->intake(
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The ticket could not be taken in.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket((string)$result['ticket_id']), [], 201);
    }

    /**
     * PUT /api/tickets/{id}
     */
    public function edit(?string $id = null): Response
    {
        $ticketId = $this->ticketId($id);
        $result = (new TicketWorkflow())->update(
            $ticketId,
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The ticket could not be updated.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket((string)$ticketId));
    }

    /**
     * POST /api/tickets/{id}/assign
     */
    public function assign(?string $id = null): Response
    {
        $result = (new TicketWorkflow())->assign(
            $this->ticketId($id),
            (int)$this->request->getData('technician_id'),
            $this->currentUserId(),
            // The dispatcher can overrule a skill or capacity warning; the
            // override is recorded on the ticket's history either way.
            (bool)$this->request->getData('force'),
        );

        if ($result['ok'] === false) {
            return $this->fail('assignment_refused', 'That technician cannot take this job.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/contact
     */
    public function contact(?string $id = null): Response
    {
        (new TicketWorkflow())->recordContact(
            $this->ticketId($id),
            $this->currentUserId(),
            $this->request->getData('notes'),
        );

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/hold
     */
    public function hold(?string $id = null): Response
    {
        try {
            $result = (new TicketWorkflow())->startHold(
                $this->ticketId($id),
                (int)$this->request->getData('hold_reason_id'),
                $this->currentUserId(),
                $this->request->getData('notes'),
            );
        } catch (TicketTransitionException $e) {
            return $this->fail('invalid_transition', $e->getMessage(), 409);
        }

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The ticket could not be put on hold.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/release
     */
    public function release(?string $id = null): Response
    {
        try {
            $result = (new TicketWorkflow())->endHold(
                $this->ticketId($id),
                $this->currentUserId(),
                $this->request->getData('resume_status'),
            );
        } catch (TicketTransitionException $e) {
            return $this->fail('invalid_transition', $e->getMessage(), 409);
        }

        if (($result['ok'] ?? false) === false) {
            return $this->fail('validation_error', 'The hold could not be released.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id), ['paused_minutes' => $result['paused_minutes']]);
    }

    // -----------------------------------------------------------------
    // evidence
    // -----------------------------------------------------------------

    /**
     * POST /api/tickets/{id}/checkin
     */
    public function checkin(?string $id = null): Response
    {
        $latitude = $this->request->getData('latitude');
        $longitude = $this->request->getData('longitude');

        // No defaulting to the office coordinates. A check-in that invents
        // a location is worse than no check-in at all — it is evidence
        // that says something untrue.
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return $this->fail('validation_error', 'A check-in needs real coordinates.', 422, [
                'latitude' => ['Required.'],
                'longitude' => ['Required.'],
            ]);
        }

        $result = (new EvidenceService())->checkIn(
            $this->ticketId($id),
            (float)$latitude,
            (float)$longitude,
            $this->request->getData('accuracy_m') !== null
                ? (int)$this->request->getData('accuracy_m')
                : null,
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'Could not check in.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id), [
            'distance_m' => $result['distance_m'],
            'within_tolerance' => $result['within_tolerance'],
        ]);
    }

    /**
     * POST /api/tickets/{id}/checkout
     */
    public function checkout(?string $id = null): Response
    {
        (new EvidenceService())->checkOut($this->ticketId($id), $this->currentUserId());

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/attachments
     *
     * Two shapes, one endpoint. A multipart body with a `file` part uploads
     * and stores the bytes; a JSON body records metadata for a file already
     * stored elsewhere. The second exists for an importer or a field app
     * that uploaded direct to object storage, and is the only reason the
     * endpoint is not multipart-only.
     */
    public function addAttachment(?string $id = null): Response
    {
        $ticketId = $this->ticketId($id);
        $kind = (string)$this->request->getData('kind', 'other');
        $evidence = new EvidenceService();

        $file = $this->request->getUploadedFile('file');

        $result = $file !== null
            ? $evidence->upload($ticketId, $kind, $file, $this->currentUserId())
            : $evidence->attach($ticketId, $kind, (array)$this->request->getData(), $this->currentUserId());

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The evidence could not be attached.', 422, $result['errors']);
        }

        return $this->respond(['attachment_id' => $result['attachment_id']], [], 201);
    }

    /**
     * GET /api/tickets/{id}/attachments
     *
     * What is already attached, so the desk can see whether the closure
     * requirement is met before pressing close and being refused.
     */
    public function attachments(?string $id = null): Response
    {
        $rows = $this->fetchTable('TicketAttachments')->find()
            ->select([
                'TicketAttachments.id',
                'TicketAttachments.kind',
                'TicketAttachments.original_name',
                'TicketAttachments.mime_type',
                'TicketAttachments.size_bytes',
                'TicketAttachments.uploaded_at',
            ])
            ->where(['TicketAttachments.ticket_id' => $this->ticketId($id)])
            ->orderByDesc('TicketAttachments.uploaded_at')
            ->disableHydration()
            ->all()
            ->toList();

        return $this->respond(array_map(static fn(array $row): array => $row + [
            // Served through the app rather than linked into webroot, so
            // access stays something we can put a rule on later.
            'url' => sprintf('/api/attachments/%d', $row['id']),
        ], $rows));
    }

    /**
     * GET /api/attachments/{attachment_id}
     *
     * Streams the stored file.
     *
     * The path comes from our own column and is confined to the uploads
     * directory before anything is opened. A stored path is not attacker
     * input today, but "not today" is how a traversal bug gets written, and
     * the check costs one comparison.
     */
    public function serveAttachment(?string $attachmentId = null): Response
    {
        $id = (int)$this->routeParam('attachment_id', $attachmentId);

        $row = $this->fetchTable('TicketAttachments')->find()
            ->select(['storage_path', 'mime_type', 'original_name'])
            ->where(['id' => $id])
            ->disableHydration()
            ->first();

        if ($row === null) {
            return $this->fail('not_found', 'No such attachment.', 404);
        }

        $base = realpath(WWW_ROOT . 'uploads');
        $path = realpath(WWW_ROOT . (string)$row['storage_path']);

        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return $this->fail('not_found', 'That file is no longer on disk.', 404);
        }

        return $this->response
            ->withType((string)($row['mime_type'] ?? 'application/octet-stream'))
            ->withHeader('Content-Disposition', sprintf(
                'inline; filename="%s"',
                addslashes((string)($row['original_name'] ?? basename($path))),
            ))
            ->withStringBody((string)file_get_contents($path));
    }

    /**
     * POST /api/tickets/{id}/otp/send
     */
    public function sendOtp(?string $id = null): Response
    {
        // The plaintext code only comes back where there is no SMS gateway
        // to send it. In any other environment it would defeat the point of
        // asking the customer at all.
        $returnCode = \Cake\Core\Configure::read('debug') === true;

        $result = (new EvidenceService())->issueClosureOtp(
            $this->ticketId($id),
            $this->currentUserId(),
            $returnCode,
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'No code could be sent.', 422, $result['errors']);
        }

        return $this->respond([
            'sent_to' => $result['sent_to'],
            'expires_in_minutes' => $result['expires_in_minutes'],
            'otp_demo_code' => $result['code'],
        ]);
    }

    /**
     * POST /api/tickets/{id}/otp/verify
     */
    public function verifyOtp(?string $id = null): Response
    {
        $result = (new EvidenceService())->verifyClosureOtp(
            $this->ticketId($id),
            (string)$this->request->getData('otp'),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('otp_rejected', 'That code was not accepted.', 422, $result['errors']);
        }

        return $this->respond(['verified' => true]);
    }

    // -----------------------------------------------------------------
    // spares
    // -----------------------------------------------------------------

    /**
     * POST /api/tickets/{id}/spares
     */
    public function addSpare(?string $id = null): Response
    {
        $result = (new SpareService())->consumeOnTicket(
            $this->ticketId($id),
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The part could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['ticket_spare_id' => $result['ticket_spare_id']], [], 201);
    }

    /**
     * DELETE /api/tickets/{id}/spares/{spareId}
     *
     * Withdraw a part recorded in error. The service puts the stock back
     * where it came from and refuses once the money is decided; replacing
     * a part is this followed by recording the right one.
     */
    public function removeSpare(?string $id = null, ?string $spareId = null): Response
    {
        $result = (new SpareService())->removeFromTicket(
            (int)$this->routeParam('spare_id', $spareId),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The part could not be removed.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/spares/{spareId}/return
     */
    public function returnSpare(?string $id = null, ?string $spareId = null): Response
    {
        $result = (new SpareService())->returnDefective(
            // The route element arrives as a request param, not a passed
            // argument — reading it positionally addressed spare 0.
            (int)$this->routeParam('spare_id', $spareId),
            $this->request->getData('reference'),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The return could not be recorded.', 422, $result['errors']);
        }

        return $this->respond($this->findTicket($id));
    }

    // -----------------------------------------------------------------
    // status
    // -----------------------------------------------------------------

    /**
     * Statuses a person is allowed to set directly.
     *
     * `visited` is here by choice. Geo Check-In still exists and still
     * records coordinates and the distance from the customer's address, but
     * a desk that knows the technician attended should not have to fake a
     * GPS reading to say so. Setting it from here stamps `visited_at` the
     * same way, so the visit SLA and the closure gate both still work — the
     * difference is only that no coordinates were captured, and the event
     * trail records which route was taken.
     *
     * Two are deliberately absent:
     *
     *   closed   the closure gates — evidence, resolution, pricing — all
     *            hang off the close endpoint. A status edit would skip them
     *            and freeze nothing, leaving a "closed" job with no ledger.
     *   reopened the transition map allows reopened -> closed, but close()
     *            refuses a ticket whose charges are frozen, so reopening one
     *            today produces a ticket that can never be closed again.
     *            Withheld until that path works rather than shipping a trap.
     *
     * @var list<string>
     */
    private const SETTABLE_STATUSES = [
        'assigned', 'contacted', 'scheduled', 'visited', 'in_progress',
        'awaiting_parts', 'cancelled', 'rejected',
    ];

    /**
     * GET /api/tickets/{id}/status
     *
     * Where the ticket is and where it can legally go next, so the client
     * offers the moves that exist instead of guessing and being refused.
     */
    public function statusOptions(?string $id = null): Response
    {
        $workflow = new TicketWorkflow();
        $ticket = $this->fetchTable('Tickets')->find()
            ->select(['id', 'status'])
            ->where(['id' => $this->ticketId($id)])
            ->disableHydration()
            ->first();

        if ($ticket === null) {
            return $this->fail('not_found', 'No such ticket.', 404);
        }

        $current = (string)$ticket['status'];

        $available = array_values(array_filter(
            self::SETTABLE_STATUSES,
            static fn(string $to): bool => $to !== $current
                && (new TicketWorkflow())->canTransition($current, $to),
        ));

        return $this->respond([
            'status' => $current,
            'available' => $available,
            // Named so the UI can explain a greyed-out option rather than
            // silently omitting it and looking broken.
            'action_only' => array_values(array_filter(
                ['closed', 'on_hold', 'reopened'],
                static fn(string $to): bool => $workflow->canTransition($current, $to),
            )),
        ]);
    }

    /**
     * POST /api/tickets/{id}/status
     *
     * { "to": "cancelled", "notes": "Customer bought a new set" }
     */
    public function changeStatus(?string $id = null): Response
    {
        $ticketId = $this->ticketId($id);
        $to = trim((string)$this->request->getData('to'));
        $notes = $this->request->getData('notes');

        if (!in_array($to, self::SETTABLE_STATUSES, true)) {
            return $this->fail(
                'validation_error',
                'That status cannot be set directly.',
                422,
                ['to' => [sprintf(
                    'Choose one of: %s. Closing a ticket is its own action, '
                    . 'and so is putting one on hold.',
                    implode(', ', self::SETTABLE_STATUSES),
                )]],
            );
        }

        // Cancelling writes the reason onto the ticket, where it is read
        // back later as the explanation for a job that earned nothing.
        if ($to === 'cancelled' && trim((string)$notes) === '') {
            return $this->fail('validation_error', 'A cancellation needs a reason.', 422, [
                'notes' => ['Say why the job is being cancelled.'],
            ]);
        }

        try {
            (new TicketWorkflow())->transition(
                $ticketId,
                $to,
                $this->currentUserId(),
                $notes,
                // Marks a visit recorded from the desk rather than from a
                // phone on site. Both set visited_at; only one has GPS
                // behind it, and a dispute six months out needs to know.
                ['source' => 'desk', 'has_coordinates' => false],
            );
        } catch (RecordNotFoundException) {
            return $this->fail('not_found', 'No such ticket.', 404);
        } catch (TicketTransitionException $e) {
            // The message names the legal moves, which is what the client
            // needs to correct itself.
            return $this->fail('invalid_transition', $e->getMessage(), 409, [], [
                'from' => $e->fromStatus,
                'to' => $e->toStatus,
                'allowed' => $e->allowed,
            ]);
        }

        return $this->respond($this->findTicket($id));
    }

    // -----------------------------------------------------------------
    // closure
    // -----------------------------------------------------------------

    /**
     * GET /api/tickets/{id}/preview
     *
     * What the job would earn if it closed now, plus whatever evidence is
     * still outstanding. The same engine that produces the frozen ledger,
     * so a figure quoted to the desk and the invoice raised later cannot
     * disagree.
     */
    public function preview(?string $id = null): Response
    {
        $ticketId = $this->ticketId($id);

        // A proposed manual amount can be tried here first. Same parameters
        // the close call takes, so what the desk is shown is what closing
        // will actually write rather than an approximation of it.
        $proposal = array_filter([
            'override_base_amount' => $this->request->getQuery('override_base_amount'),
            'override_reason' => $this->request->getQuery('override_reason'),
            'override_payer' => $this->request->getQuery('override_payer'),
        ], static fn($v): bool => $v !== null && $v !== '');

        return $this->respond([
            'pricing' => (new TicketClosureService())->preview($ticketId, $proposal),
            'outstanding_requirements' => (new EvidenceService())->outstandingRequirements($ticketId),
        ]);
    }

    /**
     * GET /api/tickets/{id}/charges
     *
     * The frozen ledger as it stands, adjustments included, with the running
     * totals per book. This is what a company's query gets answered from.
     */
    public function charges(?string $id = null): Response
    {
        return $this->respond(
            (new TicketAdjustmentService())->ledger($this->ticketId($id)),
        );
    }

    /**
     * POST /api/tickets/{id}/charges
     *
     * Record additional service agreed on an open job — a BOQ line. Extra
     * work while the ticket is live is part of the bill being assembled,
     * not a correction to one already sent, so it does not freeze anything.
     */
    public function addServiceLine(?string $id = null): Response
    {
        $result = (new TicketAdjustmentService())->addServiceLine(
            $this->ticketId($id),
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if (($result['ok'] ?? false) === false) {
            $status = match ($result['code'] ?? '') {
                'not_found' => 404,
                // Well formed, but the ledger has moved past the stage where
                // adding to the original bill means anything.
                'frozen' => 409,
                default => 422,
            };

            return $this->fail(
                (string)($result['code'] ?? 'validation_error'),
                'The service line could not be recorded.',
                $status,
                $result['errors'] ?? [],
            );
        }

        return $this->respond($result, [], 201);
    }

    /**
     * POST /api/tickets/{id}/adjustments
     *
     * Correct a frozen ticket by appending, never by editing. A negative
     * amount reduces the named ledger — that is how a conceded dispute is
     * recorded.
     */
    public function addAdjustment(?string $id = null): Response
    {
        $result = (new TicketAdjustmentService())->add(
            $this->ticketId($id),
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if (($result['ok'] ?? false) === false) {
            $status = match ($result['code'] ?? '') {
                'not_found' => 404,
                // The ticket is real, the request is well formed, and the
                // ledger simply is not at a stage where a correction means
                // anything yet.
                'not_frozen' => 409,
                default => 422,
            };

            return $this->fail(
                (string)($result['code'] ?? 'validation_error'),
                'The adjustment could not be recorded.',
                $status,
                $result['errors'] ?? [],
            );
        }

        return $this->respond($result, [], 201);
    }

    /**
     * POST /api/tickets/{id}/technician-expenses
     *
     * A technician cost the rate card never priced — a lump sum, an extra
     * service charge, bata/transport — named against a technician expense
     * type and bound to this ticket. Always lands on technician_payable,
     * so it comes out of margin with nothing to choose or offset.
     */
    public function addTechnicianExpense(?string $id = null): Response
    {
        $result = (new TicketAdjustmentService())->addTechnicianExpense(
            $this->ticketId($id),
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if (($result['ok'] ?? false) === false) {
            $status = match ($result['code'] ?? '') {
                'not_found' => 404,
                'not_frozen' => 409,
                default => 422,
            };

            return $this->fail(
                (string)($result['code'] ?? 'validation_error'),
                'The technician expense could not be recorded.',
                $status,
                $result['errors'] ?? [],
            );
        }

        return $this->respond($result, [], 201);
    }

    /**
     * DELETE /api/tickets/{id}/charges/{chargeId}
     */
    public function removeCharge(?string $id = null, ?string $chargeId = null): Response
    {
        $chargeId = (int)$this->routeParam('charge_id', $chargeId);
        $result = (new TicketAdjustmentService())->remove($chargeId, $this->currentUserId());

        if (($result['ok'] ?? false) === false) {
            return $this->fail('validation_error', 'The charge line could not be removed.', 422, $result['errors'] ?? []);
        }

        return $this->respond($this->findTicket($id));
    }

    /**
     * POST /api/tickets/{id}/close
     */
    public function close(?string $id = null): Response
    {
        $result = (new TicketClosureService())->close(
            $this->ticketId($id),
            (array)$this->request->getData(),
            $this->currentUserId(),
        );

        if (($result['ok'] ?? false) === false) {
            $status = match ($result['code'] ?? '') {
                // The work is real and unpriceable: a gap in the agreement,
                // not a malformed request. 409 says so.
                'rate_not_found', 'no_agreement', 'already_closed' => 409,
                default => 422,
            };

            return $this->fail(
                (string)($result['code'] ?? 'validation_error'),
                'The ticket could not be closed.',
                $status,
                $result['errors'] ?? [],
                $result['context'] ?? null,
            );
        }

        return $this->respond($this->findTicket($id), [
            'totals' => $result['totals'],
            'lines' => $result['lines'],
        ]);
    }

    // -----------------------------------------------------------------
    // comments
    // -----------------------------------------------------------------

    /**
     * GET /api/tickets/{id}/comments
     *
     * Pass `visibility` to narrow to internal notes or to what was shared
     * with the company — the field app has no business showing the desk's
     * internal remarks to a technician standing in a customer's living room.
     */
    public function comments(?string $id = null): Response
    {
        $visibility = trim((string)$this->request->getQuery('visibility', ''));

        return $this->respond((new TicketWorkflow())->comments(
            $this->ticketId($id),
            $visibility !== '' ? $visibility : null,
        ));
    }

    /**
     * POST /api/tickets/{id}/comments
     */
    public function addComment(?string $id = null): Response
    {
        $data = (array)$this->request->getData();

        $result = (new TicketWorkflow())->comment(
            $this->ticketId($id),
            (string)($data['body'] ?? ''),
            $this->currentUserId(),
            (string)($data['visibility'] ?? 'internal'),
        );

        if (($result['ok'] ?? false) === false) {
            return $this->fail(
                'validation_error',
                'The comment could not be saved.',
                isset($result['errors']['ticket_id']) ? 404 : 422,
                $result['errors'] ?? [],
            );
        }

        return $this->respond($result, [], 201);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function ticketId(?string $id): int
    {
        $id ??= (string)$this->request->getParam('id');

        if (is_numeric($id)) {
            return (int)$id;
        }

        $row = $this->fetchTable('Tickets')->find()
            ->select(['id'])
            ->where(['ticket_no' => $id])
            ->disableHydration()
            ->first();

        return (int)($row['id'] ?? 0);
    }

    private function findTicket(?string $id): mixed
    {
        $id ??= (string)$this->request->getParam('id');

        $query = $this->fetchTable('Tickets')->find();

        if (is_numeric($id)) {
            $query->where(['Tickets.id' => (int)$id]);
        } else {
            $query->where(['Tickets.ticket_no' => $id]);
        }

        return $query->contain([
            // The district is half of a Kerala address — without it the panel
            // shows a street and a town and the technician still has to ring
            // the desk to find out which one.
            'Customers' => ['Districts'],
            'Companies', 'ServiceCenters', 'JobTypes',
            'AssignedTechnicians', 'Brands', 'ProductCategories',
            'Symptoms', 'Resolutions',
            'TicketEvents' => fn ($q) => $q->orderBy(['TicketEvents.occurred_at' => 'DESC']),
            // The fitted parts are shown as a list on the ticket, so the
            // catalogue row travels with each line — without it a part is
            // an id and the desk has to read the activity trail to find out
            // what is in the customer's set.
            'TicketSpares' => fn ($q) => $q
                ->contain(['SpareParts'])
                ->orderBy(['TicketSpares.created' => 'ASC', 'TicketSpares.id' => 'ASC']),
            'TicketHolds', 'TicketCharges', 'TicketAttachments',
        ])->first();
    }

}
