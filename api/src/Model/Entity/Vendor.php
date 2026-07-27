<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Vendor Entity
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $support_phone
 * @property string|null $communication_email
 * @property string|null $accounts_email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $pincode
 * @property string|null $website
 * @property string|null $logo_path
 * @property bool $is_active
 * @property \Cake\I18n\Date|null $onboarded_on
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Brand[] $brands
 * @property \App\Model\Entity\Product[] $products
 * @property \App\Model\Entity\RateCard[] $rate_cards
 * @property \App\Model\Entity\SparePart[] $spare_parts
 * @property \App\Model\Entity\Ticket[] $tickets
 * @property \App\Model\Entity\VendorAgreement[] $vendor_agreements
 * @property \App\Model\Entity\VendorInvoice[] $vendor_invoices
 * @property \App\Model\Entity\VendorJobTypeAlias[] $vendor_job_type_aliases
 */
class Vendor extends Entity
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
        'code' => true,
        'name' => true,
        'legal_name' => true,
        'gstin' => true,
        'contact_person' => true,
        'phone' => true,
        'support_phone' => true,
        'communication_email' => true,
        'accounts_email' => true,
        'address_line1' => true,
        'address_line2' => true,
        'city' => true,
        'state' => true,
        'pincode' => true,
        'website' => true,
        'logo_path' => true,
        'is_active' => true,
        'onboarded_on' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'brands' => true,
        'products' => true,
        'rate_cards' => true,
        'spare_parts' => true,
        'tickets' => true,
        'vendor_agreements' => true,
        'vendor_invoices' => true,
        'vendor_job_type_aliases' => true,
    ];
}
