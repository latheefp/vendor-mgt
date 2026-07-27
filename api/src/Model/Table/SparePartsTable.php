<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * SpareParts Model
 *
 * @property \App\Model\Table\VendorsTable&\Cake\ORM\Association\BelongsTo $Vendors
 * @property \App\Model\Table\ProductCategoriesTable&\Cake\ORM\Association\BelongsTo $ProductCategories
 * @property \App\Model\Table\TicketSparesTable&\Cake\ORM\Association\HasMany $TicketSpares
 *
 * @method \App\Model\Entity\SparePart newEmptyEntity()
 * @method \App\Model\Entity\SparePart newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\SparePart> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\SparePart get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\SparePart findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\SparePart patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\SparePart> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\SparePart|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\SparePart saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\SparePart>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SparePart>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SparePart>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SparePart> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SparePart>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SparePart>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SparePart>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SparePart> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SparePartsTable extends Table
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

        $this->setTable('spare_parts');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Vendors', [
            'foreignKey' => 'vendor_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ProductCategories', [
            'foreignKey' => 'product_category_id',
        ]);
        $this->hasMany('TicketSpares', [
            'foreignKey' => 'spare_part_id',
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
            ->notEmptyString('vendor_id');

        $validator
            ->allowEmptyString('product_category_id');

        $validator
            ->scalar('part_no')
            ->maxLength('part_no', 96)
            ->requirePresence('part_no', 'create')
            ->notEmptyString('part_no');

        $validator
            ->scalar('name')
            ->maxLength('name', 190)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('description')
            ->allowEmptyString('description');

        $validator
            ->notEmptyString('cost_paise');

        $validator
            ->allowEmptyString('mrp_paise');

        $validator
            ->boolean('is_serialized')
            ->notEmptyString('is_serialized');

        $validator
            ->nonNegativeInteger('reorder_level')
            ->notEmptyString('reorder_level');

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
        $rules->add($rules->isUnique(['vendor_id', 'part_no']), ['errorField' => 'vendor_id', 'message' => __('This combination of vendor_id and part_no already exists')]);
        $rules->add($rules->existsIn(['vendor_id'], 'Vendors'), ['errorField' => 'vendor_id']);
        $rules->add($rules->existsIn(['product_category_id'], 'ProductCategories'), ['errorField' => 'product_category_id']);

        return $rules;
    }
}
