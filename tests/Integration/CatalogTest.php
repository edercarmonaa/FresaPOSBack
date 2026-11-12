<?php

namespace Tests\Integration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Catalog\Application\Ports\CategoryRepository;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\CategorySnapshot;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Infrastructure\Models\Category;
use Modules\Catalog\Infrastructure\Models\Product;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use RuntimeException;
use Tests\Support\CatalogSqlServerTestCase;

class CatalogTest extends CatalogSqlServerTestCase
{
    private CatalogAdministration $catalog;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('Catalog test admin', 'catalog.admin', 'CatalogInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'CatalogInitial!123', 'CatalogChanged!123');
        $this->catalog = app(CatalogAdministration::class);
    }

    private function categoryRecord(string $name = 'Bebidas'): CategorySnapshot
    {
        return $this->catalog->createCategory($this->admin, ['name' => $name]);
    }

    private function productRecord(?string $categoryId = null, string $name = 'Café con leche', string $price = '25.5000'): ProductSnapshot
    {
        return $this->catalog->createProduct($this->admin, ['category_id' => $categoryId ?? $this->categoryRecord()->id, 'name' => $name, 'price' => $price]);
    }

    private function rejects(string $exception, Closure $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);

            return;
        }
        $this->fail('Se esperaba rechazo de la operación.');
    }

    private function employee(bool $changePassword = true): string
    {
        $user = app(UserAdministration::class)->create($this->admin, ['name' => 'Catalog employee', 'username' => 'catalog.employee', 'password' => 'EmployeeInitial!123']);
        app(UserAdministration::class)->assignRole($this->admin, $user->id, 'cashier');
        if ($changePassword) {
            app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        }

        return $user->id;
    }

    public function test_category_creation_update_normalization_and_ordering(): void
    {
        $first = $this->catalog->createCategory($this->admin, ['name' => "  Cafe\u{0301}   frío  ", 'description' => '  Bebidas   frías  ', 'display_order' => 2]);
        $this->assertTrue(Str::isUlid($first->id));
        $this->assertSame('Café frío', $first->name);
        $this->assertSame('Bebidas frías', $first->description);
        $this->assertTrue($first->isActive);
        $updated = $this->catalog->updateCategory($this->admin, $first->id, ['name' => '  Fríos ', 'description' => null, 'display_order' => 0]);
        $this->assertSame('Fríos', $updated->name);
        $this->assertNull($updated->description);
        $second = $this->categoryRecord('Alimentos');
        $this->catalog->setCategoryActive($this->admin, $second->id, false);
        $this->assertSame([$first->id], array_column($this->catalog->categories($this->admin, new CategoryQuery(name: 'FRI', isActive: true))->items, 'id'));
        $this->assertSame(2, $this->catalog->categories($this->admin)->total);
        $this->catalog->updateCategory($this->admin, $second->id, ['display_order' => 10]);
        $this->assertSame([$first->id], array_column($this->catalog->categories($this->admin, new CategoryQuery(perPage: 1))->items, 'id'));
        $this->assertSame([$second->id], array_column($this->catalog->categories($this->admin, new CategoryQuery(page: 2, perPage: 1))->items, 'id'));
    }

    public function test_invalid_category_fields_roll_back_without_audit_or_partial_rows(): void
    {
        $count = $this->database->table('audit_security_events')->count();
        foreach ([['name' => ''], ['name' => 'Nombre', 'display_order' => -1], ['name' => 'Nombre', 'display_order' => 1.5],
            ['name' => 'Nombre', 'is_active' => 'active'], ['name' => 'Nombre', 'description' => str_repeat('X', 1001)],
            ['name' => 'Nombre', 'id' => 'untrusted']] as $invalid) {
            $this->rejects(ValidationException::class, fn () => $this->catalog->createCategory($this->admin, $invalid));
        }
        $this->assertSame(0, Category::withTrashed()->count());
        $this->assertSame($count, $this->database->table('audit_security_events')->count());
    }

    public function test_category_uniqueness_is_case_accent_space_insensitive_and_reserves_deleted_names(): void
    {
        $category = $this->categoryRecord('Café frío');
        foreach ([' CAFÉ   FRÍO ', 'cafe frio', "Cafe\u{0301} fri\u{0301}o"] as $equivalent) {
            $this->rejects(CatalogConflict::class, fn () => $this->categoryRecord($equivalent));
        }
        $this->catalog->deleteCategory($this->admin, $category->id);
        $this->rejects(CatalogConflict::class, fn () => $this->categoryRecord('CAFE FRIO'));
        $this->assertSame(1, Category::withTrashed()->count());
    }

    public function test_category_deactivation_preserves_products_but_prevents_assignment_and_sale(): void
    {
        $product = $this->productRecord();
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, false);
        $current = $this->catalog->product($this->admin, $product->id);
        $this->assertTrue($current->isActive);
        $this->assertSame($product->price, $current->price);
        $this->assertSame($product->updatedAt, $current->updatedAt);
        $this->rejects(CatalogConflict::class, fn () => $this->productRecord($product->categoryId, 'Otro'));
        $this->rejects(CatalogConflict::class, fn () => app(CatalogReader::class)->forSale($this->admin, $product->id));
        $this->assertSame('Nombre nuevo', $this->catalog->updateProduct($this->admin, $product->id, ['name' => 'Nombre nuevo'])->name);
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, true);
        $this->assertSame($product->id, app(CatalogReader::class)->forSale($this->admin, $product->id)->productId);
    }

    public function test_product_creation_ulids_relations_and_decimal_precision(): void
    {
        $product = $this->productRecord(price: '999999999999999.9999');
        $this->assertTrue(Str::isUlid($product->id));
        $this->assertSame('999999999999999.9999', $product->price);
        $this->assertNull($product->image);
        $model = Product::query()->findOrFail($product->id);
        $this->assertSame($product->categoryId, $model->category->id);
        $this->assertSame($product->id, $model->category->products->sole()->id);
        $this->assertSame('999999999999999.9999', $model->price);
        $this->assertStringContainsString('"price":"999999999999999.9999"', json_encode($product, JSON_THROW_ON_ERROR));
        $this->assertStringEndsWith('Z', $product->createdAt);
    }

    public function test_price_changes_preserve_fractional_precision_and_audit_before_after(): void
    {
        $product = $this->productRecord(price: '0');
        $updated = $this->catalog->changePrice($this->admin, $product->id, '100000000000001.0001');
        $this->assertSame('100000000000001.0001', $updated->price);
        $event = $this->database->table('audit_security_events')->where('event_type', 'catalog.product.price_changed')->sole();
        $changes = json_decode($event->change_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.0000', $changes['price_before']);
        $this->assertSame($updated->price, $changes['price_after']);
        $this->assertSame(['price'], $changes['changed_fields']);
        $this->assertSame($this->admin, $event->actor_id);
        $this->assertSame($product->id, $event->record_id);
    }

    public function test_invalid_prices_cannot_create_or_update_products_even_when_called_directly(): void
    {
        $product = $this->productRecord();
        foreach ([1.25, '-1', '1.12345', '1e3', '1000000000000000', true] as $invalid) {
            $this->rejects(ValidationException::class, fn () => $this->catalog->changePrice($this->admin, $product->id, $invalid));
            $this->rejects(ValidationException::class, fn () => $this->catalog->createProduct($this->admin, ['name' => 'Inválido', 'category_id' => $product->categoryId, 'price' => $invalid]));
        }
        $this->assertSame(1, Product::query()->count());
        $this->assertSame($product->price, $this->catalog->product($this->admin, $product->id)->price);
    }

    public function test_missing_inactive_deleted_and_invalid_categories_are_rejected(): void
    {
        $this->rejects(ModelNotFoundException::class, fn () => $this->productRecord(strtolower((string) Str::ulid())));
        $this->rejects(ValidationException::class, fn () => $this->productRecord('invalid'));
        $category = $this->categoryRecord();
        $this->catalog->setCategoryActive($this->admin, $category->id, false);
        $this->rejects(CatalogConflict::class, fn () => $this->productRecord($category->id));
        $this->catalog->setCategoryActive($this->admin, $category->id, true);
        $this->catalog->deleteCategory($this->admin, $category->id);
        $this->rejects(CatalogConflict::class, fn () => $this->productRecord($category->id));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_product_updates_allow_only_whitelisted_data_and_valid_new_categories(): void
    {
        $product = $this->productRecord();
        $category = $this->categoryRecord('Especialidades');
        $updated = $this->catalog->updateProduct($this->admin, $product->id, ['name' => '  Latte   especial ', 'category_id' => $category->id, 'description' => 'Espresso', 'price' => '42.1']);
        $this->assertSame('Latte especial', $updated->name);
        $this->assertSame($category->id, $updated->categoryId);
        $this->assertSame('42.1000', $updated->price);
        foreach ([['id' => $product->id], ['deleted_at' => now()], ['is_active' => 'yes'], ['name' => ''], ['image_key' => '../bad.webp']] as $invalid) {
            $this->rejects(ValidationException::class, fn () => $this->catalog->updateProduct($this->admin, $product->id, $invalid));
        }
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, false);
        $this->rejects(CatalogConflict::class, fn () => $this->catalog->updateProduct($this->admin, $product->id, ['category_id' => $product->categoryId]));
    }

    public function test_sale_snapshots_are_immutable_and_do_not_follow_catalog_changes(): void
    {
        $product = $this->productRecord();
        $old = app(CatalogReader::class)->forSale($this->admin, $product->id);
        $this->catalog->updateCategory($this->admin, $product->categoryId, ['name' => 'Cafetería']);
        $other = $this->categoryRecord('Promociones');
        $this->catalog->updateProduct($this->admin, $product->id, ['name' => 'Nuevo nombre', 'category_id' => $other->id, 'price' => '30']);
        $new = app(CatalogReader::class)->forSale($this->admin, $product->id);
        $this->assertSame('Bebidas', $old->categoryName);
        $this->assertSame('Café con leche', $old->name);
        $this->assertSame('25.5000', $old->price);
        $this->assertSame('Promociones', $new->categoryName);
        $this->assertSame('Nuevo nombre', $new->name);
        $this->assertSame('30.0000', $new->price);
        $this->assertTrue((new \ReflectionClass($old))->isReadOnly());
        $this->assertFalse($this->database->getSchemaBuilder()->hasTable('sale_lines'));
    }

    public function test_soft_deletes_preserve_product_and_category_references(): void
    {
        $product = $this->productRecord();
        $this->catalog->deleteProduct($this->admin, $product->id);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(1, Product::withTrashed()->count());
        $this->assertNotNull($this->catalog->product($this->admin, $product->id, true)->deletedAt);
        $this->rejects(CatalogConflict::class, fn () => app(CatalogReader::class)->forSale($this->admin, $product->id));
        $this->catalog->deleteCategory($this->admin, $product->categoryId);
        $this->assertTrue(Product::withTrashed()->findOrFail($product->id)->category->trashed());
        $this->assertSame(1, $this->catalog->categories($this->admin, new CategoryQuery(deleted: 'only'))->total);
    }

    public function test_category_soft_delete_retains_active_products_but_hides_them_from_new_sales(): void
    {
        $product = $this->productRecord();
        $this->catalog->deleteCategory($this->admin, $product->categoryId);
        $current = $this->catalog->product($this->admin, $product->id);
        $this->assertTrue($current->isActive);
        $this->assertNull($current->deletedAt);
        $this->assertSame($product->updatedAt, $current->updatedAt);
        $this->assertTrue(Product::query()->findOrFail($product->id)->category->trashed());
        $this->rejects(CatalogConflict::class, fn () => app(CatalogReader::class)->forSale($this->admin, $product->id));
    }

    public function test_restored_product_is_active_even_when_it_was_inactive_before_deletion(): void
    {
        $product = $this->productRecord();
        $this->catalog->setProductActive($this->admin, $product->id, false);
        $this->catalog->deleteProduct($this->admin, $product->id);
        $restored = $this->catalog->restoreProduct($this->admin, $product->id);
        $this->assertTrue($restored->isActive);
        $this->assertNull($restored->deletedAt);
        $this->assertSame($restored->id, app(CatalogReader::class)->forSale($this->admin, $product->id)->productId);
        $this->rejects(CatalogConflict::class, fn () => $this->catalog->restoreProduct($this->admin, $product->id));
    }

    public function test_restoration_rejects_inactive_or_deleted_categories_without_partial_changes(): void
    {
        $product = $this->productRecord();
        $this->catalog->deleteProduct($this->admin, $product->id);
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, false);
        $this->rejects(CatalogConflict::class, fn () => $this->catalog->restoreProduct($this->admin, $product->id));
        $this->assertTrue(Product::withTrashed()->findOrFail($product->id)->trashed());
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, true);
        $this->catalog->deleteCategory($this->admin, $product->categoryId);
        $this->rejects(CatalogConflict::class, fn () => $this->catalog->restoreProduct($this->admin, $product->id));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_product_activation_requires_a_sellable_category(): void
    {
        $product = $this->productRecord();
        $this->catalog->setProductActive($this->admin, $product->id, false);
        $this->rejects(CatalogConflict::class, fn () => app(CatalogReader::class)->forSale($this->admin, $product->id));
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, false);
        $this->rejects(CatalogConflict::class, fn () => $this->catalog->setProductActive($this->admin, $product->id, true));
        $this->catalog->setCategoryActive($this->admin, $product->categoryId, true);
        $this->assertTrue($this->catalog->setProductActive($this->admin, $product->id, true)->isActive);
    }

    public function test_word_prefix_search_is_case_accent_insensitive_and_escapes_like_literals(): void
    {
        $category = $this->categoryRecord();
        $coffee = $this->productRecord($category->id);
        $tea = $this->productRecord($category->id, 'Té Limón');
        $literal = $this->productRecord($category->id, 'Combo 10% A_B [N]');
        foreach (['cafe LE', 'LEC', 'CAFÉ'] as $query) {
            $this->assertSame([$coffee->id], array_column($this->catalog->products($this->admin, new ProductQuery(name: $query))->items, 'id'));
        }
        $this->assertSame([$tea->id], array_column($this->catalog->products($this->admin, new ProductQuery(name: 'LIMON'))->items, 'id'));
        foreach (['10%', 'A_', '[N]'] as $query) {
            $this->assertSame([$literal->id], array_column($this->catalog->products($this->admin, new ProductQuery(name: $query))->items, 'id'));
        }
        foreach (['afé', "x' OR 1=1 --"] as $query) {
            $this->assertSame(0, $this->catalog->products($this->admin, new ProductQuery(name: $query))->total);
        }
        $this->assertSame(3, Product::query()->count());
    }

    public function test_filters_pagination_price_order_and_deleted_views_are_stable(): void
    {
        $category = $this->categoryRecord();
        $other = $this->categoryRecord('Otra');
        $first = $this->productRecord($category->id, 'Producto A', '10');
        $second = $this->productRecord($category->id, 'Producto B', '2');
        $third = $this->productRecord($other->id, 'Producto C', '2.0001');
        $this->catalog->setProductActive($this->admin, $second->id, false);
        $page = $this->catalog->products($this->admin, new ProductQuery(sort: 'price', perPage: 2));
        $this->assertSame(3, $page->total);
        $this->assertSame([$second->id, $third->id], array_column($page->items, 'id'));
        $this->assertSame([$first->id], array_column($this->catalog->products($this->admin, new ProductQuery(sort: 'price', perPage: 2, page: 2))->items, 'id'));
        $this->assertSame(1, $this->catalog->products($this->admin, new ProductQuery(categoryId: $category->id, isActive: true))->total);
        $this->catalog->deleteProduct($this->admin, $third->id);
        $this->assertSame(2, $this->catalog->products($this->admin)->total);
        $this->assertSame(3, $this->catalog->products($this->admin, new ProductQuery(deleted: 'with'))->total);
        $this->assertSame([$third->id], array_column($this->catalog->products($this->admin, new ProductQuery(deleted: 'only'))->items, 'id'));
    }

    public function test_existing_roles_can_view_without_inheriting_catalog_write_permissions(): void
    {
        $product = $this->productRecord();
        $employee = $this->employee();
        $this->assertSame($product->id, app(CatalogReader::class)->forSale($employee, $product->id)->productId);
        $this->assertSame(1, $this->catalog->categories($employee)->total);
        foreach ([fn () => $this->catalog->createCategory($employee, ['name' => 'No']),
            fn () => $this->catalog->createProduct($employee, ['category_id' => $product->categoryId, 'name' => 'No', 'price' => '1']),
            fn () => $this->catalog->updateProduct($employee, $product->id, ['name' => 'No']),
            fn () => $this->catalog->changePrice($employee, $product->id, '1'),
            fn () => $this->catalog->setProductActive($employee, $product->id, false),
            fn () => $this->catalog->deleteProduct($employee, $product->id),
            fn () => $this->catalog->restoreProduct($employee, $product->id)] as $operation) {
            $this->rejects(AuthorizationException::class, $operation);
        }
    }

    public function test_inactive_deleted_and_password_change_pending_actors_cannot_use_catalog_services(): void
    {
        $product = $this->productRecord();
        $employee = $this->employee(false);
        $this->rejects(AuthorizationException::class, fn () => $this->catalog->products($employee));
        app(IdentityAccess::class)->changePassword($employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
        app(UserAdministration::class)->setActive($this->admin, $employee, false);
        $this->rejects(AuthorizationException::class, fn () => app(CatalogReader::class)->forSale($employee, $product->id));
        app(UserAdministration::class)->setActive($this->admin, $employee, true);
        app(UserAdministration::class)->delete($this->admin, $employee);
        $this->rejects(AuthorizationException::class, fn () => $this->catalog->products($employee));
    }

    public function test_update_permission_cannot_bypass_the_separate_status_permission(): void
    {
        $product = $this->productRecord();
        $employee = $this->employee();
        app(UserAdministration::class)->createRole($this->admin, 'catalog-editor', 'Editor de prueba');
        app(UserAdministration::class)->setRolePermissions($this->admin, 'catalog-editor', ['catalog.products.view', 'catalog.products.update']);
        app(UserAdministration::class)->assignRole($this->admin, $employee, 'catalog-editor');
        $this->assertSame('20.0000', $this->catalog->changePrice($employee, $product->id, '20')->price);
        $this->rejects(AuthorizationException::class, fn () => $this->catalog->updateProduct($employee, $product->id, ['name' => 'No parcial', 'is_active' => false]));
        $this->assertSame($product->name, $this->catalog->product($this->admin, $product->id)->name);
        $this->assertTrue($this->catalog->product($this->admin, $product->id)->isActive);
    }

    public function test_admin_role_name_does_not_bypass_a_revoked_catalog_permission(): void
    {
        $category = $this->categoryRecord();
        $permissions = app(IdentityAccess::class)->inspect($this->admin)->permissions;
        app(UserAdministration::class)->setRolePermissions($this->admin, 'admin', array_values(array_diff($permissions, ['catalog.products.create'])));
        $this->rejects(AuthorizationException::class, fn () => $this->productRecord($category->id));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_audit_records_changes_without_free_text_or_secrets(): void
    {
        $category = $this->categoryRecord();
        $product = $this->catalog->createProduct($this->admin, ['name' => 'No duplicar este nombre', 'description' => 'No duplicar esta descripción', 'category_id' => $category->id, 'price' => '2']);
        $this->catalog->updateProduct($this->admin, $product->id, ['name' => 'Otro nombre', 'price' => '3']);
        $this->catalog->setProductActive($this->admin, $product->id, false);
        $this->catalog->setProductActive($this->admin, $product->id, true);
        $this->catalog->deleteProduct($this->admin, $product->id);
        $this->catalog->restoreProduct($this->admin, $product->id);
        $events = $this->database->table('audit_security_events')->where('event_type', 'like', 'catalog.product.%')->get();
        foreach (['created', 'updated', 'price_changed', 'deactivated', 'activated', 'deleted', 'restored'] as $operation) {
            $this->assertTrue($events->contains('event_type', 'catalog.product.'.$operation));
        }
        foreach ($events as $event) {
            $this->assertSame($this->admin, $event->actor_id);
            $this->assertSame($product->id, $event->record_id);
            $this->assertSame('success', $event->result);
        }
        $encoded = json_encode($events, JSON_THROW_ON_ERROR);
        foreach (['No duplicar', 'CatalogChanged!123', 'password', 'pin_hash', 'SQLSTATE', 'DB_PASSWORD'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_audit_failure_rolls_back_catalog_price_and_audit_rows(): void
    {
        $product = $this->productRecord();
        $auditCount = $this->database->table('audit_security_events')->count();
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'catalog.product.price_changed') {
                throw new RuntimeException('Synthetic catalog audit failure.');
            }
        });
        $this->rejects(RuntimeException::class, fn () => $this->catalog->changePrice($this->admin, $product->id, '99'));
        $this->assertSame($product->price, $this->catalog->product($this->admin, $product->id)->price);
        $this->assertSame($auditCount, $this->database->table('audit_security_events')->count());
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_external_transaction_rollback_discards_catalog_and_audit_changes(): void
    {
        $auditCount = $this->database->table('audit_security_events')->count();
        $this->rejects(RuntimeException::class, fn () => app(TransactionManager::class)->run(function (): void {
            $this->productRecord();
            throw new RuntimeException('Synthetic outer rollback.');
        }));
        $this->assertSame(0, Category::withTrashed()->count());
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame($auditCount, $this->database->table('audit_security_events')->count());
    }

    public function test_repositories_never_start_independent_transactions(): void
    {
        $this->rejects(LogicException::class, fn () => app(CategoryRepository::class)->create(['name' => 'Fuera de transacción']));
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_image_references_replace_clear_and_validate_disks_without_file_io(): void
    {
        $product = $this->productRecord();
        $image = new ImageReference('public', 'catalog/products/'.strtolower((string) Str::ulid()).'.webp');
        $updated = $this->catalog->updateProduct($this->admin, $product->id, ['image' => $image]);
        $this->assertSame($image->key, $updated->image->key);
        $replacement = new ImageReference('public', 'catalog/products/'.strtolower((string) Str::ulid()).'.webp');
        $this->assertNotSame($updated->image->key, $this->catalog->updateProduct($this->admin, $product->id, ['image' => $replacement])->image->key);
        $this->rejects(ValidationException::class, fn () => $this->catalog->updateProduct($this->admin, $product->id, ['image' => new ImageReference('local', $image->key)]));
        $this->assertNull($this->catalog->updateProduct($this->admin, $product->id, ['image' => null])->image);
    }

    public function test_sql_server_schema_constraints_foreign_keys_and_indexes(): void
    {
        $product = $this->productRecord();
        $columns = $this->database->select("SELECT c.name, t.name AS type_name, c.max_length, c.precision, c.scale FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID('catalog_products')");
        $id = collect($columns)->firstWhere('name', 'id');
        $price = collect($columns)->firstWhere('name', 'price');
        $this->assertSame('nchar', $id->type_name);
        $this->assertSame(52, (int) $id->max_length);
        $this->assertSame('decimal', $price->type_name);
        $this->assertSame(19, (int) $price->precision);
        $this->assertSame(4, (int) $price->scale);
        $foreign = $this->database->getSchemaBuilder()->getForeignKeys('catalog_products');
        $this->assertSame('no action', $foreign[0]['on_delete']);
        $this->assertSame('catalog_categories', $foreign[0]['foreign_table']);
        $indexes = $this->database->getSchemaBuilder()->getIndexes('catalog_products');
        $this->assertContains(['category_id', 'deleted_at', 'is_active'], array_column($indexes, 'columns'));
        foreach ([['category_id' => strtolower((string) Str::ulid())], ['price' => '-1'], ['image_disk' => 'public', 'image_key' => '../bad.webp']] as $invalid) {
            try {
                $this->database->table('catalog_products')->insert(array_replace(['id' => strtolower((string) Str::ulid()), 'category_id' => $product->categoryId, 'name' => 'SQL test', 'price' => '1'], $invalid));
                $this->fail('La base debe rechazar datos inválidos.');
            } catch (QueryException $exception) {
                $this->assertSame(547, (int) ($exception->errorInfo[1] ?? 0));
            }
        }
        $this->rejects(QueryException::class, fn () => $this->database->table('catalog_categories')->where('id', $product->categoryId)->delete());
        $this->rejects(LogicException::class, fn () => Product::query()->findOrFail($product->id)->forceDelete());
        $this->rejects(LogicException::class, fn () => Category::query()->findOrFail($product->categoryId)->forceDelete());
        $this->assertSame(1, Product::query()->count());
    }

    public function test_audit_extension_is_additive_and_preserves_legacy_events(): void
    {
        $migration = require base_path('Modules/Audit/database/migrations/2026_11_12_000004_add_catalog_change_data_to_security_events.php');
        $migration->down();
        event(new SecurityEvent('login', $this->admin, $this->admin));
        $before = $this->database->table('audit_security_events')->orderBy('id')->get()->toArray();
        $migration->up();
        $after = $this->database->table('audit_security_events')->orderBy('id')->get()->map(function ($row) {
            $this->assertNull($row->change_data);
            unset($row->change_data);

            return $row;
        })->all();
        $this->assertEquals($before, $after);
        $this->rejects(QueryException::class, fn () => $this->database->table('audit_security_events')->where('id', $before[0]->id)->update(['change_data' => 'not-json']));
    }
}
