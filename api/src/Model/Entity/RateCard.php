<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * RateCard Entity
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $vendor_agreement_id
 * @property string $name
 * @property int $version
 * @property string $status
 * @property \Cake\I18n\Date $effective_from
 * @property \Cake\I18n\Date|null $effective_to
 * @property string $currency
 * @property \Cake\I18n\DateTime|null $published_at
 * @property int|null $published_by_user_id
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Vendor $vendor
 * @property \App\Model\Entity\VendorAgreement $vendor_agreement
 * @property \App\Model\Entity\PublishedByUser $published_by_user
 * @property \App\Model\Entity\RateCardItem[] $rate_card_items
 * @property \App\Model\Entity\SlaRule[] $sla_rules
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 * @property \App\Model\Entity\Ticket[] $tickets
 */
class RateCard extends Entity
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
        'vendor_id' => true,
        'vendor_agreement_id' => true,
        'name' => true,
        'version' => true,
        'status' => true,
        'effective_from' => true,
        'effective_to' => true,
        'currency' => true,
        'published_at' => true,
        'published_by_user_id' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'vendor' => true,
        'vendor_agreement' => true,
        'published_by_user' => true,
        'rate_card_items' => true,
        'sla_rules' => true,
        'ticket_charges' => true,
        'tickets' => true,
    ];
}
