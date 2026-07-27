<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Job types, product categories and the vendor product catalogue.
 *
 * `job_types` is our own vocabulary and is deliberately small — it is the
 * key the rate resolver matches on, so it must stay stable across vendors.
 * The Dianora card is expressible with five:
 *
 *   installation       24"-43" Rs.350 / 45"-65" Rs.500
 *   demo_inspection    Rs.250, no size dimension
 *   service            in-warranty and out-of-warranty, size banded
 *   exchange_delivery  Rs.700, "TV Set Exchange or Delivery"
 *   panel_backlight    "Open Cell & Back Light Replacement & Service",
 *                      Rs.1000 in warranty / Rs.1500 out of warranty
 *
 * Size bands are NOT modelled here. They cannot be: the same agreement
 * uses 45"-65" for installation, 45"-85" for in-warranty service, and
 * 45"-55" plus 65"-85" for out-of-warranty. Bands are therefore explicit
 * inch ranges on each rate card item.
 */
class CreateCatalog extends AppMigration
{
    public function up(): void
    {
        $categories = $this->newTable('product_categories');
        $categories
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 96, 'null' => false])
            // Only some categories are sized (TVs are, fans are not); the
            // resolver skips size matching when this is false.
            ->addColumn('is_sized', 'boolean', [
                'null' => false,
                'default' => false,
                'comment' => 'true when rates depend on screen size',
            ])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($categories)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_product_categories_code'])
            ->create();

        $jobTypes = $this->newTable('job_types');
        $jobTypes
            ->addColumn('code', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            // Whether the size of the unit affects the rate for this job.
            ->addColumn('is_size_banded', 'boolean', ['null' => false, 'default' => false])
            // Whether a warranty scope must be supplied to rate this job.
            // Installation and demo are scope-free; service is not.
            ->addColumn('requires_warranty_scope', 'boolean', ['null' => false, 'default' => false])
            // Does the technician physically collect money from the customer?
            ->addColumn('is_customer_billable', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($jobTypes)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_job_types_code'])
            ->create();

        $products = $this->newTable('products');
        $products
            ->addColumn('vendor_id', 'biginteger', self::KEY)
            ->addColumn('product_category_id', 'biginteger', self::KEY)
            ->addColumn('model_no', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('size_inch', 'decimal', [
                'precision' => 6,
                'scale' => 2,
                'null' => true,
                'comment' => 'screen size where applicable',
            ])
            // "DIANORA LED TV is titled with a warranty of 3 years, 1 & 2
            // years depends upon the model" — so warranty length is a
            // per-model fact, not a vendor-wide one.
            ->addColumn('warranty_months', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('panel_warranty_months', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($products)
            ->addIndex(['vendor_id', 'model_no'], ['unique' => true, 'name' => 'uq_products_vendor_model'])
            ->addIndex(['product_category_id'], ['name' => 'idx_products_category'])
            ->addForeignKey('vendor_id', 'vendors', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_products_vendor',
            ])
            ->addForeignKey('product_category_id', 'product_categories', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_products_category',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('products')->drop()->save();
        $this->table('job_types')->drop()->save();
        $this->table('product_categories')->drop()->save();
    }
}
