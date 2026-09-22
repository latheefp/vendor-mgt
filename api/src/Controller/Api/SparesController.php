<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\SpareService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Exception;

/**
 * The stock side of spare parts: what a centre holds, where it is, and how
 * long it has been held.
 *
 * Fitting a part to a job lives on the ticket, not here — that is a
 * decision about a customer's television and it belongs with the rest of
 * the work. What is here is everything that happens to a part while nobody
 * is looking at it: arriving on a challan, going out in a bag, coming back
 * unused, and quietly ageing towards the day clause 10 turns it into a
 * payable.
 *
 * Every write goes through SpareService rather than the tables. The ledger
 * has invariants that a controller has no business knowing — a transfer is
 * two rows, a balance is a location and not a centre — and every one of
 * them was broken at least once by code that wrote a movement directly.
 */
class SparesController extends ApiController
{
    /**
     * @param \Cake\Event\EventInterface $event The controller lifecycle event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Reading stock is open on the same terms as the rest of the desk
        // endpoints. Moving it is not: a movement is the only record that
        // a part was ever here, so it carries whoever recorded it.
        $this->Authentication->allowUnauthenticated([
            'catalogue', 'addPart', 'editPart', 'stock', 'holdings', 'movements', 'ageing',
            'receive', 'issue', 'returnGood', 'writeOff', 'count',
            'returnDefectives', 'recordDefectiveCredit',
        ]);
    }

    /**
     * POST /api/spares/catalogue
     */
    public function addPart(): Response
    {
        $spareParts = $this->fetchTable('SpareParts');

        $companyId = (int)$this->request->getData('company_id');
        $partNo = trim((string)$this->request->getData('part_no'));
        $name = trim((string)$this->request->getData('name'));

        if ($companyId <= 0 || $partNo === '' || $name === '') {
            return $this->fail('validation_error', 'Company, Part Number, and Part Name are required.', 422, [
                'company_id' => $companyId <= 0 ? ['Select company'] : [],
                'part_no' => $partNo === '' ? ['Part number is required'] : [],
                'name' => $name === '' ? ['Part name is required'] : [],
            ]);
        }

        $costRupees = (float)$this->request->getData('cost_rupees', 0);
        $mrpRupees = (float)$this->request->getData('mrp_rupees', 0);

        $part = $spareParts->newEntity([
            'company_id' => $companyId,
            'product_category_id' => $this->intOrNull('product_category_id'),
            'part_no' => $partNo,
            'name' => $name,
            'cost_paise' => (int)round($costRupees * 100),
            'mrp_paise' => (int)round($mrpRupees * 100),
            'reorder_level' => (int)($this->request->getData('reorder_level') ?? 5),
            'is_active' => true,
        ]);

        if (!$spareParts->save($part)) {
            return $this->fail('validation_error', 'The spare part could not be saved.', 422, $part->getErrors());
        }

        return $this->respond($part, [], 201);
    }

    /**
     * PUT /api/spares/catalogue/{id}
     *
     * The catalogue entry itself — cost, MRP, reorder level, active flag.
     * Unlike a rate card or a frozen charge, a part's list price isn't
     * something anything downstream has locked in: receipts and issues
     * record their own quantities and reference the part by id, not by a
     * copy of its price, so editing it in place doesn't corrupt history.
     */
    public function editPart(string $id): Response
    {
        $spareParts = $this->fetchTable('SpareParts');
        $part = $spareParts->find()->where(['id' => (int)$id])->first();

        if ($part === null) {
            return $this->fail('not_found', 'Spare part not found.', 404);
        }

        $companyId = (int)$this->request->getData('company_id');
        $partNo = trim((string)$this->request->getData('part_no'));
        $name = trim((string)$this->request->getData('name'));

        if ($companyId <= 0 || $partNo === '' || $name === '') {
            return $this->fail('validation_error', 'Company, Part Number, and Part Name are required.', 422, [
                'company_id' => $companyId <= 0 ? ['Select company'] : [],
                'part_no' => $partNo === '' ? ['Part number is required'] : [],
                'name' => $name === '' ? ['Part name is required'] : [],
            ]);
        }

        $costRupees = (float)$this->request->getData('cost_rupees', 0);
        $mrpRupees = (float)$this->request->getData('mrp_rupees', 0);
        $isActive = $this->request->getData('is_active');

        $part = $spareParts->patchEntity($part, [
            'company_id' => $companyId,
            'product_category_id' => $this->intOrNull('product_category_id'),
            'part_no' => $partNo,
            'name' => $name,
            'cost_paise' => (int)round($costRupees * 100),
            'mrp_paise' => (int)round($mrpRupees * 100),
            'reorder_level' => (int)($this->request->getData('reorder_level') ?? 5),
            'is_active' => $isActive === null ? $part->is_active : (bool)$isActive,
        ]);

        if (!$spareParts->save($part)) {
            return $this->fail('validation_error', 'The spare part could not be updated.', 422, $part->getErrors());
        }

        return $this->respond($part);
    }

