<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * CashCollections Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\BelongsTo $Technicians
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $CollectedByUsers
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $VerifiedByUsers
 *
 * @method \App\Model\Entity\CashCollection newEmptyEntity()
 * @method \App\Model\Entity\CashCollection newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\CashCollection> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\CashCollection get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\CashCollection findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\CashCollection patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\CashCollection> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\CashCollection|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\CashCollection saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\CashCollection>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CashCollection>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CashCollection>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CashCollection> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CashCollection>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CashCollection>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\CashCollection>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\CashCollection> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class CashCollectionsTable extends Table
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

        $this->setTable('cash_collections');
        $this->setDisplayField('method');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Technicians', [
            'foreignKey' => 'technician_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CollectedByUsers', [
            'foreignKey' => 'collected_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('VerifiedByUsers', [
            'foreignKey' => 'verified_by_user_id',
            'className' => 'Users',
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
            ->notEmptyString('ticket_id');

        $validator
            ->notEmptyString('technician_id');

        $validator
            ->allowEmptyString('collected_by_user_id');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->scalar('method')
            ->maxLength('method', 16)
            ->notEmptyString('method');

        $validator
            ->scalar('reference')
            ->maxLength('reference', 96)
            ->allowEmptyString('reference');

        $validator
            ->scalar('receipt_no')
            ->maxLength('receipt_no', 48)
            ->allowEmptyString('receipt_no');

        $validator
            ->dateTime('collected_at')
            ->requirePresence('collected_at', 'create')
            ->notEmptyDateTime('collected_at');

        $validator
            ->dateTime('deposited_at')
            ->allowEmptyDateTime('deposited_at');

        $validator
            ->scalar('deposit_reference')
            ->maxLength('deposit_reference', 96)
            ->allowEmptyString('deposit_reference');

        $validator
            ->allowEmptyString('deposited_paise');

        $validator
            ->notEmptyString('variance_paise');

        $validator
            ->dateTime('verified_at')
            ->allowEmptyDateTime('verified_at');

        $validator
            ->allowEmptyString('verified_by_user_id');

        $validator
            ->scalar('status')
            ->maxLength('status', 24)
            ->notEmptyString('status');

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
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add($rules->existsIn(['technician_id'], 'Technicians'), ['errorField' => 'technician_id']);
        $rules->add($rules->existsIn(['collected_by_user_id'], 'CollectedByUsers'), ['errorField' => 'collected_by_user_id']);
        $rules->add($rules->existsIn(['verified_by_user_id'], 'VerifiedByUsers'), ['errorField' => 'verified_by_user_id']);

        return $rules;
    }
}
