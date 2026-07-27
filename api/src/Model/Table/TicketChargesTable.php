<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketCharges Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\BelongsTo $RateCards
 * @property \App\Model\Table\RateCardItemsTable&\Cake\ORM\Association\BelongsTo $RateCardItems
 * @property \App\Model\Table\SlaRulesTable&\Cake\ORM\Association\BelongsTo $SlaRules
 * @property \App\Model\Table\TechnicianRatesTable&\Cake\ORM\Association\BelongsTo $TechnicianRates
 * @property \App\Model\Table\TicketSparesTable&\Cake\ORM\Association\BelongsTo $TicketSpares
 * @property \App\Model\Table\VendorAgreementsTable&\Cake\ORM\Association\BelongsTo $VendorAgreements
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $ComputedByUsers
 * @property \App\Model\Table\TechnicianPayoutLinesTable&\Cake\ORM\Association\HasMany $TechnicianPayoutLines
 * @property \App\Model\Table\VendorInvoiceLinesTable&\Cake\ORM\Association\HasMany $VendorInvoiceLines
 *
 * @method \App\Model\Entity\TicketCharge newEmptyEntity()
 * @method \App\Model\Entity\TicketCharge newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketCharge> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketCharge get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketCharge findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketCharge patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketCharge> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketCharge|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketCharge saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketCharge>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketCharge>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketCharge>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketCharge> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketCharge>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketCharge>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketCharge>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketCharge> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketChargesTable extends Table
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

        $this->setTable('ticket_charges');
        $this->setDisplayField('line_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('RateCards', [
            'foreignKey' => 'rate_card_id',
        ]);
        $this->belongsTo('RateCardItems', [
            'foreignKey' => 'rate_card_item_id',
        ]);
        $this->belongsTo('SlaRules', [
            'foreignKey' => 'sla_rule_id',
        ]);
        $this->belongsTo('TechnicianRates', [
            'foreignKey' => 'technician_rate_id',
        ]);
        $this->belongsTo('TicketSpares', [
            'foreignKey' => 'ticket_spare_id',
        ]);
        $this->belongsTo('VendorAgreements', [
            'foreignKey' => 'vendor_agreement_id',
        ]);
        $this->belongsTo('ComputedByUsers', [
            'foreignKey' => 'computed_by_user_id',
            'className' => 'Users',
        ]);
        $this->hasMany('TechnicianPayoutLines', [
            'foreignKey' => 'ticket_charge_id',
        ]);
        $this->hasMany('VendorInvoiceLines', [
            'foreignKey' => 'ticket_charge_id',
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
            ->scalar('line_type')
            ->maxLength('line_type', 40)
            ->requirePresence('line_type', 'create')
            ->notEmptyString('line_type');

        $validator
            ->scalar('ledger')
            ->maxLength('ledger', 32)
            ->requirePresence('ledger', 'create')
            ->notEmptyString('ledger');

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
            ->allowEmptyString('rate_card_id');

        $validator
            ->allowEmptyString('rate_card_item_id');

        $validator
            ->allowEmptyString('sla_rule_id');

        $validator
            ->allowEmptyString('technician_rate_id');

        $validator
            ->allowEmptyString('ticket_spare_id');

        $validator
            ->allowEmptyString('vendor_agreement_id');

        $validator
            ->allowEmptyString('calc_snapshot');

        $validator
            ->dateTime('computed_at')
            ->requirePresence('computed_at', 'create')
            ->notEmptyDateTime('computed_at');

        $validator
            ->allowEmptyString('computed_by_user_id');

        $validator
            ->boolean('is_frozen')
            ->notEmptyString('is_frozen');

        $validator
            ->scalar('settlement_status')
            ->maxLength('settlement_status', 24)
            ->notEmptyString('settlement_status');

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
        $rules->add($rules->existsIn(['rate_card_id'], 'RateCards'), ['errorField' => 'rate_card_id']);
        $rules->add($rules->existsIn(['rate_card_item_id'], 'RateCardItems'), ['errorField' => 'rate_card_item_id']);
        $rules->add($rules->existsIn(['sla_rule_id'], 'SlaRules'), ['errorField' => 'sla_rule_id']);
        $rules->add($rules->existsIn(['technician_rate_id'], 'TechnicianRates'), ['errorField' => 'technician_rate_id']);
        $rules->add($rules->existsIn(['ticket_spare_id'], 'TicketSpares'), ['errorField' => 'ticket_spare_id']);
        $rules->add($rules->existsIn(['vendor_agreement_id'], 'VendorAgreements'), ['errorField' => 'vendor_agreement_id']);
        $rules->add($rules->existsIn(['computed_by_user_id'], 'ComputedByUsers'), ['errorField' => 'computed_by_user_id']);

        return $rules;
    }
}
