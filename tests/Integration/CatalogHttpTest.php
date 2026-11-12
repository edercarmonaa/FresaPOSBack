<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Http\Livewire\Categories;
use Modules\Catalog\Http\Livewire\Products;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CatalogSqlServerTestCase;

class CatalogHttpTest extends CatalogSqlServerTestCase
{
    private string $admin;

    private CatalogAdministration $catalog;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('HTTP test admin', 'catalog.http', 'CatalogInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'CatalogInitial!123', 'CatalogChanged!123');
        $this->catalog = app(CatalogAdministration::class);
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->json($method, '/api/v1/'.$path, $data, $token === null ? [] : ['Authorization' => 'Bearer '.$token, 'X-Request-Id' => 'catalog-http']);
    }

    private function token(string $username = 'catalog.http', string $password = 'CatalogChanged!123'): string
    {
        return $this->api('POST', 'auth/login', ['username' => $username, 'password' => $password, 'device_name' => 'catalog-test'])->assertOk()->json('data.token');
    }

    private function web(string $method, string $path, array $data = [], bool $livewire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire
            ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function webLogin(string $username = 'catalog.http', string $password = 'CatalogChanged!123'): void
    {
        $this->web('POST', '/login', compact('username', 'password'))->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    private function employee(string $role = 'cashier', bool $change = true): string
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->admin, ['name' => 'HTTP employee', 'username' => 'http.'.str_replace('_', '.', $role), 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->admin, $id, $role);
        if ($change) {
            app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        }

        return $id;
    }

    private function category(string $name = 'Bebidas'): string
    {
        return $this->catalog->createCategory($this->admin, ['name' => $name])->id;
    }

    private function product(string $category, string $name = 'Café frío', string $price = '25.5000'): string
    {
        return $this->catalog->createProduct($this->admin, ['name' => $name, 'category_id' => $category, 'price' => $price])->id;
    }

    public function test_anonymous_api_and_web_cannot_access_catalog(): void
    {
        $id = (string) Str::ulid();
        foreach (['categories', 'products'] as $kind) {
            foreach ([['GET', ''], ['POST', ''], ['GET', '/'.$id], ['PUT', '/'.$id], ['PATCH', '/'.$id.'/status'], ['DELETE', '/'.$id]] as [$method, $suffix]) {
                $this->api($method, 'catalog/'.$kind.$suffix)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
            }
            $this->web('GET', '/admin/catalog/'.$kind)->assertRedirect(route('login'));
        }
        $this->api('POST', 'catalog/products/'.$id.'/restore')->assertUnauthorized();
    }

    public function test_category_api_crud_normalization_unique_validation_and_audit(): void
    {
        $token = $this->token();
        $created = $this->api('POST', 'catalog/categories', ['name' => '  Café   frío  ', 'display_order' => 3], $token)
            ->assertCreated()->assertJsonPath('data.name', 'Café frío')->assertJsonPath('data.display_order', 3)->assertJsonPath('meta.request_id', 'catalog-http');
        $id = $created->json('data.id');
        $this->assertTrue(Str::isUlid($id));
        $this->api('GET', 'catalog/categories/'.$id, [], $token)->assertOk()->assertJsonPath('data.id', $id);
        $this->api('POST', 'catalog/categories', ['name' => 'CAFE FRIO'], $token)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->api('PUT', 'catalog/categories/'.$id, ['name' => 'Postres', 'description' => ' Del día '], $token)->assertOk()->assertJsonPath('data.description', 'Del día');
        foreach ([false, true] as $state) {
            $this->api('PATCH', 'catalog/categories/'.$id.'/status', ['is_active' => $state], $token)->assertOk()->assertJsonPath('data.is_active', $state);
        }
        $this->api('DELETE', 'catalog/categories/'.$id, [], $token)->assertOk()->assertJsonPath('data', null);
        $this->api('GET', 'catalog/categories/'.$id, [], $token)->assertNotFound();
        $this->api('GET', 'catalog/categories/'.$id.'?deleted=only', [], $token)->assertOk()->assertJsonPath('data.id', $id);
        $this->api('POST', 'catalog/categories', ['name' => 'Postres'], $token)->assertUnprocessable();
        $this->assertSame(1, $this->database->table('catalog_categories')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'catalog.category.created')->count());
    }

