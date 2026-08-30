<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CompanyAgreements Model
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\HasMany $RateCards
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 * @property \App\Model\Table\CompanyInvoicesTable&\Cake\ORM\Association\HasMany $CompanyInvoices
 *
 * @method \App\Model\Entity\CompanyAgreement newEmptyEntity()
 * @method \App\Model\Entity\CompanyAgreement newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyAgreement> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CompanyAgreement get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CompanyAgreement findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CompanyAgreement patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyAgreement> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CompanyAgreement|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CompanyAgreement saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyAgreement>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyAgreement>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyAgreement>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyAgreement> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyAgreement>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyAgreement>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyAgreement>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyAgreement> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CompanyAgreementsTable extends Table
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

        $this->setTable('company_agreements');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', [
            'foreignKey' => 'company_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('RateCards', [
            'foreignKey' => 'company_agreement_id',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'company_agreement_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'company_agreement_id',
        ]);
        $this->hasMany('CompanyInvoices', [
            'foreignKey' => 'company_agreement_id',
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
            ->notEmptyString('company_id');

        $validator
            ->scalar('agreement_no')
            ->maxLength('agreement_no', 64)
            ->requirePresence('agreement_no', 'create')
            ->notEmptyString('agreement_no');

        $validator
            ->scalar('title')
            ->maxLength('title', 190)
            ->allowEmptyString('title');

        $validator
            ->scalar('status')
            ->maxLength('status', 24)
            ->notEmptyString('status');

        $validator
            ->date('signed_on')
            ->allowEmptyDate('signed_on');

        $validator
            ->date('effective_from')
            ->requirePresence('effective_from', 'create')
            ->notEmptyDate('effective_from');

        $validator
            ->date('effective_to')
            ->allowEmptyDate('effective_to');

        $validator
            ->notEmptyString('credit_limit_paise');

        $validator
            ->nonNegativeInteger('invoice_cycle_day')
            ->notEmptyString('invoice_cycle_day');

        $validator
            ->decimal('oow_royalty_pct')
            ->notEmptyString('oow_royalty_pct');

        $validator
            ->decimal('spare_margin_min_pct')
            ->notEmptyString('spare_margin_min_pct');

        $validator
            ->decimal('spare_margin_max_pct')
            ->notEmptyString('spare_margin_max_pct');

        $validator
            ->decimal('travel_free_km')
            ->notEmptyString('travel_free_km');

        $validator
            ->notEmptyString('travel_rate_per_km_paise');

        $validator
            ->nonNegativeInteger('sla_contact_hours')
            ->notEmptyString('sla_contact_hours');

        $validator
            ->nonNegativeInteger('sla_visit_hours')
            ->notEmptyString('sla_visit_hours');

        $validator
            ->nonNegativeInteger('sla_close_hours')
            ->notEmptyString('sla_close_hours');

        $validator
            ->nonNegativeInteger('repeat_complaint_window_days')
            ->notEmptyString('repeat_complaint_window_days');

        $validator
            ->nonNegativeInteger('defective_return_days')
            ->notEmptyString('defective_return_days');

        $validator
            ->nonNegativeInteger('spare_billing_days')
            ->notEmptyString('spare_billing_days');

        $validator
            ->nonNegativeInteger('notice_period_days')
            ->notEmptyString('notice_period_days');

        $validator
            ->scalar('document_path')
            ->maxLength('document_path', 255)
            ->allowEmptyString('document_path');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

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
        $rules->add($rules->isUnique(['company_id', 'agreement_no']), ['errorField' => 'company_id', 'message' => __('This combination of company_id and agreement_no already exists')]);
        $rules->add($rules->existsIn(['company_id'], 'Companies'), ['errorField' => 'company_id']);

        return $rules;
    }
}
