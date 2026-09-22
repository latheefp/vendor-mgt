<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * RateCardItems Model
 *
 * @property \App\Model\Table\RateCardsTable&\Cake\ORM\Association\BelongsTo $RateCards
 * @property \App\Model\Table\JobTypesTable&\Cake\ORM\Association\BelongsTo $JobTypes
 * @property \App\Model\Table\ProductCategoriesTable&\Cake\ORM\Association\BelongsTo $ProductCategories
 * @property \App\Model\Table\TicketChargesTable&\Cake\ORM\Association\HasMany $TicketCharges
 *
 * @method \App\Model\Entity\RateCardItem newEmptyEntity()
 * @method \App\Model\Entity\RateCardItem newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\RateCardItem> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\RateCardItem get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\RateCardItem findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\RateCardItem patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\RateCardItem> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\RateCardItem|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\RateCardItem saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\RateCardItem>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCardItem>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCardItem>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCardItem> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCardItem>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCardItem>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\RateCardItem>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\RateCardItem> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class RateCardItemsTable extends Table
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

        $this->setTable('rate_card_items');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('RateCards', [
            'foreignKey' => 'rate_card_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('JobTypes', [
            'foreignKey' => 'job_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ProductCategories', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('TicketCharges', [
            'foreignKey' => 'rate_card_item_id',
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
            ->notEmptyString('rate_card_id');

        $validator
            ->notEmptyString('job_type_id');

        $validator
            ->allowEmptyString('product_category_id');

        $validator
            ->scalar('warranty_scope')
            ->maxLength('warranty_scope', 24)
            ->notEmptyString('warranty_scope');

        $validator
            ->decimal('size_min_inch')
            ->allowEmptyString('size_min_inch');

        $validator
            ->decimal('size_max_inch')
            ->allowEmptyString('size_max_inch');

        $validator
            ->notEmptyString('amount_paise');

        $validator
            ->scalar('payer')
            ->maxLength('payer', 16)
            ->notEmptyString('payer')
            ->inList('payer', ['company', 'customer']);

        $validator
            ->scalar('label')
            ->maxLength('label', 190)
            ->allowEmptyString('label');

        $validator
            ->nonNegativeInteger('priority')
            ->notEmptyString('priority');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

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
        $rules->add($rules->existsIn(['rate_card_id'], 'RateCards'), ['errorField' => 'rate_card_id']);
        $rules->add($rules->existsIn(['job_type_id'], 'JobTypes'), ['errorField' => 'job_type_id']);
        $rules->add($rules->existsIn(['product_category_id'], 'ProductCategories'), ['errorField' => 'product_category_id']);

        return $rules;
    }
}
