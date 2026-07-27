<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Money;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Where every spare part is, and what it costs us to keep it there.
 *
 * Stock is a movement ledger rather than a quantity column. "We are three
 * short" is a question about history — when did they leave, with whom, on
 * which ticket — and a counter that has been decremented forty times
 * cannot answer any of it.
 *
 * Two clauses give this real money attached to it, and both are per-company
 * terms rather than constants:
 *
 *   clause 9   a defective part goes back within N days (7 for Dianora)
 *   clause 10  a part held beyond N days (30) is treated as billed to us
 *
 * The second is the expensive one and the easiest to miss: a panel sitting
 * in a technician's bag quietly becomes our cost on day 31, with nothing
 * anywhere reporting that it happened. The ageing queries below exist to
 * make that visible while it is still avoidable.
 *
 * LOCATIONS
 *
 * A movement's location is the pair (service_center_id, technician_id).
 * A null technician is the centre's shelf; a technician is that person's
 * bag, still the centre's stock and still ours. Every movement is signed
 * at exactly one location, so a transfer is two rows rather than one row
 * that means different things depending on which balance is asking:
 *
 *   received        +n shelf
 *   issued          -n shelf, +n bag
 *   returned_good   -n bag,   +n shelf
 *   consumed        -n at whichever location the part actually left
 *   written_off     -n at a stated location
 *   adjustment      signed, at a stated location
 *   sent_to_vendor   0        (a defective was never our stock)
 *
 * The alternative — one row carrying both ends — is what produced a
 * centre balance that fell by two when one part was issued and then
 * fitted, and a technician holding that could not be derived at all.
 */
class SpareService
{
    use LocatorAwareTrait;

    /**
     * Movement types that put stock into a centre's possession, and so
     * start a clause 10 clock.
     *
     * Transfers are deliberately absent: moving a part from a shelf to a
     * bag does not make it newer, and treating the arrival in the bag as
     * a fresh receipt would reset the ageing every time a part was
     * carried out and brought back.
     *
     * @var list<string>
     */
    private const INFLOW_TYPES = ['received', 'adjustment'];

    /**
     * Types that take stock out of a centre's possession for good.
     *
     * @var list<string>
     */
    private const OUTFLOW_TYPES = ['consumed', 'written_off', 'adjustment'];

    /**
     * Clause 10's window per company, memoised for the life of the call.
     * An ageing pass touches every lot and they are overwhelmingly one
     * company's.
     *
     * @var array<int, int>
     */
    private array $billingDaysByVendor = [];

    /**
     * @param \App\Service\RateCardRepository $rates Per-company agreement terms — clauses 6, 9 and 10.
     * @param \App\Service\TicketWorkflow $workflow The append-only ticket trail.
     */
    public function __construct(
        private readonly RateCardRepository $rates = new RateCardRepository(),
        private readonly TicketWorkflow $workflow = new TicketWorkflow(),
    ) {
    }

    // -----------------------------------------------------------------
    // stock
    // -----------------------------------------------------------------

    /**
     * Book parts in from the company.
     *
     * `receivedAt` starts the clause 10 clock, so it is the date on the
     * company's challan rather than the day someone got round to entering
     * it. Defaulting to now would hand us free days we are not owed.
     *
     * The cost is stamped on the movement as well as read from the
     * catalogue, because clause 10 turns this stock into a payable at the
     * price it arrived at. A catalogue that has been repriced since would
     * value the liability at a number nobody agreed to.
     *
     * @return array{ok: true, movement_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function receive(
        int $sparePartId,
        int $serviceCenterId,
        int $quantity,
        ?string $reference = null,
        ?DateTime $receivedAt = null,
        ?int $actorUserId = null,
        ?int $unitCostPaise = null,
    ): array {
        if ($quantity < 1) {
            return ['ok' => false, 'errors' => ['quantity' => ['How many parts arrived?']]];
        }

        $part = $this->fetchTable('SpareParts')->find()->where(['id' => $sparePartId])->first();

        if ($part === null) {
            return ['ok' => false, 'errors' => ['spare_part_id' => ['No such part in the catalogue.']]];
        }

        $movementId = $this->recordMovement([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
            'movement_type' => 'received',
            'quantity' => $quantity,
            'unit_cost_paise' => $unitCostPaise ?? (int)$part->cost_paise,
            'reference' => $reference,
            'occurred_at' => $receivedAt ?? DateTime::now(),
            'actor_user_id' => $actorUserId,
        ]);

        return ['ok' => true, 'movement_id' => $movementId];
    }

    /**
     * Hand parts to a technician.
     *
     * The part stays ours and stays on the books; it has simply moved from
     * a shelf to a bag. That distinction is what lets the ageing report
     * name a person rather than a location.
     *
     * @return array{ok: true, movement_ids: list<int>}|array{ok: false, errors: array<string, list<string>>}
     */
    public function issueToTechnician(
        int $sparePartId,
        int $serviceCenterId,
        int $technicianId,
        int $quantity,
        ?int $ticketId = null,
        ?int $actorUserId = null,
    ): array {
        if ($quantity < 1) {
            return ['ok' => false, 'errors' => ['quantity' => ['How many parts are going out?']]];
        }

        $available = $this->balanceAtCentre($sparePartId, $serviceCenterId);

        if ($available < $quantity) {
            return ['ok' => false, 'errors' => ['quantity' => [sprintf(
                'Only %d on the shelf at this service centre.',
                $available,
            )]]];
        }

        return ['ok' => true, 'movement_ids' => $this->transfer(
            $sparePartId,
            $serviceCenterId,
            'issued',
            $quantity,
            fromTechnicianId: null,
            toTechnicianId: $technicianId,
            ticketId: $ticketId,
            actorUserId: $actorUserId,
        )];
    }

