<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketSpares Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\SparePartsTable&\Cake\ORM\Association\BelongsTo $SpareParts
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 *
 * @method \App\Model\Entity\TicketSpare newEmptyEntity()
 * @method \App\Model\Entity\TicketSpare newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketSpare> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketSpare get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketSpare findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketSpare patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketSpare> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketSpare|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketSpare saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketSpare>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketSpare>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketSpare>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketSpare> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketSpare>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketSpare>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketSpare>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketSpare> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketSparesTable extends Table
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

        $this->setTable('ticket_spares');
        $this->setDisplayField('charged_to');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SpareParts', [
            'foreignKey' => 'spare_part_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'ticket_spare_id',
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
            ->notEmptyString('spare_part_id');

        $validator
            ->nonNegativeInteger('quantity')
            ->notEmptyString('quantity');

        $validator
            ->scalar('serial_no')
            ->maxLength('serial_no', 96)
            ->allowEmptyString('serial_no');

        $validator
            ->notEmptyString('unit_cost_paise');

        $validator
            ->decimal('margin_pct')
            ->notEmptyString('margin_pct');

        $validator
            ->notEmptyString('unit_price_paise');

        $validator
            ->notEmptyString('line_total_paise');

        $validator
            ->scalar('charged_to')
            ->maxLength('charged_to', 16)
            ->notEmptyString('charged_to');

        $validator
            ->boolean('is_defective_return')
            ->notEmptyString('is_defective_return');

        $validator
            ->dateTime('defective_return_due_at')
            ->allowEmptyDateTime('defective_return_due_at');

        $validator
            ->dateTime('defective_returned_at')
            ->allowEmptyDateTime('defective_returned_at');

        $validator
            ->dateTime('received_at')
            ->allowEmptyDateTime('received_at');

        $validator
            ->dateTime('billing_due_at')
            ->allowEmptyDateTime('billing_due_at');

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
        $rules->add($rules->existsIn(['spare_part_id'], 'SpareParts'), ['errorField' => 'spare_part_id']);

        return $rules;
    }
}
