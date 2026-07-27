<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TechnicianPayouts Model
 *
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\BelongsTo $Technicians
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $ApprovedByUsers
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $CreatedByUsers
 * @property \App\Model\Table\TechnicianPayoutLinesTable&\Cake\ORM\Association\HasMany $TechnicianPayoutLines
 *
 * @method \App\Model\Entity\TechnicianPayout newEmptyEntity()
 * @method \App\Model\Entity\TechnicianPayout newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianPayout> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianPayout get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TechnicianPayout findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TechnicianPayout patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianPayout> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianPayout|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TechnicianPayout saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayout>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayout>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayout>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayout> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayout>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayout>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianPayout>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianPayout> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TechnicianPayoutsTable extends Table
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

        $this->setTable('technician_payouts');
        $this->setDisplayField('payout_no');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Technicians', [
            'foreignKey' => 'technician_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApprovedByUsers', [
            'foreignKey' => 'approved_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'foreignKey' => 'created_by_user_id',
            'className' => 'Users',
        ]);
        $this->hasMany('TechnicianPayoutLines', [
            'foreignKey' => 'technician_payout_id',
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
            ->notEmptyString('technician_id');

        $validator
            ->scalar('payout_no')
            ->maxLength('payout_no', 48)
            ->requirePresence('payout_no', 'create')
            ->notEmptyString('payout_no')
            ->add('payout_no', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->date('period_start')
            ->requirePresence('period_start', 'create')
            ->notEmptyDate('period_start');

        $validator
            ->date('period_end')
            ->requirePresence('period_end', 'create')
            ->notEmptyDate('period_end');

        $validator
            ->scalar('status')
            ->maxLength('status', 24)
            ->notEmptyString('status');

        $validator
            ->notEmptyString('gross_paise');

        $validator
            ->notEmptyString('bonus_paise');

        $validator
            ->notEmptyString('travel_paise');

        $validator
            ->notEmptyString('penalty_recovery_paise');

        $validator
            ->notEmptyString('advance_recovery_paise');

        $validator
            ->notEmptyString('deductions_paise');

        $validator
            ->notEmptyString('net_paise');

        $validator
            ->nonNegativeInteger('ticket_count')
            ->notEmptyString('ticket_count');

        $validator
            ->dateTime('approved_at')
            ->allowEmptyDateTime('approved_at');

        $validator
            ->allowEmptyString('approved_by_user_id');

        $validator
            ->dateTime('paid_at')
            ->allowEmptyDateTime('paid_at');

        $validator
            ->scalar('payment_method')
            ->maxLength('payment_method', 24)
            ->allowEmptyString('payment_method');

        $validator
            ->scalar('payment_reference')
            ->maxLength('payment_reference', 96)
            ->allowEmptyString('payment_reference');

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
        $rules->add($rules->isUnique(['payout_no']), ['errorField' => 'payout_no']);
        $rules->add($rules->existsIn(['technician_id'], 'Technicians'), ['errorField' => 'technician_id']);
        $rules->add($rules->existsIn(['approved_by_user_id'], 'ApprovedByUsers'), ['errorField' => 'approved_by_user_id']);
        $rules->add($rules->existsIn(['created_by_user_id'], 'CreatedByUsers'), ['errorField' => 'created_by_user_id']);

        return $rules;
    }
}