    /**
     * Take an unused part back off a technician.
     *
     * The case this exists for: two panels go out, one is fitted, and the
     * other stays in the bag because nothing ever books it back. Without
     * this the centre is short a part it still owns, and the clause 10
     * clock keeps running on it with nobody able to explain where it is.
     *
     * @return array{ok: true, movement_ids: list<int>}|array{ok: false, errors: array<string, list<string>>}
     */
    public function returnGoodFromTechnician(
        int $sparePartId,
        int $serviceCenterId,
        int $technicianId,
        int $quantity,
        ?int $actorUserId = null,
    ): array {
        if ($quantity < 1) {
            return ['ok' => false, 'errors' => ['quantity' => ['How many parts are coming back?']]];
        }

        $held = $this->balanceWithTechnician($sparePartId, $technicianId, $serviceCenterId);

        if ($held < $quantity) {
            return ['ok' => false, 'errors' => ['quantity' => [sprintf(
                'This technician is holding %d, so %d cannot come back.',
                $held,
                $quantity,
            )]]];
        }

        return ['ok' => true, 'movement_ids' => $this->transfer(
            $sparePartId,
            $serviceCenterId,
            'returned_good',
            $quantity,
            fromTechnicianId: $technicianId,
            toTechnicianId: null,
            ticketId: null,
            actorUserId: $actorUserId,
        )];
    }

    /**
     * Write stock off — damaged in handling, lost, or found missing at a
     * count with no explanation available.
     *
     * A reason is required rather than optional. Stock that leaves without
     * one is indistinguishable from stock that was stolen, and the
     * difference is the only thing this row is for.
     *
     * @return array{ok: true, movement_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function writeOff(
        int $sparePartId,
        int $serviceCenterId,
        int $quantity,
        string $reason,
        ?int $technicianId = null,
        ?int $actorUserId = null,
    ): array {
        if ($quantity < 1) {
            return ['ok' => false, 'errors' => ['quantity' => ['How many are being written off?']]];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'errors' => ['reason' => ['Say what happened to these parts.']]];
        }

        $held = $technicianId === null
            ? $this->balanceAtCentre($sparePartId, $serviceCenterId)
            : $this->balanceWithTechnician($sparePartId, $technicianId, $serviceCenterId);

        if ($held < $quantity) {
            return ['ok' => false, 'errors' => ['quantity' => [sprintf(
                'Only %d at that location.',
                $held,
            )]]];
        }

        return ['ok' => true, 'movement_id' => $this->recordMovement([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
            'technician_id' => $technicianId,
            'movement_type' => 'written_off',
            'quantity' => -$quantity,
            'notes' => $reason,
            'occurred_at' => DateTime::now(),
            'actor_user_id' => $actorUserId,
        ])];
    }

    /**
     * Correct the ledger against a physical count.
     *
     * The delta is what gets recorded, not the counted figure, because the
     * ledger is the history and a count is one observation in it. Writing
     * the observed total would erase every movement that disagreed with
     * it, which is exactly the history the clause 10 ageing runs on.
     *
     * @return array{ok: true, movement_id: int, delta: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function adjustToCount(
        int $sparePartId,
        int $serviceCenterId,
        int $countedQuantity,
        string $reason,
        ?int $technicianId = null,
        ?int $actorUserId = null,
    ): array {
        if ($countedQuantity < 0) {
            return ['ok' => false, 'errors' => ['counted_quantity' => ['A count cannot be negative.']]];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'errors' => ['reason' => ['Say why the ledger and the shelf disagree.']]];
        }

        $onBook = $technicianId === null
            ? $this->balanceAtCentre($sparePartId, $serviceCenterId)
            : $this->balanceWithTechnician($sparePartId, $technicianId, $serviceCenterId);

        $delta = $countedQuantity - $onBook;

        // A count that agrees is still worth recording: it is evidence the
        // balance was checked on a date, which is what makes the next
        // disagreement diagnosable. `adjustment` is allowed to be zero for
        // exactly this reason.
        $movementId = $this->recordMovement([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
            'technician_id' => $technicianId,
            'movement_type' => 'adjustment',
            'quantity' => $delta,
            'notes' => sprintf('Counted %d against %d on book. %s', $countedQuantity, $onBook, $reason),
            'occurred_at' => DateTime::now(),
            'actor_user_id' => $actorUserId,
        ]);

        return ['ok' => true, 'movement_id' => $movementId, 'delta' => $delta];
    }

    /**
     * Stock on a service centre's shelves — not counting what technicians
     * are carrying.
     */
    public function balanceAtCentre(int $sparePartId, int $serviceCenterId): int
    {
        return $this->sumQuantity([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
            'technician_id IS' => null,
        ]);
    }

    /**
     * What one technician is carrying of one part.
     */
    public function balanceWithTechnician(
        int $sparePartId,
        int $technicianId,
        ?int $serviceCenterId = null,
    ): int {
        $conditions = [
            'spare_part_id' => $sparePartId,
            'technician_id' => $technicianId,
        ];

        if ($serviceCenterId !== null) {
            $conditions['service_center_id'] = $serviceCenterId;
        }

        return $this->sumQuantity($conditions);
    }

    /**
     * Everything the centre owns of one part, shelf and bags together.
     * This is the figure clause 10 ages.
     */
    public function balanceAtCentreTotal(int $sparePartId, int $serviceCenterId): int
    {
        return $this->sumQuantity([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
        ]);
    }