    // -----------------------------------------------------------------
    // reading
    // -----------------------------------------------------------------

    /**
     * GET /api/spares/catalogue
     *
     * The parts list, optionally with each one's balance at a centre so
     * the issue form can show what it is about to hand out.
     */
    public function catalogue(): Response
    {
        $query = $this->fetchTable('SpareParts')->find()
            ->contain(['Companies'])
            ->orderByAsc('SpareParts.part_no');

        $companyId = (int)$this->request->getQuery('company_id', 0);
        if ($companyId > 0) {
            $query->where(['SpareParts.company_id' => $companyId]);
        }

        if ($this->request->getQuery('include_inactive') !== '1') {
            $query->where(['SpareParts.is_active' => true]);
        }

        $search = trim((string)$this->request->getQuery('q', ''));
        if ($search !== '') {
            $query->where(['OR' => [
                'SpareParts.part_no LIKE' => '%' . $search . '%',
                'SpareParts.name LIKE' => '%' . $search . '%',
            ]]);
        }

        $parts = $query->limit(200)->all()->toList();
        $centreId = (int)$this->request->getQuery('service_center_id', 0);

        if ($centreId < 1) {
            return $this->respond($parts);
        }

        $spares = new SpareService();
        $withStock = [];

        foreach ($parts as $part) {
            $row = $part->toArray();
            $row['on_shelf'] = $spares->balanceAtCentre((int)$part->id, $centreId);
            $row['on_hand'] = $spares->balanceAtCentreTotal((int)$part->id, $centreId);
            $withStock[] = $row;
        }

        return $this->respond($withStock, ['service_center_id' => $centreId]);
    }

    /**
     * GET /api/spares/stock
     *
     * Balances by part and centre, split between the shelf and what
     * technicians are carrying.
     */
    public function stock(): Response
    {
        $companyId = (int)$this->request->getQuery('company_id', 0);
        $centreId = (int)$this->request->getQuery('service_center_id', 0);

        $rows = (new SpareService())->stockOnHand(
            $companyId > 0 ? $companyId : null,
            $centreId > 0 ? $centreId : null,
        );

        return $this->respond($rows, [
            'value_paise' => array_sum(array_column($rows, 'value_paise')),
            'below_reorder' => count(array_filter($rows, fn(array $r): bool => $r['below_reorder'])),
            'negative' => count(array_filter($rows, fn(array $r): bool => $r['is_negative'])),
        ]);
    }

    /**
     * GET /api/spares/holdings
     *
     * What each technician is carrying. The list clause 10 makes expensive
     * and the one nobody keeps on paper.
     */
    public function holdings(): Response
    {
        $centreId = (int)$this->request->getQuery('service_center_id', 0);

        return $this->respond((new SpareService())->technicianHoldings(
            $centreId > 0 ? $centreId : null,
        ));
    }

    /**
     * GET /api/spares/{id}/movements
     *
     * The history behind one part's balance. "We are three short" is
     * answered here rather than by a number.
     */
    public function movements(?string $id = null): Response
    {
        $id = (int)$this->routeParam('id', $id);
        $centreId = (int)$this->request->getQuery('service_center_id', 0);

        return $this->respond((new SpareService())->movements(
            $id,
            $centreId > 0 ? $centreId : null,
            (int)$this->request->getQuery('limit', 100),
        ));
    }

    /**
     * GET /api/spares/ageing
     *
     * Clause 10 against stock we are still holding, aged from the challan
     * that brought it in. Overdue lots are money already lost; the ones
     * with days left are the reason to look.
     */
    public function ageing(): Response
    {
        $companyId = (int)$this->request->getQuery('company_id', 0);
        $centreId = (int)$this->request->getQuery('service_center_id', 0);

        $result = (new SpareService())->stockAgeing(
            $companyId > 0 ? $companyId : null,
            $centreId > 0 ? $centreId : null,
            (int)$this->request->getQuery('within_days', 5),
        );

        return $this->respond($result['lots'], $result['totals']);
    }

    // -----------------------------------------------------------------
    // moving stock
    // -----------------------------------------------------------------

    /**
     * POST /api/spares/receive
     *
     * Book a company challan in. `received_at` is the date on the challan,
     * not today — clause 10 counts from when they shipped it, and a
     * default of now hands us days we are not owed.
     */
    public function receive(): Response
    {
        $receivedAt = $this->dateOrNull('received_at');

        $result = (new SpareService())->receive(
            (int)$this->request->getData('spare_part_id'),
            (int)$this->request->getData('service_center_id'),
            (int)$this->request->getData('quantity', 0),
            $this->stringOrNull('reference'),
            $receivedAt,
            $this->currentUserId(),
            $this->intOrNull('unit_cost_paise'),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The receipt could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['movement_id' => $result['movement_id']], [], 201);
    }

