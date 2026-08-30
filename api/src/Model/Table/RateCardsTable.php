<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * RateCards Model
 *
 * @property \App\Model\Table\CompaniesTable&\Cake\ORM\Association\BelongsTo $Companies
 * @property \App\Model\Table\CompanyAgreementsTable&\Cake\ORM\Association\BelongsTo $CompanyAgreements
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $PublishedByUsers
 * @property \App\Model\Table\RateCardItemsTable&\Cake\ORM\Association\HasMany $RateCardItems
 * @property \App\Model\Table\SlaRulesTable&\Cake\ORM\Association\HasMany $SlaRules
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\HasMany $Tickets
 *
 * @method \App\Model\Entity\RateCard newEmptyEntity()
 * @method \App\Model\Entity\RateCard newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\RateCard> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\RateCard get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\RateCard findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\RateCard patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\RateCard> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\RateCard|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\RateCard saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\RateCard>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCard>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCard>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCard> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCard>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCard>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCard>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCard> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class RateCardsTable extends Table
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

        $this->setTable('rate_cards');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Companies', [
            'foreignKey' => 'company_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CompanyAgreements', [
            'foreignKey' => 'company_agreement_id',
        ]);
        $this->belongsTo('PublishedByUsers', [
            'foreignKey' => 'published_by_user_id',
            'className' => 'Users',
        ]);
        $this->hasMany('RateCardItems', [
            'foreignKey' => 'rate_card_id',
        ]);
        $this->hasMany('SlaRules', [
            'foreignKey' => 'rate_card_id',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'rate_card_id',
        ]);
        $this->hasMany('Tickets', [
            'foreignKey' => 'rate_card_id',
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
            ->notEmptyString('company_id');

        $validator
            ->allowEmptyString('company_agreement_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 190)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->nonNegativeInteger('version')
            ->notEmptyString('version');

        $validator
            ->scalar('status')
            ->maxLength('status', 24)
            ->notEmptyString('status');

        $validator
            ->date('effective_from')
            ->requirePresence('effective_from', 'create')
            ->notEmptyDate('effective_from');

        $validator
            ->date('effective_to')
            ->allowEmptyDate('effective_to');

        $validator
            ->scalar('currency')
            ->maxLength('currency', 3)
            ->notEmptyString('currency');

        $validator
            ->dateTime('published_at')
            ->allowEmptyDateTime('published_at');

        $validator
            ->allowEmptyString('published_by_user_id');

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
        $rules->add($rules->isUnique(['company_id', 'version']), ['errorField' => 'company_id', 'message' => __('This combination of company_id and version already exists')]);
        $rules->add($rules->existsIn(['company_id'], 'Companies'), ['errorField' => 'company_id']);
        $rules->add($rules->existsIn(['company_agreement_id'], 'CompanyAgreements'), ['errorField' => 'company_agreement_id']);
        $rules->add($rules->existsIn(['published_by_user_id'], 'PublishedByUsers'), ['errorField' => 'published_by_user_id']);

        return $rules;
    }
}
