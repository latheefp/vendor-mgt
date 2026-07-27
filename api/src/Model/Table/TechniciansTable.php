<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Technicians Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\ServiceCentersTable&\Cake\ORM\Association\BelongsTo $ServiceCenters
 * @property \App\Model\Table\CashCollectionsTable&\Cake\ORM\Association\HasMany $CashCollections
 * @property \App\Model\Table\TechnicianPayoutsTable&\Cake\ORM\Association\HasMany $TechnicianPayouts
 * @property \App\Model\Table\TechnicianRatesTable&\Cake\ORM\Association\HasMany $TechnicianRates
 *
 * @method \App\Model\Entity\Technician newEmptyEntity()
 * @method \App\Model\Entity\Technician newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Technician> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Technician get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Technician findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Technician patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Technician> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Technician|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Technician saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Technician>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Technician>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Technician>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Technician> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Technician>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Technician>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Technician>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Technician> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TechniciansTable extends Table
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

        $this->setTable('technicians');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
        $this->belongsTo('ServiceCenters', [
            'foreignKey' => 'service_center_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('CashCollections', [
            'foreignKey' => 'technician_id',
        ]);
        $this->hasMany('TechnicianPayouts', [
            'foreignKey' => 'technician_id',
        ]);
        $this->hasMany('TechnicianRates', [
            'foreignKey' => 'technician_id',
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
            ->allowEmptyString('user_id')
            ->add('user_id', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->notEmptyString('service_center_id');

        $validator
            ->scalar('code')
            ->maxLength('code', 32)
            ->requirePresence('code', 'create')
            ->notEmptyString('code')
            ->add('code', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('name')
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 24)
            ->requirePresence('phone', 'create')
            ->notEmptyString('phone');

        $validator
            ->scalar('alt_phone')
            ->maxLength('alt_phone', 24)
            ->allowEmptyString('alt_phone');

        $validator
            ->email('email')
            ->allowEmptyString('email');

        $validator
            ->scalar('employment_type')
            ->maxLength('employment_type', 24)
            ->notEmptyString('employment_type');

        $validator
            ->date('joined_on')
            ->allowEmptyDate('joined_on');

        $validator
            ->date('exited_on')
            ->allowEmptyDate('exited_on');

        $validator
            ->scalar('address_line1')
            ->maxLength('address_line1', 255)
            ->allowEmptyString('address_line1');

        $validator
            ->scalar('city')
            ->maxLength('city', 96)
            ->allowEmptyString('city');

        $validator
            ->scalar('district')
            ->maxLength('district', 96)
            ->allowEmptyString('district');

        $validator
            ->scalar('pincode')
            ->maxLength('pincode', 12)
            ->allowEmptyString('pincode');

        $validator
            ->allowEmptyString('skills');

        $validator
            ->scalar('id_proof_type')
            ->maxLength('id_proof_type', 32)
            ->allowEmptyString('id_proof_type');

        $validator
            ->scalar('id_proof_number')
            ->maxLength('id_proof_number', 64)
            ->allowEmptyString('id_proof_number');

        $validator
            ->scalar('bank_account_name')
            ->maxLength('bank_account_name', 128)
            ->allowEmptyString('bank_account_name');

        $validator
            ->scalar('bank_account_number')
            ->maxLength('bank_account_number', 32)
            ->allowEmptyString('bank_account_number');

        $validator
            ->scalar('bank_ifsc')
            ->maxLength('bank_ifsc', 16)
            ->allowEmptyString('bank_ifsc');

        $validator
            ->scalar('upi_id')
            ->maxLength('upi_id', 96)
            ->allowEmptyString('upi_id');

        $validator
            ->nonNegativeInteger('max_open_tickets')
            ->notEmptyString('max_open_tickets');

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
        $rules->add($rules->isUnique(['code']), ['errorField' => 'code']);
        $rules->add($rules->isUnique(['user_id'], ['allowMultipleNulls' => true]), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn(['service_center_id'], 'ServiceCenters'), ['errorField' => 'service_center_id']);

        return $rules;
    }
}