    /**
     * The stock list: every catalogued part against every centre holding
     * it, split shelf/bag, valued, and flagged against its reorder level.
     *
     * Parts with no movements are included when a centre is named — a part
     * that has run to zero is precisely the row someone is looking for,
     * and omitting it would make the list quietly useless for reordering.
     *
     * @return list<array<string, mixed>>
     */
    public function stockOnHand(?int $vendorId = null, ?int $serviceCenterId = null): array
    {
        $movements = $this->fetchTable('SpareStockMovements');

        $query = $movements->find()
            ->select([
                'spare_part_id' => 'SpareStockMovements.spare_part_id',
                'service_center_id' => 'SpareStockMovements.service_center_id',
                'on_hand' => 'SUM(SpareStockMovements.quantity)',
                'on_shelf' => 'SUM(CASE WHEN SpareStockMovements.technician_id IS NULL '
                    . 'THEN SpareStockMovements.quantity ELSE 0 END)',
                'last_movement_at' => 'MAX(SpareStockMovements.occurred_at)',
            ])
            ->groupBy(['SpareStockMovements.spare_part_id', 'SpareStockMovements.service_center_id']);

        if ($serviceCenterId !== null) {
            $query->where(['SpareStockMovements.service_center_id' => $serviceCenterId]);
        }

        $balances = [];
        foreach ($query->disableHydration()->all() as $row) {
            $balances[$row['spare_part_id'] . ':' . $row['service_center_id']] = $row;
        }

        $parts = $this->fetchTable('SpareParts')->find()
            ->contain(['Vendors'])
            ->where(['SpareParts.is_active' => true]);

        if ($vendorId !== null) {
            $parts->where(['SpareParts.vendor_id' => $vendorId]);
        }

        $centres = $this->centreNames($serviceCenterId);
        $rows = [];

        foreach ($parts->all() as $part) {
            foreach ($centres as $centreId => $centreName) {
                $balance = $balances[$part->id . ':' . $centreId] ?? null;

                // A part that has never moved at a centre is only worth a
                // row when the centre was asked for by name. Listing every
                // part against every centre otherwise buries the ones that
                // matter.
                if ($balance === null && $serviceCenterId === null) {
                    continue;
                }

                $onHand = (int)($balance['on_hand'] ?? 0);
                $onShelf = (int)($balance['on_shelf'] ?? 0);

                $rows[] = [
                    'spare_part_id' => (int)$part->id,
                    'part_no' => $part->part_no,
                    'part_name' => $part->name,
                    'vendor_id' => (int)$part->vendor_id,
                    'vendor_name' => $part->vendor->name ?? null,
                    'service_center_id' => (int)$centreId,
                    'service_center_name' => $centreName,
                    'on_hand' => $onHand,
                    'on_shelf' => $onShelf,
                    'with_technicians' => $onHand - $onShelf,
                    'unit_cost_paise' => (int)$part->cost_paise,
                    'value_paise' => $onHand * (int)$part->cost_paise,
                    'reorder_level' => (int)$part->reorder_level,
                    // Against the shelf, not the total: a part in someone's
                    // bag is spoken for and cannot be handed to the next job.
                    'below_reorder' => (int)$part->reorder_level > 0 && $onShelf <= (int)$part->reorder_level,
                    'is_negative' => $onHand < 0,
                    'is_serialized' => (bool)$part->is_serialized,
                    'last_movement_at' => $balance['last_movement_at'] ?? null,
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            // Problems first: anything impossible, then anything about to
            // run out. The rest is a reference list nobody reads top-down.
            return [$b['is_negative'], $b['below_reorder'], $a['part_no']]
                <=> [$a['is_negative'], $a['below_reorder'], $b['part_no']];
        });

        return $rows;
    }

    /**
     * What each technician is carrying, across all parts.
     *
     * @return list<array<string, mixed>>
     */
    public function technicianHoldings(?int $serviceCenterId = null): array
    {
        $query = $this->fetchTable('SpareStockMovements')->find()
            ->select([
                'technician_id' => 'SpareStockMovements.technician_id',
                'service_center_id' => 'SpareStockMovements.service_center_id',
                'spare_part_id' => 'SpareStockMovements.spare_part_id',
                'quantity' => 'SUM(SpareStockMovements.quantity)',
                'technician_name' => 'Technicians.name',
                'part_no' => 'SpareParts.part_no',
                'part_name' => 'SpareParts.name',
                'unit_cost_paise' => 'SpareParts.cost_paise',
            ])
            ->join([
                'Technicians' => [
                    'table' => 'technicians',
                    'type' => 'INNER',
                    'conditions' => 'Technicians.id = SpareStockMovements.technician_id',
                ],
                'SpareParts' => [
                    'table' => 'spare_parts',
                    'type' => 'INNER',
                    'conditions' => 'SpareParts.id = SpareStockMovements.spare_part_id',
                ],
            ])
            ->where(['SpareStockMovements.technician_id IS NOT' => null])
            ->groupBy([
                'SpareStockMovements.technician_id',
                'SpareStockMovements.service_center_id',
                'SpareStockMovements.spare_part_id',
                'Technicians.name',
                'SpareParts.part_no',
                'SpareParts.name',
                'SpareParts.cost_paise',
            ])
            // A technician holding nothing is not news. A negative one is,
            // so the filter is on zero rather than on "less than one".
            ->having(['SUM(SpareStockMovements.quantity) <>' => 0]);

        if ($serviceCenterId !== null) {
            $query->where(['SpareStockMovements.service_center_id' => $serviceCenterId]);
        }

        $rows = [];
        foreach ($query->disableHydration()->all() as $row) {
            $rows[] = $row + ['value_paise' => (int)$row['quantity'] * (int)$row['unit_cost_paise']];
        }

        return $rows;
    }

    /**
     * The movement history behind a balance.
     *
     * @return list<array<string, mixed>>
     */
    public function movements(int $sparePartId, ?int $serviceCenterId = null, int $limit = 100): array
    {
        $query = $this->fetchTable('SpareStockMovements')->find()
            ->contain(['ServiceCenters', 'Technicians', 'Tickets'])
            ->where(['SpareStockMovements.spare_part_id' => $sparePartId])
            ->orderByDesc('SpareStockMovements.occurred_at')
            ->orderByDesc('SpareStockMovements.id')
            ->limit($limit);

        if ($serviceCenterId !== null) {
            $query->where(['SpareStockMovements.service_center_id' => $serviceCenterId]);
        }

        return $query->all()->toList();
    }

    /**
     * Move stock between two locations at the same centre.
     *
     * Two rows in one transaction. A half-applied transfer would leave a
     * part that exists in neither place, and the ledger has no way to
     * express "in transit" — nor should it, since the physical move is
     * someone handing a box across a counter.
     *
     * @return list<int>
     */
    private function transfer(
        int $sparePartId,
        int $serviceCenterId,
        string $movementType,
        int $quantity,
        ?int $fromTechnicianId,
        ?int $toTechnicianId,
        ?int $ticketId,
        ?int $actorUserId,
    ): array {
        $movements = $this->fetchTable('SpareStockMovements');
        $now = DateTime::now();

        $common = [
            'spare_part_id' => $sparePartId,
            'service_center_id' => $serviceCenterId,
            'ticket_id' => $ticketId,
            'movement_type' => $movementType,
            'occurred_at' => $now,
            'actor_user_id' => $actorUserId,
        ];

        return $movements->getConnection()->transactional(
            function () use ($common, $quantity, $fromTechnicianId, $toTechnicianId): array {
                return [
                    $this->recordMovement($common + [
                        'technician_id' => $fromTechnicianId,
                        'quantity' => -$quantity,
                    ]),
                    $this->recordMovement($common + [
                        'technician_id' => $toTechnicianId,
                        'quantity' => $quantity,
                    ]),
                ];
            },
        );
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function sumQuantity(array $conditions): int
    {
        $row = $this->fetchTable('SpareStockMovements')->find()
            ->select(['balance' => 'SUM(quantity)'])
            ->where($conditions)
            ->disableHydration()
            ->first();

        return (int)($row['balance'] ?? 0);
    }

    /**
     * @return array<int, string>
     */
    private function centreNames(?int $serviceCenterId = null): array
    {
        $query = $this->fetchTable('ServiceCenters')->find()
            ->select(['id', 'name'])
            ->where(['is_active' => true]);

        if ($serviceCenterId !== null) {
            $query->where(['id' => $serviceCenterId]);
        }

        return $query->all()->combine('id', 'name')->toArray();
    }

    // -----------------------------------------------------------------
    // consumption on a ticket
    // -----------------------------------------------------------------

    /**
     * Record a part fitted on a job.
     *
     * Who pays follows the warranty scope, and the margin is validated
     * against the band this company negotiated. Clause 6 gives a range —
     * 10% to 15% for Dianora — and a figure outside it is a breach rather
     * than a pricing preference, so it is refused here rather than
     * discovered on an invoice.
     *
     * @return array{ok: true, ticket_spare_id: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function consumeOnTicket(int $ticketId, array $data, ?int $actorUserId = null): array
    {
        $ticket = $this->fetchTable('Tickets')->get($ticketId);

        if ($ticket->charges_frozen_at !== null) {
            return ['ok' => false, 'errors' => ['ticket' => [
                'This ticket is closed and its charges are frozen. Raise an adjustment instead.',
            ]]];
        }

        $sparePartId = (int)($data['spare_part_id'] ?? 0);
        $part = $this->fetchTable('SpareParts')->find()
            ->where(['id' => $sparePartId, 'vendor_id' => $ticket->vendor_id])
            ->first();

        if ($part === null) {
            return ['ok' => false, 'errors' => ['spare_part_id' => [
                'Not a part in this company\'s catalogue.',
            ]]];
        }

        $quantity = max(1, (int)($data['quantity'] ?? 1));

        // Out-of-warranty parts are sold to the customer; in-warranty ones
        // are supplied by the company and carry an obligation instead of a
        // price. Defaulted from the ticket rather than trusted from input,
        // because getting it wrong bills a warranty customer.
        $chargedTo = $ticket->warranty_scope === 'out_of_warranty' ? 'customer' : 'vendor';

        $marginPct = '0.00';
        $unitCost = Money::fromPaise((int)$part->cost_paise);

        if ($chargedTo === 'customer') {
            $marginPct = (string)($data['margin_pct'] ?? '');

            try {
                $terms = $this->rates->agreementTerms(
                    (int)$ticket->vendor_id,
                    (new DateTime($ticket->received_at))->format('Y-m-d'),
                );
            } catch (RecordNotFoundException) {
                return ['ok' => false, 'errors' => ['margin_pct' => [
                    'No active agreement, so the permitted margin band is unknown.',
                ]]];
            }

            if ($marginPct === '') {
                // Default to the floor of the band. The desk may go higher
                // within it, but nobody should have to make a pricing
                // decision to record a part.
                $marginPct = $terms->spareMarginMinPct;
            }

            if (!$terms->isSpareMarginPermitted($marginPct)) {
                return ['ok' => false, 'errors' => ['margin_pct' => [sprintf(
                    'This company permits %s%%-%s%%. %s%% is outside the agreed band.',
                    $terms->spareMarginMinPct,
                    $terms->spareMarginMaxPct,
                    $marginPct,
                )]]];
            }
        }

        $unitPrice = $unitCost->plus($unitCost->percentage($marginPct));

        $receivedAt = isset($data['received_at']) ? new DateTime((string)$data['received_at']) : null;

        $spares = $this->fetchTable('TicketSpares');
        $spare = $spares->newEntity([
            'ticket_id' => $ticketId,
            'spare_part_id' => $sparePartId,
            'quantity' => $quantity,
            'serial_no' => $data['serial_no'] ?? null,
            'unit_cost_paise' => $unitCost->paise,
            'margin_pct' => $marginPct,
            'unit_price_paise' => $unitPrice->paise,
            'line_total_paise' => $unitPrice->times($quantity)->paise,
            'charged_to' => $chargedTo,
            'is_defective_return' => (bool)($data['is_defective_return'] ?? false),
            'received_at' => $receivedAt,
            'issued_from_center_id' => $data['issued_from_center_id'] ?? $ticket->service_center_id,
            'issued_to_technician_id' => $ticket->assigned_technician_id,
            'issued_at' => DateTime::now(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->applyClocks($spare, (int)$ticket->vendor_id, $receivedAt);

        if (!$spares->save($spare)) {
            return ['ok' => false, 'errors' => $spare->getErrors()];
        }

        $centreId = (int)($spare->issued_from_center_id ?? $ticket->service_center_id);
        $technicianId = $ticket->assigned_technician_id !== null
            ? (int)$ticket->assigned_technician_id
            : null;

        // The part leaves the location that was actually holding it. If the
        // technician was issued one it comes out of their bag; otherwise it
        // came straight off the shelf and the movement must say so, or the
        // shelf keeps a part that is physically in a customer's television.
        $fromTechnicianId = $technicianId !== null
            && $this->balanceWithTechnician($sparePartId, $technicianId, $centreId) >= $quantity
                ? $technicianId
                : null;

        $this->recordMovement([
            'spare_part_id' => $sparePartId,
            'service_center_id' => $centreId,
            'technician_id' => $fromTechnicianId,
            'ticket_id' => $ticketId,
            'movement_type' => 'consumed',
            'quantity' => -$quantity,
            'unit_cost_paise' => $unitCost->paise,
            'serial_no' => $data['serial_no'] ?? null,
            'occurred_at' => DateTime::now(),
            'actor_user_id' => $actorUserId,
        ]);

        // Recording work that physically happened is never blocked on the
        // back office having booked a challan first, so a consumption may
        // take a location negative. That is a data problem rather than an
        // operational one, and it is handed back to be shown rather than
        // swallowed — an unexplained negative is how stock walks.
        $remaining = $this->balanceAtCentreTotal($sparePartId, $centreId);

        $this->workflow->logEvent($ticketId, 'spare_used', null, null, $actorUserId, sprintf(
            '%s x%d fitted, billed to the %s.',
            $part->name,
            $quantity,
            $chargedTo,
        ), [
            'ticket_spare_id' => (int)$spare->id,
            'part_no' => $part->part_no,
            'charged_to' => $chargedTo,
            'margin_pct' => $marginPct,
        ]);

        return [
            'ok' => true,
            'ticket_spare_id' => (int)$spare->id,
            'stock_remaining' => $remaining,
            'stock_warning' => $remaining < 0 ? sprintf(
                '%s is now %d at this centre. Book the company\'s challan in, or count the shelf.',
                $part->part_no,
                $remaining,
            ) : null,
        ];
    }

    /**
     * Set the two clauses' deadlines from this company's own terms.
     */
    private function applyClocks(object $spare, int $vendorId, ?DateTime $receivedAt): void
    {
        try {
            $terms = $this->rates->agreementTerms($vendorId);
        } catch (RecordNotFoundException) {
            return;
        }

        if ($spare->is_defective_return) {
            // Clause 9: the defective unit goes back within this many days
            // of being swapped out.
            $spare->set(
                'defective_return_due_at',
                DateTime::now()->addDays($terms->defectiveReturnDays),
            );
        }

        // Clause 10: the clock starts when the company shipped the part,
        // not when we fitted it. Without a receipt date there is nothing
        // to age against, and inventing one would age it in our favour.
        if ($receivedAt !== null) {
            $spare->set('billing_due_at', $receivedAt->addDays($terms->spareBillingDays));
        }
    }

    // -----------------------------------------------------------------
    // defective returns
    // -----------------------------------------------------------------

    /**
     * Send a defective part back to the company.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, list<string>>}
     */
    public function returnDefective(
        int $ticketSpareId,
        ?string $reference = null,
        ?int $actorUserId = null,
    ): array {
        $spares = $this->fetchTable('TicketSpares');
        $spare = $spares->get($ticketSpareId, contain: ['Tickets', 'SpareParts']);

        if (!$spare->is_defective_return) {
            return ['ok' => false, 'errors' => ['ticket_spare_id' => [
                'This part was not recorded as a defective swap.',
            ]]];
        }

        if ($spare->defective_returned_at !== null) {
            return ['ok' => false, 'errors' => ['ticket_spare_id' => ['This part has already gone back.']]];
        }

        $now = DateTime::now();

        $spare->set('defective_returned_at', $now);
        $spare->set('defective_return_reference', $reference);
        $spares->saveOrFail($spare);

        $this->recordMovement([
            'spare_part_id' => (int)$spare->spare_part_id,
            'service_center_id' => (int)($spare->issued_from_center_id ?? $spare->ticket->service_center_id),
            'ticket_id' => (int)$spare->ticket_id,
            'movement_type' => 'sent_to_vendor',
            // The defective unit was never our stock to begin with; this
            // movement records the obligation being discharged, so it does
            // not move a balance.
            'quantity' => 0,
            'reference' => $reference,
            'serial_no' => $spare->serial_no,
            'occurred_at' => $now,
            'actor_user_id' => $actorUserId,
        ]);

        $late = $spare->defective_return_due_at !== null && $now > $spare->defective_return_due_at;

        $this->workflow->logEvent(
            (int)$spare->ticket_id,
            'spare_returned',
            null,
            null,
            $actorUserId,
            sprintf('Defective %s returned to the company.%s', $spare->spare_part->name, $late ? ' Late.' : ''),
            ['ticket_spare_id' => $ticketSpareId, 'reference' => $reference, 'late' => $late],
        );

        return ['ok' => true];
    }

    /**
     * Send a batch of defectives back under one docket.
     *
     * Clause 9 settles every 7 days, and a settlement is a consignment
     * rather than a part: one courier docket covers everything in the box,
     * and the company's credit note comes back against that docket. Making
     * the batch the unit here is what lets the credit be reconciled at all
     * — matching a single credit note against eleven separately-referenced
     * returns is a job nobody does twice.
     *
     * Partial success is deliberate. One already-returned line in a
     * selection of twenty should not send the other nineteen back to be
     * re-picked; the box has already gone.
     *
     * @param list<int> $ticketSpareIds
     * @return array{ok: true, returned: list<int>, skipped: array<int, string>, reference: string}
     *        |array{ok: false, errors: array<string, list<string>>}
     */
    public function returnDefectiveBatch(
        array $ticketSpareIds,
        string $reference,
        ?int $actorUserId = null,
    ): array {
        if ($ticketSpareIds === []) {
            return ['ok' => false, 'errors' => ['ticket_spare_ids' => ['Nothing was selected to send back.']]];
        }

        if (trim($reference) === '') {
            return ['ok' => false, 'errors' => ['reference' => [
                'A courier docket or challan number is what the credit note will quote back.',
            ]]];
        }

        $returned = [];
        $skipped = [];

        foreach ($ticketSpareIds as $id) {
            $result = $this->returnDefective((int)$id, $reference, $actorUserId);

            if ($result['ok'] === true) {
                $returned[] = (int)$id;

                continue;
            }

            $skipped[(int)$id] = implode(' ', array_merge(...array_values($result['errors'])));
        }

        return ['ok' => true, 'returned' => $returned, 'skipped' => $skipped, 'reference' => $reference];
    }

    /**
     * Record the company's credit note against returned defectives.
     *
     * Addressed by docket rather than by line for the same reason the
     * return is: the credit note names the consignment.
     *
     * @return array{ok: true, credited: int}|array{ok: false, errors: array<string, list<string>>}
     */
    public function recordDefectiveCreditForBatch(
        string $reference,
        ?DateTime $creditedAt = null,
        ?int $actorUserId = null,
    ): array {
        if (trim($reference) === '') {
            return ['ok' => false, 'errors' => ['reference' => ['Which docket is this credit against?']]];
        }

        $spares = $this->fetchTable('TicketSpares');
        $pending = $spares->find()
            ->where([
                'defective_return_reference' => $reference,
                'defective_returned_at IS NOT' => null,
                'defective_credit_received_at IS' => null,
            ])
            ->all();

        if ($pending->isEmpty()) {
            return ['ok' => false, 'errors' => ['reference' => [
                'No defectives are waiting on a credit under that docket.',
            ]]];
        }

        $at = $creditedAt ?? DateTime::now();
        $credited = 0;

        foreach ($pending as $spare) {
            $spare->set('defective_credit_received_at', $at);
            $spares->saveOrFail($spare);
            $credited++;

            $this->workflow->logEvent(
                (int)$spare->ticket_id,
                'spare_credit_received',
                null,
                null,
                $actorUserId,
                sprintf('Company credit received against docket %s.', $reference),
                ['ticket_spare_id' => (int)$spare->id, 'reference' => $reference],
            );
        }

        return ['ok' => true, 'credited' => $credited];
    }

    /**
     * Record the company's credit note against one returned defective.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, list<string>>}
     */
    public function recordDefectiveCredit(int $ticketSpareId, ?int $actorUserId = null): array
    {
        $spares = $this->fetchTable('TicketSpares');
        $spare = $spares->get($ticketSpareId);

        if ($spare->defective_returned_at === null) {
            return ['ok' => false, 'errors' => ['ticket_spare_id' => ['This part has not gone back yet.']]];
        }

        if ($spare->defective_credit_received_at !== null) {
            return ['ok' => false, 'errors' => ['ticket_spare_id' => ['A credit is already recorded for this part.']]];
        }

        $spare->set('defective_credit_received_at', DateTime::now());
        $spares->saveOrFail($spare);

        $this->workflow->logEvent(
            (int)$spare->ticket_id,
            'spare_credit_received',
            null,
            null,
            $actorUserId,
            'Company credit received for the returned defective.',
            ['ticket_spare_id' => $ticketSpareId],
        );

        return ['ok' => true];
    }

    /**
     * Defective parts that have not gone back, oldest first.
     *
     * Clause 9 settles these on a cycle, so what matters is the ones about
     * to miss the next one rather than a raw list.
     *
     * @return list<array<string, mixed>>
     */
    public function defectiveReturnsDue(?int $vendorId = null, int $withinDays = 3): array
    {
        $conditions = [
            'TicketSpares.is_defective_return' => true,
            'TicketSpares.defective_returned_at IS' => null,
            'TicketSpares.defective_return_due_at <=' => DateTime::now()->addDays($withinDays),
        ];

        if ($vendorId !== null) {
            $conditions['Tickets.vendor_id'] = $vendorId;
        }

        return $this->fetchTable('TicketSpares')->find()
            ->select([
                'TicketSpares.id',
                'TicketSpares.quantity',
                'TicketSpares.serial_no',
                'TicketSpares.defective_return_due_at',
                'ticket_no' => 'Tickets.ticket_no',
                'vendor_id' => 'Tickets.vendor_id',
                'part_no' => 'SpareParts.part_no',
                'part_name' => 'SpareParts.name',
            ])
            ->join([
                'Tickets' => [
                    'table' => 'tickets',
                    'type' => 'INNER',
                    'conditions' => 'Tickets.id = TicketSpares.ticket_id',
                ],
                'SpareParts' => [
                    'table' => 'spare_parts',
                    'type' => 'INNER',
                    'conditions' => 'SpareParts.id = TicketSpares.spare_part_id',
                ],
            ])
            ->where($conditions)
            ->orderByAsc('TicketSpares.defective_return_due_at')
            ->disableHydration()
            ->all()
            ->toList();
    }

    /**
     * Parts approaching the day they become our cost.
     *
     * This is the clause 10 report, and it is the one worth putting on a
     * dashboard: after the due date the money is already lost and the list
     * is only useful as an explanation.
     *
     * @return list<array<string, mixed>>
     */
    public function sparesNearingBillingCutoff(?int $vendorId = null, int $withinDays = 5): array
    {
        $conditions = [
            'TicketSpares.billing_due_at IS NOT' => null,
            'TicketSpares.billing_due_at <=' => DateTime::now()->addDays($withinDays),
            'TicketSpares.charged_to' => 'vendor',
        ];

        if ($vendorId !== null) {
            $conditions['Tickets.vendor_id'] = $vendorId;
        }

        return $this->fetchTable('TicketSpares')->find()
            ->select([
                'TicketSpares.id',
                'TicketSpares.quantity',
                'TicketSpares.unit_cost_paise',
                'TicketSpares.billing_due_at',
                'TicketSpares.received_at',
                'ticket_no' => 'Tickets.ticket_no',
                'vendor_id' => 'Tickets.vendor_id',
                'part_no' => 'SpareParts.part_no',
                'part_name' => 'SpareParts.name',
            ])
            ->join([
                'Tickets' => [
                    'table' => 'tickets',
                    'type' => 'INNER',
                    'conditions' => 'Tickets.id = TicketSpares.ticket_id',
                ],
                'SpareParts' => [
                    'table' => 'spare_parts',
                    'type' => 'INNER',
                    'conditions' => 'SpareParts.id = TicketSpares.spare_part_id',
                ],
            ])
            ->where($conditions)
            ->orderByAsc('TicketSpares.billing_due_at')
            ->disableHydration()
            ->all()
            ->toList();
    }

    /**
     * Clause 10, properly: stock we are still holding, aged from the day
     * the company shipped it.
     *
     * The agreement's words: spare parts kept above 30 days will be
     * considered as billed, and service centres may keep the same. Kept —
     * not fitted. The report
     * next to this one ages parts already consumed on a ticket, which
     * misses the expensive case entirely: a panel that arrived on a
     * challan and has sat on a shelf ever since is exactly what the clause
     * bills us for, and it appears on no ticket at all.
     *
     * Receipts are consumed oldest-first. That is how a centre physically
     * rotates stock, and it is the only assumption under which naming the
     * challan a remaining unit arrived on is a true statement rather than
     * a guess.
     *
     * @return array{lots: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function stockAgeing(?int $vendorId = null, ?int $serviceCenterId = null, int $warnWithinDays = 5): array
    {
        $query = $this->fetchTable('SpareStockMovements')->find()
            ->select([
                'id' => 'SpareStockMovements.id',
                'spare_part_id' => 'SpareStockMovements.spare_part_id',
                'service_center_id' => 'SpareStockMovements.service_center_id',
                'movement_type' => 'SpareStockMovements.movement_type',
                'quantity' => 'SpareStockMovements.quantity',
                'unit_cost_paise' => 'SpareStockMovements.unit_cost_paise',
                'reference' => 'SpareStockMovements.reference',
                'occurred_at' => 'SpareStockMovements.occurred_at',
                'vendor_id' => 'SpareParts.vendor_id',
                'part_no' => 'SpareParts.part_no',
                'part_name' => 'SpareParts.name',
                'catalogue_cost_paise' => 'SpareParts.cost_paise',
                'service_center_name' => 'ServiceCenters.name',
            ])
            ->join([
                'SpareParts' => [
                    'table' => 'spare_parts',
                    'type' => 'INNER',
                    'conditions' => 'SpareParts.id = SpareStockMovements.spare_part_id',
                ],
                'ServiceCenters' => [
                    'table' => 'service_centers',
                    'type' => 'INNER',
                    'conditions' => 'ServiceCenters.id = SpareStockMovements.service_center_id',
                ],
            ])
            // Transfers are excluded rather than netted. A shelf-to-bag
            // move is two rows that cancel, but only if both are seen in
            // the same pass — and the +n arriving in the bag would push a
            // lot dated today, resetting the age of a part that has not
            // moved an inch closer to being new.
            ->where(['SpareStockMovements.movement_type IN' => array_values(array_unique(
                array_merge(self::INFLOW_TYPES, self::OUTFLOW_TYPES),
            ))])
            ->orderByAsc('SpareStockMovements.occurred_at')
            ->orderByAsc('SpareStockMovements.id');

        if ($vendorId !== null) {
            $query->where(['SpareParts.vendor_id' => $vendorId]);
        }

        if ($serviceCenterId !== null) {
            $query->where(['SpareStockMovements.service_center_id' => $serviceCenterId]);
        }

        /** @var array<string, list<array<string, mixed>>> $open */
        $open = [];

        foreach ($query->disableHydration()->all() as $row) {
            $key = $row['spare_part_id'] . ':' . $row['service_center_id'];
            $open[$key] ??= [];
            $quantity = (int)$row['quantity'];

            if ($quantity > 0) {
                $open[$key][] = ['remaining' => $quantity, 'row' => $row];

                continue;
            }

            $this->drawDown($open[$key], -$quantity);
        }

        $now = DateTime::now();
        $lots = [];
        $totals = ['units' => 0, 'value_paise' => 0, 'overdue_units' => 0, 'overdue_value_paise' => 0];

        foreach ($open as $lotsForKey) {
            foreach ($lotsForKey as $lot) {
                if ($lot['remaining'] < 1) {
                    continue;
                }

                $row = $lot['row'];
                $receivedAt = new DateTime($row['occurred_at']);
                $billingDays = $this->spareBillingDays((int)$row['vendor_id']);
                $dueAt = $receivedAt->addDays($billingDays);
                $ageDays = (int)$receivedAt->diffInDays($now);
                $daysLeft = (int)$now->diffInDays($dueAt, false);

                // Movement cost when the challan carried one, catalogue
                // cost otherwise. Older rows predate the movement being
                // stamped, and a lot valued at zero would read as free
                // stock rather than as missing data.
                $unitCost = (int)($row['unit_cost_paise'] ?? 0) ?: (int)$row['catalogue_cost_paise'];
                $value = $lot['remaining'] * $unitCost;
                $overdue = $daysLeft < 0;

                $totals['units'] += $lot['remaining'];
                $totals['value_paise'] += $value;

                if ($overdue) {
                    $totals['overdue_units'] += $lot['remaining'];
                    $totals['overdue_value_paise'] += $value;
                }

                $lots[] = [
                    'spare_part_id' => (int)$row['spare_part_id'],
                    'part_no' => $row['part_no'],
                    'part_name' => $row['part_name'],
                    'vendor_id' => (int)$row['vendor_id'],
                    'service_center_id' => (int)$row['service_center_id'],
                    'service_center_name' => $row['service_center_name'],
                    'quantity' => $lot['remaining'],
                    'received_at' => $receivedAt->format('Y-m-d H:i:s'),
                    'reference' => $row['reference'],
                    'unit_cost_paise' => $unitCost,
                    'value_paise' => $value,
                    'age_days' => $ageDays,
                    'billing_days' => $billingDays,
                    'due_at' => $dueAt->format('Y-m-d H:i:s'),
                    'days_left' => $daysLeft,
                    // Past the due date the money is already gone and the
                    // row is an explanation. Before it, it is still an
                    // instruction: use this part or send it back.
                    'is_overdue' => $overdue,
                    'is_due_soon' => !$overdue && $daysLeft <= $warnWithinDays,
                ];
            }
        }

        usort($lots, fn(array $a, array $b): int => $a['days_left'] <=> $b['days_left']);

        return ['lots' => $lots, 'totals' => $totals];
    }

    /**
     * Take `$quantity` off the oldest open lots.
     *
     * Consumption with no lot behind it is left to fall on the floor. It
     * means stock went out that was never booked in, which the balance
     * already reports as negative; inventing a lot to absorb it here would
     * age a part that, as far as the ledger knows, we never received.
     *
     * @param list<array{remaining: int, row: array<string, mixed>}> $lots
     */
    private function drawDown(array &$lots, int $quantity): void
    {
        foreach ($lots as $index => $lot) {
            if ($quantity < 1) {
                return;
            }

            $taken = min($lot['remaining'], $quantity);
            $lots[$index]['remaining'] -= $taken;
            $quantity -= $taken;
        }
    }

    /**
     * @param int $vendorId The company whose agreement sets the window.
     * @return int Days we may hold their stock before clause 10 bills it to us.
     */
    private function spareBillingDays(int $vendorId): int
    {
        if (!isset($this->billingDaysByVendor[$vendorId])) {
            try {
                $this->billingDaysByVendor[$vendorId] = $this->rates->agreementTerms($vendorId)->spareBillingDays;
            } catch (RecordNotFoundException) {
                // No agreement on file is not a reason to report no
                // exposure. The platform default is the safer guess, and a
                // company with no terms is a separate problem.
                $this->billingDaysByVendor[$vendorId] = 30;
            }
        }

        return $this->billingDaysByVendor[$vendorId];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordMovement(array $data): int
    {
        $movements = $this->fetchTable('SpareStockMovements');

        $movement = $movements->newEntity($data + [
            'occurred_at' => DateTime::now(),
            'created' => DateTime::now(),
        ]);

        $movements->saveOrFail($movement);

        return (int)$movement->id;
    }
}
