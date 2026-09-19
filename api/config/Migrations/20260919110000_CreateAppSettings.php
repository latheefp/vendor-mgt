<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;
use Migrations\Db\Adapter\MysqlAdapter;

/**
 * The portal's own identity: the logo and favicon shown in the shell, and
 * the timezone/date/time conventions the desk reads every timestamp in.
 *
 * These are not `company_settings` rows. That store exists to let a
 * per-company dial diverge from a shared baseline — a district default, a
 * closure requirement. A logo or a timezone is not per-company at all: it
 * belongs to this vendor's own portal, the same for every OEM company
 * whose tickets pass through it. A single row is the whole store; there is
 * nothing to key it by.
 */
class CreateAppSettings extends AppMigration
{
    public function up(): void
    {
        $table = $this->newTable('app_settings');
        $table
            ->addColumn('logo_base64', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('favicon_base64', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('timezone', 'string', ['limit' => 64, 'null' => false, 'default' => 'Asia/Kolkata'])
            ->addColumn('date_format', 'string', ['limit' => 20, 'null' => false, 'default' => 'DD/MM/YYYY'])
            ->addColumn('time_format', 'string', ['limit' => 8, 'null' => false, 'default' => '24h']);
        $this->timestamps($table)->create();

        $now = date('Y-m-d H:i:s');
        $this->table('app_settings')->insert([
            'logo_base64' => null,
            'favicon_base64' => null,
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '24h',
            'created' => $now,
            'modified' => $now,
        ])->save();
    }

    public function down(): void
    {
        $this->table('app_settings')->drop()->save();
    }
}