    /**
     * POST /api/spares/issue
     *
     * Shelf to a technician's bag. Still our stock, still ageing, now with
     * a name against it.
     */
    public function issue(): Response
    {
        $ticketId = $this->intOrNull('ticket_id');

        $result = (new SpareService())->issueToTechnician(
            (int)$this->request->getData('spare_part_id'),
            (int)$this->request->getData('service_center_id'),
            (int)$this->request->getData('technician_id'),
            (int)$this->request->getData('quantity', 0),
            $ticketId,
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The part could not be issued.', 422, $result['errors']);
        }

        return $this->respond(['movement_ids' => $result['movement_ids']], [], 201);
    }

    /**
     * POST /api/spares/return-good
     *
     * An unused part coming back off a technician.
     */
    public function returnGood(): Response
    {
        $result = (new SpareService())->returnGoodFromTechnician(
            (int)$this->request->getData('spare_part_id'),
            (int)$this->request->getData('service_center_id'),
            (int)$this->request->getData('technician_id'),
            (int)$this->request->getData('quantity', 0),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The return could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['movement_ids' => $result['movement_ids']], [], 201);
    }

    /**
     * POST /api/spares/write-off
     */
    public function writeOff(): Response
    {
        $result = (new SpareService())->writeOff(
            (int)$this->request->getData('spare_part_id'),
            (int)$this->request->getData('service_center_id'),
            (int)$this->request->getData('quantity', 0),
            (string)$this->request->getData('reason', ''),
            $this->intOrNull('technician_id'),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The write-off could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['movement_id' => $result['movement_id']], [], 201);
    }

    /**
     * POST /api/spares/count
     *
     * A physical count. What gets written is the difference, so the
     * history that disagreed with it survives.
     */
    public function count(): Response
    {
        $result = (new SpareService())->adjustToCount(
            (int)$this->request->getData('spare_part_id'),
            (int)$this->request->getData('service_center_id'),
            (int)$this->request->getData('counted_quantity', 0),
            (string)$this->request->getData('reason', ''),
            $this->intOrNull('technician_id'),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The count could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['movement_id' => $result['movement_id'], 'delta' => $result['delta']], [], 201);
    }

    // -----------------------------------------------------------------
    // defective settlement
    // -----------------------------------------------------------------

    /**
     * POST /api/spares/defective-returns
     *
     * Send a batch back under one docket. Clause 9 settles on a cycle, so
     * the batch is the unit the company's credit note will come back
     * against.
     */
    public function returnDefectives(): Response
    {
        $ids = $this->request->getData('ticket_spare_ids');

        $result = (new SpareService())->returnDefectiveBatch(
            array_map('intval', is_array($ids) ? $ids : []),
            (string)$this->request->getData('reference', ''),
            $this->currentUserId(),
        );

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The batch could not be sent.', 422, $result['errors']);
        }

        return $this->respond([
            'returned' => $result['returned'],
            'skipped' => $result['skipped'],
            'reference' => $result['reference'],
        ], [], 201);
    }

    /**
     * POST /api/spares/defective-credits
     *
     * The company's credit note, against the docket it quotes.
     */
    public function recordDefectiveCredit(): Response
    {
        $spares = new SpareService();
        $reference = $this->stringOrNull('reference');

        $actorUserId = $this->currentUserId();

        $result = $reference !== null
            ? $spares->recordDefectiveCreditForBatch($reference, $this->dateOrNull('credited_at'), $actorUserId)
            : $spares->recordDefectiveCredit((int)$this->request->getData('ticket_spare_id'), $actorUserId);

        if ($result['ok'] === false) {
            return $this->fail('validation_error', 'The credit could not be recorded.', 422, $result['errors']);
        }

        return $this->respond(['credited' => $result['credited'] ?? 1]);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @param string $field The posted field to read.
     * @return string|null The trimmed value, or null when it was blank.
     */
    private function stringOrNull(string $field): ?string
    {
        $value = trim((string)$this->request->getData($field, ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param string $field The posted field to read.
     * @return int|null The value as an integer, or null when it was absent.
     */
    private function intOrNull(string $field): ?int
    {
        $value = $this->request->getData($field);

        return $value === null || $value === '' ? null : (int)$value;
    }

    /**
     * A date from the client, or null.
     *
     * An unparseable date is treated as absent rather than as an error.
     * The callers here use it to start a clock, and every one of them
     * already has a defined behaviour for not having one — falling back is
     * safer than refusing a challan over a date format.
     */
    private function dateOrNull(string $field): ?DateTime
    {
        $value = $this->stringOrNull($field);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (Exception) {
            return null;
        }
    }
}
