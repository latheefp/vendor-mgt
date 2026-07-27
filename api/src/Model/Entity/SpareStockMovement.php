<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * SpareStockMovement Entity
 *
 * One line of the stock ledger. `quantity` is signed — positive adds to
 * the location, negative removes — so a balance is a SUM rather than a
 * subtraction that has to know which way each movement type points.
 *
 * Append-only in practice: nothing in the application updates a movement.
 * A mistake is corrected with an `adjustment` in the opposite direction,
 * which keeps the history of what was believed at the time.
 *
 * @property int $id
 * @property int $spare_part_id
 * @property int $service_center_id
 * @property int|null $technician_id
 * @property int|null $ticket_id
 * @property string $movement_type
 * @property int $quantity
 * @property string|null $serial_no
 * @property int|null $unit_cost_paise
 * @property string|null $reference
 * @property \Cake\I18n\DateTime $occurred_at
 * @property int|null $actor_user_id
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 */
class SpareStockMovement extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'spare_part_id' => true,
        'service_center_id' => true,
        'technician_id' => true,
        'ticket_id' => true,
        'movement_type' => true,
        'quantity' => true,
        'serial_no' => true,
        'unit_cost_paise' => true,
        'reference' => true,
        'occurred_at' => true,
        'actor_user_id' => true,
        'notes' => true,
        'created' => true,
    ];
}
