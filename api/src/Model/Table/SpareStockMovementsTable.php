<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * SpareStockMovements Model
 *
 * @property \App\Model\Table\SparePartsTable&\Cake\ORM\Association\BelongsTo $SpareParts
 * @property \App\Model\Table\ServiceCentersTable&\Cake\ORM\Association\BelongsTo $ServiceCenters
 * @property \App\Model\Table\TechniciansTable&\Cake\ORM\Association\BelongsTo $Technicians
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 *
 * @method \App\Model\Entity\SpareStockMovement newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\SpareStockMovement saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class SpareStockMovementsTable extends Table
{
    /**
     * Movement types that are allowed to carry a zero quantity.
     *
     * Sending a defective unit back to the company is a real event with a
     * date and a docket, but it does not move our stock — the part was
     * never ours. Recording it as a movement keeps the obligation and its
     * discharge in one timeline.
     *
     * @var list<string>
     */
    private const ZERO_QUANTITY_TYPES = ['sent_to_company', 'adjustment'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('spare_stock_movements');
        $this->setDisplayField('movement_type');
        $this->setPrimaryKey('id');

        // `created` only — the table has no `modified` column because a
        // movement is never edited.
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('SpareParts', ['foreignKey' => 'spare_part_id', 'joinType' => 'INNER']);
        $this->belongsTo('ServiceCenters', ['foreignKey' => 'service_center_id', 'joinType' => 'INNER']);
        $this->belongsTo('Technicians', ['foreignKey' => 'technician_id']);
        $this->belongsTo('Tickets', ['foreignKey' => 'ticket_id']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('spare_part_id')
            ->notEmptyString('spare_part_id');

        $validator
            ->integer('service_center_id')
            ->notEmptyString('service_center_id');

        $validator
            ->scalar('movement_type')
            ->inList('movement_type', [
                'received', 'issued', 'consumed', 'returned_good',
                'returned_defective', 'sent_to_company', 'written_off', 'adjustment',
            ])
            ->notEmptyString('movement_type');

        $validator
            ->integer('quantity')
            ->notEmptyString('quantity')
            // A zero-quantity movement on a type that should move stock is
            // almost always a caller that forgot to sign the value, and it
            // leaves a balance silently wrong.
            ->add('quantity', 'meaningful', [
                'rule' => function ($value, array $context): bool {
                    $type = $context['data']['movement_type'] ?? '';

                    return (int)$value !== 0 || in_array($type, self::ZERO_QUANTITY_TYPES, true);
                },
                'message' => 'A movement of this type must add to or remove from stock.',
            ]);

        $validator
            ->scalar('serial_no')
            ->maxLength('serial_no', 96)
            ->allowEmptyString('serial_no');

        $validator
            ->scalar('reference')
            ->maxLength('reference', 96)
            ->allowEmptyString('reference');

        $validator
            ->dateTime('occurred_at')
            ->notEmptyDateTime('occurred_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['spare_part_id'], 'SpareParts'), ['errorField' => 'spare_part_id']);
        $rules->add($rules->existsIn(['service_center_id'], 'ServiceCenters'), ['errorField' => 'service_center_id']);
        $rules->add($rules->existsIn(['technician_id'], 'Technicians'), ['errorField' => 'technician_id']);
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);

        return $rules;
    }
}
