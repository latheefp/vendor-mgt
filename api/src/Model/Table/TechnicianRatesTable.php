<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TechnicianRates Model
 *
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\BelongsTo $Technicians
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 *
 * @method \App\Model\Entity\TechnicianRate newEmptyEntity()
 * @method \App\Model\Entity\TechnicianRate newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianRate> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianRate get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TechnicianRate findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TechnicianRate patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TechnicianRate> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TechnicianRate|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TechnicianRate saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianRate>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianRate>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianRate>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianRate> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianRate>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianRate>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TechnicianRate>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TechnicianRate> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TechnicianRatesTable extends Table
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

        $this->setTable('technician_rates');
        $this->setDisplayField('model');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Technicians', [
            'foreignKey' => 'technician_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'technician_rate_id',
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
            ->date('effective_from')
            ->requirePresence('effective_from', 'create')
            ->notEmptyDate('effective_from');

        $validator
            ->date('effective_to')
            ->allowEmptyDate('effective_to');

        $validator
            ->scalar('model')
            ->maxLength('model', 24)
            ->notEmptyString('model');

        $validator
            ->allowEmptyString('flat_amount_paise');

        $validator
            ->decimal('pct_of_company')
            ->allowEmptyString('pct_of_company');

        $validator
            ->allowEmptyString('monthly_salary_paise');

        $validator
            ->decimal('travel_free_km')
            ->notEmptyString('travel_free_km');

        $validator
            ->notEmptyString('travel_rate_per_km_paise');

        $validator
            ->decimal('bonus_share_pct')
            ->notEmptyString('bonus_share_pct');

        $validator
            ->decimal('penalty_recovery_pct')
            ->notEmptyString('penalty_recovery_pct');

        $validator
            ->allowEmptyString('applies_to_job_types');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

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
        $rules->add($rules->existsIn(['technician_id'], 'Technicians'), ['errorField' => 'technician_id']);

        return $rules;
    }
}
