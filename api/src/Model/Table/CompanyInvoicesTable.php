<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CompanyInvoices Model
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 * @property \App\Model\Table\CompanyAgreementsTable&\Cake\ORM\Association\BelongsTo $CompanyAgreements
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $CreatedByUsers
 * @property \App\Model\Table\CompanyInvoiceLinesTable&\Cake\ORM\Association\HasMany $CompanyInvoiceLines
 *
 * @method \App\Model\Entity\CompanyInvoice newEmptyEntity()
 * @method \App\Model\Entity\CompanyInvoice newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyInvoice> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CompanyInvoice get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CompanyInvoice findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CompanyInvoice patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CompanyInvoice> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CompanyInvoice|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CompanyInvoice saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyInvoice>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyInvoice>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyInvoice>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyInvoice> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyInvoice>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyInvoice>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CompanyInvoice>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CompanyInvoice> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CompanyInvoicesTable extends Table
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

        $this->setTable('company_invoices');
        $this->setDisplayField('invoice_no');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', [
            'foreignKey' => 'company_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CompanyAgreements', [
            'foreignKey' => 'company_agreement_id',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'foreignKey' => 'created_by_user_id',
            'className' => 'Users',
        ]);
        $this->hasMany('CompanyInvoiceLines', [
            'foreignKey' => 'company_invoice_id',
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
            ->allowEmptyString('company_agreement_id');

        $validator
            ->scalar('invoice_no')
            ->maxLength('invoice_no', 48)
            ->requirePresence('invoice_no', 'create')
            ->notEmptyString('invoice_no')
            ->add('invoice_no', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->date('period_start')
            ->requirePresence('period_start', 'create')
            ->notEmptyDate('period_start');

        $validator
            ->date('period_end')
            ->requirePresence('period_end', 'create')
            ->notEmptyDate('period_end');

        $validator
            ->date('cycle_date')
            ->allowEmptyDate('cycle_date');

        $validator
            ->scalar('status')
            ->maxLength('status', 24)
            ->notEmptyString('status');

        $validator
            ->notEmptyString('subtotal_paise');

        $validator
            ->notEmptyString('sla_bonus_paise');

        $validator
            ->notEmptyString('sla_penalty_paise');

        $validator
            ->notEmptyString('travel_paise');

        $validator
            ->notEmptyString('spare_paise');

        $validator
            ->notEmptyString('royalty_paise');

        $validator
            ->notEmptyString('total_paise');

        $validator
            ->notEmptyString('paid_paise');

        $validator
            ->nonNegativeInteger('ticket_count')
            ->notEmptyString('ticket_count');

        $validator
            ->dateTime('sent_at')
            ->allowEmptyDateTime('sent_at');

        $validator
            ->scalar('sent_to_email')
            ->maxLength('sent_to_email', 190)
            ->allowEmptyString('sent_to_email');

        $validator
            ->scalar('message_id')
            ->maxLength('message_id', 190)
            ->allowEmptyString('message_id');

        $validator
            ->date('due_at')
            ->allowEmptyDate('due_at');

        $validator
            ->dateTime('paid_at')
            ->allowEmptyDateTime('paid_at');

        $validator
            ->scalar('payment_reference')
            ->maxLength('payment_reference', 96)
            ->allowEmptyString('payment_reference');

        $validator
            ->dateTime('disputed_at')
            ->allowEmptyDateTime('disputed_at');

        $validator
            ->scalar('dispute_notes')
            ->allowEmptyString('dispute_notes');

        $validator
            ->scalar('pdf_path')
            ->maxLength('pdf_path', 512)
            ->allowEmptyString('pdf_path');

        $validator
            ->allowEmptyString('created_by_user_id');

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
        $rules->add($rules->isUnique(['invoice_no']), ['errorField' => 'invoice_no']);
        $rules->add($rules->existsIn(['company_id'], 'Companies'), ['errorField' => 'company_id']);
        $rules->add($rules->existsIn(['company_agreement_id'], 'CompanyAgreements'), ['errorField' => 'company_agreement_id']);
        $rules->add($rules->existsIn(['created_by_user_id'], 'CreatedByUsers'), ['errorField' => 'created_by_user_id']);

        return $rules;
    }
}
