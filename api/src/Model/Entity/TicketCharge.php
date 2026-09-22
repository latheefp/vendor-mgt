<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketCharge Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $line_type
 * @property string $ledger
 * @property string $description
 * @property string|null $quantity
 * @property int|null $unit_amount_paise
 * @property int $amount_paise
 * @property int|null $rate_card_id
 * @property int|null $rate_card_item_id
 * @property int|null $sla_rule_id
 * @property int|null $technician_rate_id
 * @property int|null $ticket_spare_id
 * @property int|null $company_agreement_id
 * @property int|null $technician_expense_type_id
 * @property array|null $calc_snapshot
 * @property \Cake\I18n\DateTime $computed_at
 * @property int|null $computed_by_user_id
 * @property bool $is_frozen
 * @property string $settlement_status
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\RateCard $rate_card
 * @property \App\Model\Entity\RateCardItem $rate_card_item
 * @property \App\Model\Entity\SlaRule $sla_rule
 * @property \App\Model\Entity\TechnicianRate $technician_rate
 * @property \App\Model\Entity\TicketSpare $ticket_spare
 * @property \App\Model\Entity\CompanyAgreement $company_agreement
 * @property \App\Model\Entity\TechnicianExpenseType $technician_expense_type
 * @property \App\Model\Entity\ComputedByUser $computed_by_user
 * @property \App\Model\Entity\TechnicianPayoutLine[] $technician_payout_lines
 * @property \App\Model\Entity\CompanyInvoiceLine[] $company_invoice_lines
 */
class TicketCharge extends Entity
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
        'ticket_id' => true,
        'line_type' => true,
        'ledger' => true,
        'description' => true,
        'quantity' => true,
        'unit_amount_paise' => true,
        'amount_paise' => true,
        'rate_card_id' => true,
        'rate_card_item_id' => true,
        'sla_rule_id' => true,
        'technician_rate_id' => true,
        'ticket_spare_id' => true,
        'company_agreement_id' => true,
        'technician_expense_type_id' => true,
        'calc_snapshot' => true,
        'computed_at' => true,
        'computed_by_user_id' => true,
        'is_frozen' => true,
        'settlement_status' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'ticket' => true,
        'rate_card' => true,
        'rate_card_item' => true,
        'sla_rule' => true,
        'technician_rate' => true,
        'ticket_spare' => true,
        'company_agreement' => true,
        'technician_expense_type' => true,
        'computed_by_user' => true,
        'technician_payout_lines' => true,
        'company_invoice_lines' => true,
    ];
}
