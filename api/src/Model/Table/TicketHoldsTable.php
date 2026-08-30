<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketHolds Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $StartedByUsers
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $EndedByUsers
 * @property \App\Model\Table\HoldReasonsTable&\Cake\ORM\Association\BelongsTo $HoldReasons
 *
 * @method \App\Model\Entity\TicketHold newEmptyEntity()
 * @method \App\Model\Entity\TicketHold newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketHold> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketHold get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketHold findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketHold patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketHold> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketHold|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketHold saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHold>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHold>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHold>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHold> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHold>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHold>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHold>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHold> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketHoldsTable extends Table
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

        $this->setTable('ticket_holds');
        $this->setDisplayField('reason_code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('StartedByUsers', [
            'foreignKey' => 'started_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('EndedByUsers', [
            'foreignKey' => 'ended_by_user_id',
            'className' => 'Users',
        ]);
        $this->belongsTo('HoldReasons', [
            'foreignKey' => 'hold_reason_id',
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
            ->scalar('reason_code')
            ->maxLength('reason_code', 48)
            ->requirePresence('reason_code', 'create')
            ->notEmptyString('reason_code');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

        $validator
            ->dateTime('started_at')
            ->requirePresence('started_at', 'create')
            ->notEmptyDateTime('started_at');

        $validator
            ->dateTime('ended_at')
            ->allowEmptyDateTime('ended_at');

        $validator
            ->nonNegativeInteger('paused_minutes')
            ->allowEmptyString('paused_minutes');

        $validator
            ->allowEmptyString('started_by_user_id');

        $validator
            ->allowEmptyString('ended_by_user_id');

        $validator
            ->dateTime('company_notified_at')
            ->allowEmptyDateTime('company_notified_at');

        $validator
            ->scalar('company_notification_message_id')
            ->maxLength('company_notification_message_id', 190)
            ->allowEmptyString('company_notification_message_id');

        $validator
            ->allowEmptyString('hold_reason_id');

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
        $rules->add($rules->existsIn(['started_by_user_id'], 'StartedByUsers'), ['errorField' => 'started_by_user_id']);
        $rules->add($rules->existsIn(['ended_by_user_id'], 'EndedByUsers'), ['errorField' => 'ended_by_user_id']);
        $rules->add($rules->existsIn(['hold_reason_id'], 'HoldReasons'), ['errorField' => 'hold_reason_id']);

        return $rules;
    }
}
