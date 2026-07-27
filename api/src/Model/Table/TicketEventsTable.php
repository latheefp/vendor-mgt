<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketEvents Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $ActorUsers
 *
 * @method \App\Model\Entity\TicketEvent newEmptyEntity()
 * @method \App\Model\Entity\TicketEvent newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketEvent> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketEvent get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketEvent findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketEvent patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketEvent> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketEvent|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketEvent saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketEvent>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketEvent>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketEvent>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketEvent> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketEvent>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketEvent>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketEvent>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketEvent> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketEventsTable extends Table
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

        $this->setTable('ticket_events');
        $this->setDisplayField('event_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ActorUsers', [
            'foreignKey' => 'actor_user_id',
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
            ->scalar('event_type')
            ->maxLength('event_type', 48)
            ->requirePresence('event_type', 'create')
            ->notEmptyString('event_type');

        $validator
            ->scalar('from_status')
            ->maxLength('from_status', 32)
            ->allowEmptyString('from_status');

        $validator
            ->scalar('to_status')
            ->maxLength('to_status', 32)
            ->allowEmptyString('to_status');

        $validator
            ->allowEmptyString('actor_user_id');

        $validator
            ->scalar('actor_role')
            ->maxLength('actor_role', 32)
            ->allowEmptyString('actor_role');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->allowEmptyString('description');

        $validator
            ->allowEmptyString('payload');

        $validator
            ->dateTime('occurred_at')
            ->requirePresence('occurred_at', 'create')
            ->notEmptyDateTime('occurred_at');

        $validator
            ->decimal('latitude')
            ->allowEmptyString('latitude');

        $validator
            ->decimal('longitude')
            ->allowEmptyString('longitude');

        $validator
            ->scalar('ip_address')
            ->maxLength('ip_address', 45)
            ->allowEmptyString('ip_address');

        $validator
            ->scalar('user_agent')
            ->maxLength('user_agent', 255)
            ->allowEmptyString('user_agent');

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
        $rules->add($rules->existsIn(['actor_user_id'], 'ActorUsers'), ['errorField' => 'actor_user_id']);

        return $rules;
    }
}
