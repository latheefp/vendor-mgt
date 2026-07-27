<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * VendorInvoiceLines Model
 *
 * @property \App\Model\Table\VendorInvoicesTable&\Cake\ORM\Association\BelongsTo $VendorInvoices
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\BelongsTo $TicketCharges
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 *
 * @method \App\Model\Entity\VendorInvoiceLine newEmptyEntity()
 * @method \App\Model\Entity\VendorInvoiceLine newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\VendorInvoiceLine> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\VendorInvoiceLine get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\VendorInvoiceLine findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\VendorInvoiceLine patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\VendorInvoiceLine> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\VendorInvoiceLine|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\VendorInvoiceLine saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\VendorInvoiceLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorInvoiceLine>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorInvoiceLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorInvoiceLine> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorInvoiceLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorInvoiceLine>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\VendorInvoiceLine>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\VendorInvoiceLine> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class VendorInvoiceLinesTable extends Table
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

        $this->setTable('vendor_invoice_lines');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('VendorInvoices', [
            'foreignKey' => 'vendor_invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('TicketCharges', [
            'foreignKey' => 'ticket_charge_id',
        ]);
        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->belongsTo('OverriddenByUsers', [
            'foreignKey' => 'overridden_by_user_id',
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
            ->notEmptyString('vendor_invoice_id');

        $validator
            ->allowEmptyString('ticket_charge_id');

        $validator
            ->allowEmptyString('ticket_id');

        $validator
            ->scalar('line_type')
            ->maxLength('line_type', 32)
            ->notEmptyString('line_type');

        $validator
            ->scalar('ledger')
            ->maxLength('ledger', 32)
            ->allowEmptyString('ledger');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->requirePresence('description', 'create')
            ->notEmptyString('description');

        $validator
            ->decimal('quantity')
            ->allowEmptyString('quantity');

        $validator
            ->allowEmptyString('unit_amount_paise');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->allowEmptyString('original_amount_paise');

        // Deliberately not required at this layer: a line is only ever
        // written with a reason through the service that restates it, and
        // requiring it here would reject every line raised by a normal run.
        $validator
            ->scalar('override_reason')
            ->maxLength('override_reason', 255)
            ->allowEmptyString('override_reason');

        $validator
            ->dateTime('overridden_at')
            ->allowEmptyDateTime('overridden_at');

        $validator
            ->allowEmptyString('overridden_by_user_id');

        $validator
            ->nonNegativeInteger('sort_order')
            ->notEmptyString('sort_order');

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
        $rules->add($rules->existsIn(['vendor_invoice_id'], 'VendorInvoices'), ['errorField' => 'vendor_invoice_id']);
        $rules->add($rules->existsIn(['ticket_charge_id'], 'TicketCharges'), ['errorField' => 'ticket_charge_id']);
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add(
            $rules->existsIn(['overridden_by_user_id'], 'OverriddenByUsers'),
            ['errorField' => 'overridden_by_user_id'],
        );

        return $rules;
    }
}