    public function test_category_query_validation_fixed_order_search_and_pagination(): void
    {
        $token = $this->token();
        $a = $this->category('Café frío');
        $b = $this->category('Alimentos');
        $this->catalog->updateCategory($this->admin, $a, ['display_order' => 10]);
        $this->catalog->setCategoryActive($this->admin, $b, false);
        $this->api('GET', 'catalog/categories?per_page=10', [], $token)->assertOk()->assertJsonPath('data.categories.0.id', $b)->assertJsonPath('meta.pagination.total', 2);
        $this->api('GET', 'catalog/categories?search=CAFE&is_active=true', [], $token)->assertOk()->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.id', $a);
        foreach (['per_page=11', 'page=0', 'sort=name', 'direction=desc', 'is_active=active', 'unknown=x', 'search[]=x'] as $query) {
            $this->api('GET', 'catalog/categories?'.$query, [], $token)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        foreach ([10, 25, 50, 100] as $size) {
            $this->api('GET', 'catalog/categories?per_page='.$size.'&page=2', [], $token)->assertOk()->assertJsonPath('meta.pagination.per_page', $size)->assertJsonCount(0, 'data.categories');
        }
    }

    public function test_product_api_full_lifecycle_preserves_exact_prices_and_single_audit_events(): void
    {
        $token = $this->token();
        $category = $this->category();
        $data = ['name' => ' Café con leche ', 'category_id' => $category, 'price' => '999999999999999.9999'];
        $id = $this->api('POST', 'catalog/products', $data, $token)->assertCreated()->assertJsonPath('data.price', $data['price'])->assertJsonPath('data.category.name', 'Bebidas')->assertJsonPath('data.image', null)->json('data.id');
        $this->api('GET', 'catalog/products/'.$id, [], $token)->assertOk()->assertJsonPath('data.id', $id)->assertJsonMissingPath('data.image_key')->assertJsonMissingPath('data.image_disk');
        $data['price'] = '0012.5';
        $this->api('PUT', 'catalog/products/'.$id, $data, $token)->assertOk()->assertJsonPath('data.price', '12.5000');
        foreach ([false, true] as $state) {
            $this->api('PATCH', 'catalog/products/'.$id.'/status', ['is_active' => $state], $token)->assertOk()->assertJsonPath('data.is_active', $state);
        }
        $this->api('DELETE', 'catalog/products/'.$id, [], $token)->assertOk();
        $this->api('GET', 'catalog/products', [], $token)->assertOk()->assertJsonCount(0, 'data.products');
        $this->api('GET', 'catalog/products/'.$id, [], $token)->assertNotFound();
        $this->api('GET', 'catalog/products?deleted=only', [], $token)->assertOk()->assertJsonPath('data.products.0.id', $id);
        $this->api('POST', 'catalog/products/'.$id.'/restore', [], $token)->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.deleted_at', null);
        $this->assertSame(1, $this->database->table('catalog_products')->count());
        foreach (['created', 'price_changed', 'deleted', 'restored'] as $event) {
            $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'catalog.product.'.$event)->count());
        }
        $this->assertSame('12.5000', $this->database->table('catalog_products')->value('price'));
        $this->assertSame('catalog-http', $this->database->table('audit_security_events')->where('event_type', 'catalog.product.created')->value('request_id'));
    }

    public function test_product_validation_cannot_mass_assign_or_lose_decimal_precision(): void
    {
        $token = $this->token();
        $data = ['name' => 'Prueba', 'category_id' => $this->category(), 'price' => '0.0000'];
        foreach ([-1, 1.25, '1,25', '1e2', '0.00001', '1000000000000000', null] as $price) {
            $this->api('POST', 'catalog/products', [...$data, 'price' => $price], $token)->assertUnprocessable()->assertJsonValidationErrors('price');
        }
        foreach ([['image' => null], ['image_disk' => 'public'], ['id' => (string) Str::ulid()], ['deleted_at' => now()->toISOString()]] as $extra) {
            $this->api('POST', 'catalog/products', [...$data, ...$extra], $token)->assertUnprocessable();
        }
        $this->api('POST', 'catalog/products', [...$data, 'category_id' => (string) Str::ulid()], $token)->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->api('POST', 'catalog/products', [...$data, 'name' => str_repeat('🥤', 76)], $token)->assertUnprocessable();
        $this->assertSame(0, $this->database->table('catalog_products')->count());
        $this->api('POST', 'catalog/products', [...$data, 'price' => 0], $token)->assertCreated()->assertJsonPath('data.price', '0.0000');
    }

    public function test_inactive_category_and_restore_conflicts_are_atomic_and_safe(): void
    {
        $token = $this->token();
        $cat = $this->category();
        $id = $this->product($cat);
        $this->catalog->deleteProduct($this->admin, $id);
        $this->catalog->setCategoryActive($this->admin, $cat, false);
        $before = $this->database->table('audit_security_events')->count();
        $this->api('POST', 'catalog/products/'.$id.'/restore', [], $token)->assertConflict()->assertJsonPath('code', 'CONFLICT')->assertDontSee('SQLSTATE')->assertDontSee('catalog_products');
        $this->api('POST', 'catalog/products', ['name' => 'Otro', 'category_id' => $cat, 'price' => '1'], $token)->assertConflict();
        $this->assertSame($before, $this->database->table('audit_security_events')->count());
        $this->assertNotNull($this->database->table('catalog_products')->where('id', $id)->value('deleted_at'));
        $this->assertSame(1, $this->database->table('catalog_products')->count());
    }

    public function test_product_filters_order_and_database_pagination(): void
    {
        $token = $this->token();
        $cat = $this->category();
        $other = $this->category('Comida');
        for ($i = 1; $i <= 12; $i++) {
            $this->product($cat, 'Café '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), (string) $i);
        }
        $this->product($other, 'Torta', '0.1');
        $first = $this->api('GET', 'catalog/products?search=CAFE&category_id='.$cat.'&per_page=10&sort=price&direction=desc&is_active=1', [], $token)
            ->assertOk()->assertJsonCount(10, 'data.products')->assertJsonPath('data.products.0.price', '12.0000')->assertJsonPath('meta.pagination.total', 12)->assertJsonPath('meta.pagination.last_page', 2);
        $this->assertSame('catalog-http', $first->headers->get('X-Request-Id'));
        $this->api('GET', 'catalog/products?search=CAFE&per_page=10&page=2&sort=price&direction=desc', [], $token)->assertOk()->assertJsonCount(2, 'data.products')->assertJsonPath('data.products.0.price', '2.0000')->assertJsonPath('meta.pagination.from', 11);
        foreach (['sort=price%3BDROP', 'per_page=101', 'deleted=all', 'category_id=bad', 'direction=random', 'page=1000001', 'search=one+two+three+four+five+six+seven+eight+nine'] as $query) {
            $this->api('GET', 'catalog/products?'.$query, [], $token)->assertUnprocessable();
        }
    }

    public function test_roles_can_read_but_cannot_write_or_access_admin_or_trash(): void
    {
        $id = $this->product($this->category());
        foreach (['cashier', 'waiter', 'supervisor'] as $role) {
            $this->employee($role);
            $token = $this->token('http.'.$role, 'EmployeeChanged!123');
            $this->api('GET', 'catalog/products', [], $token)->assertOk();
            $this->api('GET', 'catalog/categories', [], $token)->assertOk();
            foreach ([['POST', 'products'], ['PUT', 'products/'.$id], ['PATCH', 'products/'.$id.'/status'], ['DELETE', 'products/'.$id], ['POST', 'products/'.$id.'/restore'], ['POST', 'categories']] as [$method, $path]) {
                $this->api($method, 'catalog/'.$path, [], $token)->assertForbidden();
            }
            foreach (['products?deleted=only', 'products?deleted=with', 'products/'.$id.'?deleted=only', 'categories?deleted=only'] as $path) {
                $this->api('GET', 'catalog/'.$path, [], $token)->assertForbidden();
            }
            $this->cookies = [];
            $this->webLogin('http.'.$role, 'EmployeeChanged!123');
            $this->web('GET', '/account')->assertDontSee('aria-label="Catálogo"', false);
            $this->web('GET', '/admin/catalog/products')->assertForbidden();
            $this->web('GET', '/admin/catalog/categories')->assertForbidden();
        }
    }

    public function test_existing_token_loses_catalog_permission_immediately(): void
    {
        $this->employee();
        $token = $this->token('http.cashier', 'EmployeeChanged!123');
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', []);
        $this->api('GET', 'catalog/products', [], $token)->assertForbidden();
        $this->api('GET', 'catalog/categories', [], $token)->assertForbidden();
    }

    public function test_disabled_deleted_or_password_pending_identities_cannot_enter_catalog(): void
    {
        $id = $this->employee('cashier', false);
        $token = $this->token('http.cashier', 'EmployeeInitial!123');
        $this->api('GET', 'catalog/products', [], $token)->assertForbidden();
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('http.cashier', 'EmployeeChanged!123');
        app(UserAdministration::class)->setActive($this->admin, $id, false);
        $this->api('GET', 'catalog/products', [], $token)->assertUnauthorized();
        app(UserAdministration::class)->setActive($this->admin, $id, true);
        $token = $this->token('http.cashier', 'EmployeeChanged!123');
        app(UserAdministration::class)->delete($this->admin, $id);
        $this->api('GET', 'catalog/categories', [], $token)->assertUnauthorized();
    }

    public function test_missing_invalid_and_soft_deleted_ids_have_uniform_404(): void
    {
        $token = $this->token();
        foreach (['bad', (string) Str::ulid()] as $id) {
            foreach (['categories', 'products'] as $kind) {
                $this->api('GET', 'catalog/'.$kind.'/'.$id, [], $token)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
            }
        }
        $this->api('GET', 'catalog/products/'.$this->product($this->category()).'?deleted=only', [], $token)->assertNotFound();
    }

    public function test_image_resource_only_publishes_url_and_version_without_mutation(): void
    {
        $id = $this->product($this->category());
        $key = 'catalog/products/01arz3ndektsv4rrffq69g5fav.webp';
        $this->catalog->updateProduct($this->admin, $id, ['image' => new ImageReference('public', $key)]);
        $token = $this->token();
        $response = $this->api('GET', 'catalog/products/'.$id, [], $token)->assertOk()->assertJsonPath('data.image.version', '01arz3ndektsv4rrffq69g5fav')->assertJsonMissingPath('data.image.disk')->assertJsonMissingPath('data.image.key')->assertJsonMissingPath('data.image_key');
        $this->assertStringEndsWith('/storage/'.$key, $response->json('data.image.url'));
        $this->assertSame(['url', 'version'], array_keys($response->json('data.image')));
        $this->webLogin();
        $this->web('GET', '/admin/catalog/products')->assertOk()->assertSee('/storage/'.$key)->assertDontSee('type="file"', false);
    }

    public function test_web_screens_navigation_confirmation_and_name_placeholder(): void
    {
        $this->product($this->category(), 'Producto sin imagen');
        $this->webLogin();
        $this->web('GET', '/admin/catalog/categories')->assertOk()->assertSee('aria-label="Catálogo"', false)->assertSee('wire:confirm', false)->assertSee('Crear categoría');
        $this->web('GET', '/admin/catalog/products')->assertOk()->assertSee('Producto sin imagen')->assertSee('overflow-x-auto')->assertSee('wire:confirm', false)->assertSee('Papelera')->assertDontSee('type="file"', false);
        $this->web('GET', '/account')->assertOk()->assertSee(route('catalog.products'), false)->assertSee(route('catalog.categories'), false);
    }

    public function test_livewire_category_forms_keep_values_on_error_and_apply_changes(): void
    {
        $this->webLogin();
        $screen = Livewire::test(Categories::class)->call('create')->assertSee('wire:submit="save"', false)->set('name', '  Café frío  ')->set('displayOrder', -1)->call('save')->assertHasErrors('displayOrder')->assertSet('name', '  Café frío  ')->assertSet('formOpen', true)
            ->set('displayOrder', 2)->call('save')->assertHasNoErrors()->assertSet('formOpen', false)->assertSee('Categoría guardada.');
        $id = $this->database->table('catalog_categories')->value('id');
        $screen->call('edit', $id)->set('name', 'Alimentos')->set('description', 'Del día')->call('save')->assertHasNoErrors()->assertSee('Alimentos')->call('toggle', $id)->assertSee('Inactiva')->call('toggle', $id)->call('remove', $id)->assertDontSee('Alimentos');
        $this->assertNotNull($this->database->table('catalog_categories')->value('deleted_at'));
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'catalog.category.created')->count());
    }

    public function test_livewire_product_form_price_edit_status_soft_delete_restore_and_validation(): void
    {
        $cat = $this->category();
        $this->webLogin();
        $screen = Livewire::test(Products::class)->call('create')->set('name', 'Producto UI')->set('categoryId', $cat)->set('price', '-1')->call('save')->assertHasErrors('price')->assertSet('name', 'Producto UI')->assertSet('formOpen', true)
            ->set('price', '1.0001')->call('save')->assertHasNoErrors()->assertSee('$1.0001');
        $id = $this->database->table('catalog_products')->value('id');
        $screen->call('edit', $id)->set('price', '999999999999999.9999')->set('name', 'Producto editado')->call('save')->assertHasNoErrors()->assertSee('$999,999,999,999,999.9999')
            ->call('toggle', $id)->assertSee('Inactivo')->call('toggle', $id)->call('remove', $id)->assertDontSee('Producto editado')->set('deleted', 'only')->assertSee('Producto editado')->call('restore', $id)->assertDontSee('Producto editado')->set('deleted', 'without')->assertSee('Producto editado');
        $this->assertTrue($this->catalog->product($this->admin, $id)->isActive);
        foreach (['created', 'price_changed', 'deleted', 'restored'] as $event) {
            $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'catalog.product.'.$event)->count());
        }
    }

    public function test_livewire_restore_conflict_keeps_product_in_trash(): void
    {
        $cat = $this->category();
        $id = $this->product($cat);
        $this->catalog->deleteProduct($this->admin, $id);
        $this->catalog->setCategoryActive($this->admin, $cat, false);
        $this->webLogin();
        Livewire::test(Products::class)->set('deleted', 'only')->call('restore', $id)->assertHasErrors('operation')->assertSee('Restaurar');
        $this->assertNotNull($this->database->table('catalog_products')->value('deleted_at'));
    }

    public function test_livewire_filters_preserve_search_on_page_changes_and_reset_page_on_filter(): void
    {
        $cat = $this->category();
        for ($i = 1; $i <= 11; $i++) {
            $this->product($cat, 'Café '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), (string) $i);
        }
        $this->webLogin();
        Livewire::test(Products::class)->set('search', 'CAFE')->set('perPage', 10)->set('sort', 'price')->set('direction', 'desc')->call('gotoPage', 2)->assertSet('search', 'CAFE')->assertSet('page', 2)->assertSee('Café 01')->assertDontSee('Café 11')
            ->set('categoryFilter', $cat)->assertSet('page', 1)->assertSee('Café 11');
        Livewire::test(Categories::class)->set('search', 'BEB')->set('stateFilter', 'active')->set('perPage', 10)->assertSee('Bebidas')->call('gotoPage', 2)->assertSet('search', 'BEB')->assertDontSee('Bebidas')->set('stateFilter', 'inactive')->assertSet('page', 1);
    }

    public function test_livewire_unique_category_and_inactive_assignment_have_visible_errors(): void
    {
        $cat = $this->category('Café frío');
        $this->catalog->setCategoryActive($this->admin, $cat, false);
        $this->webLogin();
        Livewire::test(Categories::class)->call('create')->set('name', ' CAFE   FRIO ')->call('save')->assertHasErrors('name')->assertSet('formOpen', true);
        Livewire::test(Products::class)->call('create')->set('name', 'Prueba')->set('categoryId', $cat)->set('price', '12.1')->call('save')->assertHasErrors('operation')->assertSet('name', 'Prueba')->assertSet('formOpen', true);
        $this->assertSame(0, $this->database->table('catalog_products')->count());
    }

    public function test_livewire_read_only_roles_cannot_invoke_hidden_admin_actions(): void
    {
        $cat = $this->category();
        $this->employee();
        $this->webLogin('http.cashier', 'EmployeeChanged!123');
        Livewire::test(Categories::class)->assertForbidden();
        Livewire::test(Products::class)->assertForbidden();
        foreach ([fn () => (new Categories)->remove($cat), fn () => (new Products)->create()] as $action) {
            try {
                $action();
                $this->fail('La acción debe verificar permisos aunque no se renderice la pantalla.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertNull($this->database->table('catalog_categories')->value('deleted_at'));
    }

    public function test_editor_cannot_tamper_status_or_trash_through_api_or_livewire(): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->admin, 'catalog_editor', 'Editor de pruebas');
        $users->setRolePermissions($this->admin, 'catalog_editor', ['catalog.products.view', 'catalog.products.update']);
        $id = $this->employee('catalog_editor');
        $cat = $this->category();
        $product = $this->product($cat);
        $token = $this->token('http.catalog.editor', 'EmployeeChanged!123');
        $values = ['name' => 'Editado', 'category_id' => $cat, 'price' => '1.1'];
        $this->api('PUT', 'catalog/products/'.$product, $values, $token)->assertOk();
        $this->api('PUT', 'catalog/products/'.$product, [...$values, 'is_active' => false], $token)->assertForbidden();
        $this->webLogin('http.catalog.editor', 'EmployeeChanged!123');
        Livewire::test(Products::class)->assertDontSee('Crear producto')->assertDontSee('Papelera')->call('edit', $product)->set('isActive', false)->call('save')->assertForbidden();
        $this->assertTrue($this->catalog->product($this->admin, $product)->isActive);
        $this->assertNotSame($this->admin, $id);
    }

    private function snapshot(TestResponse $response, string $name): array
    {
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach ((new \DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $root) {
            $snapshot = $root->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['memo']['name'] === $name) {
                preg_match('/data-update-uri="([^"]+)"/', $response->getContent(), $matches);

                return [$snapshot, html_entity_decode($matches[1])];
            }
        }
        $this->fail('La página debe montar el componente Livewire real.');
    }

    public function test_real_livewire_protocol_enforces_csrf_then_creates_category_and_rejects_revoked_session(): void
    {
        $this->webLogin();
        [$snapshot, $uri] = $this->snapshot($this->web('GET', '/admin/catalog/categories')->assertOk(), 'catalog.categories');
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['name' => 'Categoría HTTP real'], 'calls' => [['path' => '', 'method' => 'save', 'params' => []]]]]];
        $this->app->instance('env', 'csrf-check');
        try {
            $this->web('POST', $uri, $payload, true)->assertStatus(419);
            $this->web('POST', $uri, ['_token' => session()->token(), ...$payload], true)->assertOk()->assertJsonPath('components.0.effects.returns.0', null);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame('Categoría HTTP real', $this->database->table('catalog_categories')->value('name'));
        $record = AccessSession::query()->where('user_id', $this->admin)->firstOrFail();
        app(SessionRegistry::class)->revokeAuthorized($this->admin, $record->id);
        $this->web('POST', $uri, ['_token' => session()->token(), ...$payload], true)->assertUnauthorized();
        $this->assertSame(1, $this->database->table('catalog_categories')->count());
    }

    public function test_real_livewire_protocol_rechecks_removed_administrative_permission(): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->admin, 'catalog_manager', 'Gestor de pruebas');
        $users->setRolePermissions($this->admin, 'catalog_manager', ['catalog.categories.manage']);
        $this->employee('catalog_manager');
        $this->webLogin('http.catalog.manager', 'EmployeeChanged!123');
        [$snapshot, $uri] = $this->snapshot($this->web('GET', '/admin/catalog/categories')->assertOk(), 'catalog.categories');
        $users->setRolePermissions($this->admin, 'catalog_manager', []);
        $this->web('POST', $uri, ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => ['name' => 'Prohibida'], 'calls' => [['path' => '', 'method' => 'save', 'params' => []]]]]], true)->assertForbidden();
        $this->assertSame(0, $this->database->table('catalog_categories')->count());
    }

    public function test_locked_editor_target_rejects_client_side_identifier_replacement(): void
    {
        $this->webLogin();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Products::class)->set('editingId', (string) Str::ulid());
    }

    public function test_catalog_api_and_html_hide_internal_exceptions_in_both_debug_modes(): void
    {
        $token = $this->token();
        $this->webLogin();
        $catalog = $this->createStub(CatalogAdministration::class);
        $exception = new \RuntimeException('SQLSTATE select password from private_table C:\\private\\server.php INTERNAL_SECRET');
        $catalog->method('products')->willThrowException($exception);
        $catalog->method('categories')->willThrowException($exception);
        $this->app->instance(CatalogAdministration::class, $catalog);
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            $api = $this->api('GET', 'catalog/products', [], $token)->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertJsonPath('meta.request_id', 'catalog-http');
            $html = $this->web('GET', '/admin/catalog/categories')->assertStatus(500);
            $this->assertTrue($html->headers->hasCacheControlDirective('no-store'));
            foreach (['SQLSTATE', 'private_table', 'server.php', 'INTERNAL_SECRET', 'RuntimeException'] as $secret) {
                $api->assertDontSee($secret);
                $html->assertDontSee($secret);
            }
        }
    }

    public function test_category_and_status_requests_validate_the_transport_boundary(): void
    {
        $token = $this->token();
        foreach ([[], ['name' => '---'], ['name' => 'Prueba', 'display_order' => -1], ['name' => 'Prueba', 'is_active' => 'active'], ['name' => 'Prueba', 'description' => str_repeat('X', 1001)], ['name' => 'Prueba', 'image' => null]] as $data) {
            $this->api('POST', 'catalog/categories', $data, $token)->assertUnprocessable();
        }
        $category = $this->category();
        $product = $this->product($category);
        foreach (['categories/'.$category, 'products/'.$product] as $path) {
            foreach ([[], ['is_active' => 'active'], ['is_active' => false, 'price' => '0']] as $data) {
                $this->api('PATCH', 'catalog/'.$path.'/status', $data, $token)->assertUnprocessable();
            }
        }
        $this->assertTrue($this->catalog->product($this->admin, $product)->isActive);
    }
}
