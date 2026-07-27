<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketSpare Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $spare_part_id
 * @property int $quantity
 * @property string|null $serial_no
 * @property int $unit_cost_paise
 * @property string $margin_pct
 * @property int $unit_price_paise
 * @property int $line_total_paise
 * @property string $charged_to
 * @property bool $is_defective_return
 * @property \Cake\I18n\DateTime|null $defective_return_due_at
 * @property \Cake\I18n\DateTime|null $defective_returned_at
 * @property \Cake\I18n\DateTime|null $received_at
 * @property \Cake\I18n\DateTime|null $billing_due_at
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\SparePart $spare_part
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 */
class TicketSpare extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'issued_from_center_id' => true,
        'issued_to_technician_id' => true,
        'issued_at' => true,
        'defective_return_reference' => true,
        'defective_credit_received_at' => true,
        'ticket_id' => true,
        'spare_part_id' => true,
        'quantity' => true,
        'serial_no' => true,
        'unit_cost_paise' => true,
        'margin_pct' => true,
        'unit_price_paise' => true,
        'line_total_paise' => true,
        'charged_to' => true,
        'is_defective_return' => true,
        'defective_return_due_at' => true,
        'defective_returned_at' => true,
        'received_at' => true,
        'billing_due_at' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'ticket' => true,
        'spare_part' => true,
        'ticket_charges' => true,
    ];
}
