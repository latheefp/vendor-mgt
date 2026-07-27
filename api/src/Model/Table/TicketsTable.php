<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Tickets Model
 *
 * @property \App\Model\Table\VendorsTable&\Cake\ORM\Association\BelongsTo $Vendors
 * @property \App\Model\Table\VendorAgreementsTable&\Cake\ORM\Association\BelongsTo $VendorAgreements
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\BelongsTo $RateCards
 * @property \App\Model\Table\ServiceCentersTable&\Cake\ORM\Association\BelongsTo $ServiceCenters
 * @property \App\Model\Table\CustomersTable&\Cake\ORM\Association\BelongsTo $Customers
 * @property \App\Model\Table\ProductsTable&\Cake\ORM\Association\BelongsTo $Products
 * @property \App\Model\Table\ProductCategoriesTable&\Cake\ORM\Association\BelongsTo $ProductCategories
 * @property \App\Model\Table\JobTypesTable&\Cake\ORM\Association\BelongsTo $JobTypes
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\BelongsTo $AssignedTechnicians
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $AssignedByUsers
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $ParentTickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $CreatedByUsers
 * @property \App\Model\Table\BrandsTable&\Cake\ORM\Association\BelongsTo $Brands
 * @property \App\Model\Table\SymptomsTable&\Cake\ORM\Association\BelongsTo $Symptoms
 * @property \App\Model\Table\ResolutionsTable&\Cake\ORM\Association\BelongsTo $Resolutions
 * @property \App\Model\Table\CashCollectionsTable&\Cake\ORM\Association\HasMany $CashCollections
 * @property \App\Model\Table\TechnicianPayoutLinesTable&\Cake\ORM\Association\HasMany $TechnicianPayoutLines
 * @property \App\Model\Table\TicketAttachmentsTable&\Cake\ORM\Association\HasMany $TicketAttachments
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 * @property \App\Model\Table\TicketEventsTable&\Cake\ORM\Association\HasMany $TicketEvents
 * @property \App\Model\Table\TicketHoldsTable&\Cake\ORM\Association\HasMany $TicketHolds
 * @property \App\Model\Table\TicketSparesTable&\Cake\ORM\Association\HasMany $TicketSpares
 * @property \App\Model\Table\VendorInvoiceLinesTable&\Cake\ORM\Association\HasMany $VendorInvoiceLines
 *
 * @method \App\Model\Entity\Ticket newEmptyEntity()
 * @method \App\Model\Entity\Ticket newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Ticket> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Ticket get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Ticket findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Ticket patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Ticket> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Ticket|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Ticket saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tickets');
        $this->setDisplayField('ticket_no');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Vendors', [
            'foreignKey' => 'vendor_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('VendorAgreements', [
            'foreignKey' => 'vendor_agreement_id',
        ]);
        $this->belongsTo('RateCards', [
            'foreignKey' => 'rate_card_id',
        ]);
        $this->belongsTo('ServiceCenters', [
            'foreignKey' => 'service_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Products', [
            'foreignKey' => 'product_id',
        ]);
        $this->belongsTo('ProductCategories', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->belongsTo('JobTypes', [
            'foreignKey' => 'job_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('AssignedTechnicians', [
            'foreignKey' => 'assigned_technician_id',
            'className' => 'Technicians',
        ]);
        $this->belongsTo('AssignedByUsers', [
            'foreignKey' => 'assigned_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('ParentTickets', [
            'foreignKey' => 'parent_ticket_id',
            'className' => 'Tickets',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'foreignKey' => 'created_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('Brands', [
            'foreignKey' => 'brand_id',
        ]);
        $this->belongsTo('Symptoms', [
            'foreignKey' => 'symptom_id',
        ]);
        $this->belongsTo('Resolutions', [
            'foreignKey' => 'resolution_id',
        ]);
        $this->hasMany('CashCollections', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TechnicianPayoutLines', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketAttachments', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketEvents', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketHolds', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketSpares', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('VendorInvoiceLines', [
            'foreignKey' => 'ticket_id',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('ticket_no')
            ->maxLength('ticket_no', 32)
            ->requirePresence('ticket_no', 'create')
            ->notEmptyString('ticket_no')
            ->add('ticket_no', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->notEmptyString('vendor_id');

        $validator
            ->scalar('vendor_ticket_ref')
            ->maxLength('vendor_ticket_ref', 64)
            ->allowEmptyString('vendor_ticket_ref');

        $validator
            ->allowEmptyString('vendor_agreement_id');

        $validator
            ->allowEmptyString('rate_card_id');

        $validator
            ->notEmptyString('service_center_id');

        $validator
            ->notEmptyString('customer_id');

        $validator
            ->allowEmptyString('product_id');

        $validator
            ->allowEmptyString('product_category_id');

        $validator
            ->scalar('model_no')
            ->maxLength('model_no', 96)
            ->allowEmptyString('model_no');

        $validator
            ->scalar('serial_no')
            ->maxLength('serial_no', 96)
            ->allowEmptyString('serial_no');

        $validator
            ->decimal('size_inch')
            ->allowEmptyString('size_inch');

        $validator
            ->date('purchase_date')
            ->allowEmptyDate('purchase_date');

        $validator
            ->scalar('warranty_scope')
            ->maxLength('warranty_scope', 24)
            ->notEmptyString('warranty_scope');

        $validator
            ->notEmptyString('job_type_id');

        $validator
            ->scalar('status')
            ->maxLength('status', 32)
            ->notEmptyString('status');

        $validator
            ->scalar('priority')
            ->maxLength('priority', 16)
            ->notEmptyString('priority');

        $validator
            ->scalar('reported_issue')
            ->allowEmptyString('reported_issue');

        $validator
            ->scalar('diagnosis')
            ->allowEmptyString('diagnosis');

        $validator
            ->scalar('closure_notes')
            ->allowEmptyString('closure_notes');

        $validator
            ->allowEmptyString('assigned_technician_id');

        $validator
            ->dateTime('assigned_at')
            ->allowEmptyDateTime('assigned_at');

        $validator
            ->allowEmptyString('assigned_by_user_id');

        $validator
            ->dateTime('received_at')
            ->requirePresence('received_at', 'create')
            ->notEmptyDateTime('received_at');

        $validator
            ->dateTime('first_contact_at')
            ->allowEmptyDateTime('first_contact_at');

        $validator
            ->dateTime('contact_due_at')
            ->allowEmptyDateTime('contact_due_at');

        $validator
            ->dateTime('visit_due_at')
            ->allowEmptyDateTime('visit_due_at');

        $validator
            ->dateTime('close_due_at')
            ->allowEmptyDateTime('close_due_at');

        $validator
            ->dateTime('visited_at')
            ->allowEmptyDateTime('visited_at');

        $validator
            ->dateTime('closed_at')
            ->allowEmptyDateTime('closed_at');

        $validator
            ->nonNegativeInteger('sla_paused_minutes')
            ->notEmptyString('sla_paused_minutes');

        $validator
            ->boolean('contact_sla_met')
            ->allowEmptyString('contact_sla_met');

        $validator
            ->boolean('visit_sla_met')
            ->allowEmptyString('visit_sla_met');

        $validator
            ->boolean('close_sla_met')
            ->allowEmptyString('close_sla_met');

        $validator
            ->dateTime('checkin_at')
            ->allowEmptyDateTime('checkin_at');

        $validator
            ->decimal('checkin_latitude')
            ->allowEmptyString('checkin_latitude');

        $validator
            ->decimal('checkin_longitude')
            ->allowEmptyString('checkin_longitude');

        $validator
            ->nonNegativeInteger('checkin_accuracy_m')
            ->allowEmptyString('checkin_accuracy_m');

        $validator
            ->nonNegativeInteger('checkin_distance_m')
            ->allowEmptyString('checkin_distance_m');

        $validator
            ->dateTime('checkout_at')
            ->allowEmptyDateTime('checkout_at');

        $validator
            ->decimal('travel_km')
            ->allowEmptyString('travel_km');

        $validator
            ->scalar('closure_otp_hash')
            ->maxLength('closure_otp_hash', 255)
            ->allowEmptyString('closure_otp_hash');

        $validator
            ->dateTime('closure_otp_sent_at')
            ->allowEmptyDateTime('closure_otp_sent_at');

        $validator
            ->nonNegativeInteger('closure_otp_attempts')
            ->notEmptyString('closure_otp_attempts');

        $validator
            ->dateTime('closure_otp_verified_at')
            ->allowEmptyDateTime('closure_otp_verified_at');

        $validator
            ->allowEmptyString('parent_ticket_id');

        $validator
            ->boolean('is_repeat')
            ->notEmptyString('is_repeat');

        $validator
            ->nonNegativeInteger('reopened_count')
            ->notEmptyString('reopened_count');

        $validator
            ->dateTime('vendor_submitted_at')
            ->allowEmptyDateTime('vendor_submitted_at');

        $validator
            ->dateTime('vendor_approved_at')
            ->allowEmptyDateTime('vendor_approved_at');

        $validator
            ->dateTime('vendor_rejected_at')
            ->allowEmptyDateTime('vendor_rejected_at');

        $validator
            ->scalar('vendor_rejection_reason')
            ->maxLength('vendor_rejection_reason', 255)
            ->allowEmptyString('vendor_rejection_reason');

        $validator
            ->dateTime('charges_computed_at')
            ->allowEmptyDateTime('charges_computed_at');

        $validator
            ->dateTime('charges_frozen_at')
            ->allowEmptyDateTime('charges_frozen_at');

        $validator
            ->dateTime('cancelled_at')
            ->allowEmptyDateTime('cancelled_at');

        $validator
            ->scalar('cancellation_reason')
            ->maxLength('cancellation_reason', 255)
            ->allowEmptyString('cancellation_reason');

        $validator
            ->scalar('source')
            ->maxLength('source', 24)
            ->notEmptyString('source');

        $validator
            ->allowEmptyString('created_by_user_id');

        $validator
            ->allowEmptyString('brand_id');

        $validator
            ->allowEmptyString('symptom_id');

        $validator
            ->allowEmptyString('resolution_id');

        $validator
            ->scalar('vendor_branch_label')
            ->maxLength('vendor_branch_label', 128)
            ->allowEmptyString('vendor_branch_label');

        $validator
            ->scalar('vendor_complaint_type')
            ->maxLength('vendor_complaint_type', 96)
            ->allowEmptyString('vendor_complaint_type');

        $validator
            ->boolean('video_proof_required')
            ->notEmptyString('video_proof_required');

        $validator
            ->dateTime('video_proof_received_at')
            ->allowEmptyDateTime('video_proof_received_at');

        $validator
            ->allowEmptyString('vendor_payload');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['ticket_no']), ['errorField' => 'ticket_no']);
        $rules->add($rules->isUnique(['vendor_id', 'vendor_ticket_ref'], ['allowMultipleNulls' => true]), ['errorField' => 'vendor_id', 'message' => __('This combination of vendor_id and vendor_ticket_ref already exists')]);
        $rules->add($rules->existsIn(['vendor_id'], 'Vendors'), ['errorField' => 'vendor_id']);
        $rules->add($rules->existsIn(['vendor_agreement_id'], 'VendorAgreements'), ['errorField' => 'vendor_agreement_id']);
        $rules->add($rules->existsIn(['rate_card_id'], 'RateCards'), ['errorField' => 'rate_card_id']);
        $rules->add($rules->existsIn(['service_center_id'], 'ServiceCenters'), ['errorField' => 'service_center_id']);
        $rules->add($rules->existsIn(['customer_id'], 'Customers'), ['errorField' => 'customer_id']);
        $rules->add($rules->existsIn(['product_id'], 'Products'), ['errorField' => 'product_id']);
        $rules->add($rules->existsIn(['product_category_id'], 'ProductCategories'), ['errorField' => 'product_category_id']);
        $rules->add($rules->existsIn(['job_type_id'], 'JobTypes'), ['errorField' => 'job_type_id']);
        $rules->add($rules->existsIn(['assigned_technician_id'], 'AssignedTechnicians'), ['errorField' => 'assigned_technician_id']);
        $rules->add($rules->existsIn(['assigned_by_user_id'], 'AssignedByUsers'), ['errorField' => 'assigned_by_user_id']);
        $rules->add($rules->existsIn(['parent_ticket_id'], 'ParentTickets'), ['errorField' => 'parent_ticket_id']);
        $rules->add($rules->existsIn(['created_by_user_id'], 'CreatedByUsers'), ['errorField' => 'created_by_user_id']);
        $rules->add($rules->existsIn(['brand_id'], 'Brands'), ['errorField' => 'brand_id']);
        $rules->add($rules->existsIn(['symptom_id'], 'Symptoms'), ['errorField' => 'symptom_id']);
        $rules->add($rules->existsIn(['resolution_id'], 'Resolutions'), ['errorField' => 'resolution_id']);

        return $rules;
    }
}
