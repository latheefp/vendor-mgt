<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Products and Appliance Categories API controller.
 */
class ProductsController extends ApiController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated([
            'index', 'view', 'add', 'edit', 'delete',
            'categories', 'addCategory', 'editCategory',
        ]);
    }

    /**
     * GET /api/product-categories
     */
    public function categories(): Response
    {
        $categoriesTable = $this->fetchTable('ProductCategories');
        $categories = $categoriesTable->find()
            ->orderBy(['sort_order' => 'ASC', 'name' => 'ASC'])
            ->all();

        return $this->respond($categories);
    }

    /**
     * POST /api/product-categories
     */
    public function addCategory(): Response
    {
        $categoriesTable = $this->fetchTable('ProductCategories');
        $data = (array)$this->request->getData();

        $category = $categoriesTable->newEntity($data);
        if ($category->hasErrors()) {
            return $this->fail('validation_error', 'Invalid category data.', 422, $category->getErrors());
        }

        if (!$categoriesTable->save($category)) {
            return $this->fail('save_failed', 'Could not create appliance category.', 400, $category->getErrors());
        }

        return $this->respond($category, [], 201);
    }

    /**
     * PUT /api/product-categories/{id}
     */
    public function editCategory(string $id): Response
    {
        $categoriesTable = $this->fetchTable('ProductCategories');
        $category = $categoriesTable->find()->where(['id' => (int)$id])->first();

        if ($category === null) {
            return $this->fail('not_found', 'Category not found.', 404);
        }

        $data = (array)$this->request->getData();
        $category = $categoriesTable->patchEntity($category, $data);

        if ($category->hasErrors()) {
            return $this->fail('validation_error', 'Invalid category data.', 422, $category->getErrors());
        }

        if (!$categoriesTable->save($category)) {
            return $this->fail('save_failed', 'Could not update category.', 400, $category->getErrors());
        }

        return $this->respond($category);
    }

    /**
     * GET /api/products
     */
    public function index(): Response
    {
        $productsTable = $this->fetchTable('Products');
        $query = $productsTable->find()
            ->contain(['Vendors', 'ProductCategories']);

        $vendorId = $this->request->getQuery('vendor_id');
        if ($vendorId) {
            $query->where(['Products.vendor_id' => (int)$vendorId]);
        }

        $categoryId = $this->request->getQuery('product_category_id');
        if ($categoryId) {
            $query->where(['Products.product_category_id' => (int)$categoryId]);
        }

        $search = $this->request->getQuery('search');
        if ($search) {
            $query->where([
                'OR' => [
                    'Products.model_no LIKE' => '%' . $search . '%',
                    'Products.name LIKE' => '%' . $search . '%',
                ],
            ]);
        }

        $products = $query->orderBy(['Products.model_no' => 'ASC'])->all();

        return $this->respond($products);
    }

    /**
     * GET /api/products/{id}
     */
    public function view(string $id): Response
    {
        $product = $this->fetchTable('Products')->find()
            ->where(['Products.id' => (int)$id])
            ->contain(['Vendors', 'ProductCategories'])
            ->first();

        if ($product === null) {
            return $this->fail('not_found', 'Product model not found.', 404);
        }

        return $this->respond($product);
    }

    /**
     * POST /api/products
     */
    public function add(): Response
    {
        $productsTable = $this->fetchTable('Products');
        $data = (array)$this->request->getData();

        $product = $productsTable->newEntity($data);
        if ($product->hasErrors()) {
            return $this->fail('validation_error', 'Invalid product model data.', 422, $product->getErrors());
        }

        if (!$productsTable->save($product)) {
            return $this->fail('save_failed', 'Could not create product model.', 400, $product->getErrors());
        }

        $product = $productsTable->get($product->id, contain: ['Vendors', 'ProductCategories']);
        return $this->respond($product, [], 201);
    }

    /**
     * PUT /api/products/{id}
     */
    public function edit(string $id): Response
    {
        $productsTable = $this->fetchTable('Products');
        $product = $productsTable->find()->where(['id' => (int)$id])->first();

        if ($product === null) {
            return $this->fail('not_found', 'Product model not found.', 404);
        }

        $data = (array)$this->request->getData();
        $product = $productsTable->patchEntity($product, $data);

        if ($product->hasErrors()) {
            return $this->fail('validation_error', 'Invalid product model data.', 422, $product->getErrors());
        }

        if (!$productsTable->save($product)) {
            return $this->fail('save_failed', 'Could not update product model.', 400, $product->getErrors());
        }

        $product = $productsTable->get($product->id, contain: ['Vendors', 'ProductCategories']);
        return $this->respond($product);
    }

    /**
     * DELETE /api/products/{id}
     */
    public function delete(string $id): Response
    {
        $productsTable = $this->fetchTable('Products');
        $product = $productsTable->find()->where(['id' => (int)$id])->first();

        if ($product === null) {
            return $this->fail('not_found', 'Product model not found.', 404);
        }

        $product->is_active = !$product->is_active;
        $productsTable->save($product);

        return $this->respond(['id' => $product->id, 'is_active' => $product->is_active]);
    }
}
