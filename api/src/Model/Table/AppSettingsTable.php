<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\AppSetting;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use DateTimeZone;

/**
 * AppSettings Model
 *
 * A single row: the portal's own identity and display conventions,
 * shared by every company whose tickets pass through it. There is
 * nothing to key it by, so callers read/write id 1 rather than listing.
 *
 * @method \App\Model\Entity\AppSetting newEmptyEntity()
 * @method \App\Model\Entity\AppSetting newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\AppSetting get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\AppSetting patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\AppSetting|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\AppSetting saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AppSettingsTable extends Table
{
    /** Date formats the desk knows how to render every timestamp in. */
    public const DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];

    /** Time formats the desk knows how to render every timestamp in. */
    public const TIME_FORMATS = ['12h', '24h'];

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('app_settings');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
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
            ->scalar('timezone')
            ->maxLength('timezone', 64)
            ->notEmptyString('timezone')
            ->add('timezone', 'valid', [
                'rule' => fn(string $value): bool => in_array($value, DateTimeZone::listIdentifiers(), true),
                'message' => 'Not a recognised timezone.',
            ]);

        $validator
            ->scalar('date_format')
            ->inList('date_format', self::DATE_FORMATS)
            ->notEmptyString('date_format');

        $validator
            ->scalar('time_format')
            ->inList('time_format', self::TIME_FORMATS)
            ->notEmptyString('time_format');

        $validator
            ->scalar('logo_base64')
            ->allowEmptyString('logo_base64');

        $validator
            ->scalar('favicon_base64')
            ->allowEmptyString('favicon_base64');

        return $validator;
    }

    /**
     * The single settings row, creating it if the seed migration never ran.
     */
    public function getSingleton(): AppSetting
    {
        $settings = $this->find()->orderBy(['id' => 'ASC'])->first();
        if ($settings !== null) {
            return $settings;
        }

        $settings = $this->newEntity([
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',
        ]);
        $this->saveOrFail($settings);

        return $settings;
    }
}
