<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Catalog\Application\Services\ManageProductImages;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Http\Livewire\Categories;
use Modules\Catalog\Http\Livewire\Products;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\CatalogSqlServerTestCase;
use Tests\Support\ImageFixtures;

class CatalogAcceptanceTest extends CatalogSqlServerTestCase
{
    use ImageFixtures;

    private string $admin;

    private array $cookies = [];

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'America/Mexico_City'));
        $this->admin = app(BootstrapAdministrator::class)->create('Acceptance fixture', 'acceptance.catalog', 'AcceptanceInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'AcceptanceInitial!123', 'AcceptanceChanged!123');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/catalog-1.4d-contract-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function token(string $username = 'acceptance.catalog', string $password = 'AcceptanceChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $method, string $path, int $expected, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        $response = $this->call($method, '/api/v1'.$path, $data, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUEST_ID' => 'acceptance-catalog',
            ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token])]);
        $response->assertStatus($expected)->assertHeader('X-Request-Id', 'acceptance-catalog')->assertJsonPath('meta.request_id', 'acceptance-catalog');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => strtolower($method), 'path' => preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}(?=/|$)~i', '/{id}', parse_url($path, PHP_URL_PATH)),
            'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    private function webLogin(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $this->call('POST', '/login', ['username' => 'acceptance.catalog', 'password' => 'AcceptanceChanged!123'])->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $this->call('GET', '/account', [], $this->cookies)->assertOk();
    }

    public function test_complete_catalog_http_cycle_exports_actual_responses_for_openapi_validation(): void
    {
        $this->api('GET', '/catalog/products', 401);
        $token = $this->token();
        $category = $this->api('POST', '/catalog/categories', 201, ['name' => '  Café   frío  ', 'display_order' => 4], $token)->assertJsonPath('data.name', 'Café frío')->json('data.id');
        $this->api('POST', '/catalog/categories', 422, ['name' => 'CAFE FRIO'], $token);
        $this->api('GET', '/catalog/categories?search=CAFE&per_page=10', 200, [], $token)->assertJsonPath('meta.pagination.total', 1);
        $this->api('GET', '/catalog/categories/'.$category, 200, [], $token);
        $this->api('PUT', '/catalog/categories/'.$category, 200, ['name' => 'Bebidas', 'description' => 'Del día', 'display_order' => 1], $token);
        $this->api('PATCH', '/catalog/categories/'.$category.'/status', 200, ['is_active' => false], $token);
        $values = ['name' => 'Latte de prueba', 'category_id' => $category, 'price' => '999999999999999.9999'];
        $this->api('POST', '/catalog/products', 409, $values, $token);
        $this->api('PATCH', '/catalog/categories/'.$category.'/status', 200, ['is_active' => true], $token);
        $product = $this->api('POST', '/catalog/products', 201, $values, $token)->assertJsonPath('data.price', $values['price'])->json('data.id');
        $this->api('POST', '/catalog/products', 422, [...$values, 'price' => '-1'], $token);
        $this->api('PUT', '/catalog/products/'.$product, 200, [...$values, 'price' => '100000000000001.0001'], $token)->assertJsonPath('data.price', '100000000000001.0001');
        $this->api('PATCH', '/catalog/products/'.$product.'/status', 200, ['is_active' => false], $token);
        $this->api('PATCH', '/catalog/products/'.$product.'/status', 200, ['is_active' => true], $token);
        $first = $this->api('POST', '/catalog/products/'.$product.'/image', 200, ['image' => $this->imageFile('jpeg')], $token)->json('data.image.version');
        $second = $this->api('POST', '/catalog/products/'.$product.'/image', 200, ['image' => $this->imageFile('png')], $token)->json('data.image.version');
        $this->assertNotSame($first, $second);
        $this->api('GET', '/catalog/products?category_id='.$category.'&sort=price&direction=desc&per_page=10', 200, [], $token);
        $this->api('GET', '/catalog/products/'.$product, 200, [], $token);
        $this->api('GET', '/catalog/products?sort=price%3BDROP', 422, [], $token);
        $this->api('GET', '/catalog/products/'.Str::ulid(), 404, [], $token);
        $this->api('DELETE', '/catalog/products/'.$product, 200, [], $token);
        $this->api('GET', '/catalog/products/'.$product, 404, [], $token);
        $this->api('GET', '/catalog/products?deleted=only', 200, [], $token)->assertJsonPath('data.products.0.id', $product);
        $this->api('PATCH', '/catalog/categories/'.$category.'/status', 200, ['is_active' => false], $token);
        $this->api('POST', '/catalog/products/'.$product.'/restore', 409, [], $token);
        $this->api('PATCH', '/catalog/categories/'.$category.'/status', 200, ['is_active' => true], $token);
        $this->api('POST', '/catalog/products/'.$product.'/restore', 200, [], $token)->assertJsonPath('data.is_active', true);
        $this->api('DELETE', '/catalog/products/'.$product.'/image', 200, [], $token)->assertJsonPath('data.image', null);
        $this->api('DELETE', '/catalog/categories/'.$category, 200, [], $token);
        $this->api('GET', '/catalog/categories/'.$category.'?deleted=only', 200, [], $token);
        $this->assertSame(1, $this->database->table('catalog_products')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        foreach (['image_added', 'image_replaced', 'image_removed', 'price_changed', 'deleted', 'restored'] as $type) {
            $events = $this->database->table('audit_security_events')->where('event_type', 'catalog.product.'.$type)->get();
            $this->assertCount(1, $events);
            $this->assertSame($this->admin, $events[0]->actor_id);
            $this->assertSame($product, $events[0]->record_id);
            $this->assertSame('success', $events[0]->result);
            $this->assertSame('acceptance-catalog', $events[0]->request_id);
            $this->assertStringStartsWith('2026-11-13 18:00:00', (string) $events[0]->occurred_at);
            $this->assertStringNotContainsString('AcceptanceChanged', json_encode($events[0]));
        }
    }

    public function test_revoked_token_and_limited_supervisor_cannot_mutate_or_consult_trash(): void
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->admin, ['name' => 'Limited supervisor', 'username' => 'acceptance.supervisor', 'password' => 'SupervisorInitial!123'])->id;
        $users->assignRole($this->admin, $id, 'supervisor');
        app(IdentityAccess::class)->changePassword($id, 'SupervisorInitial!123', 'SupervisorChanged!123');
        $token = $this->token('acceptance.supervisor', 'SupervisorChanged!123');
        $this->api('GET', '/catalog/products', 200, [], $token);
        $this->api('POST', '/catalog/products', 403, [], $token);
        $this->api('GET', '/catalog/products?deleted=with', 403, [], $token);
        $session = AccessSession::query()->where('token_id', explode('|', $token)[0])->firstOrFail();
        app(SessionRegistry::class)->revokeAuthorized($this->admin, $session->id);
        $this->api('GET', '/catalog/products', 401, [], $token);
    }

    public function test_sales_can_copy_available_snapshot_and_read_optional_image_without_catalog_dependency_on_sales(): void
    {
        $catalog = app(CatalogAdministration::class);
        $reader = app(CatalogReader::class);
        $category = $catalog->createCategory($this->admin, ['name' => 'Original']);
        $product = $catalog->createProduct($this->admin, ['name' => 'Café original', 'category_id' => $category->id, 'price' => '1.0001']);
        $image = app(ManageProductImages::class)->replace($this->admin, $product->id, $this->imageFile())->image;
        $copied = $reader->forSale($this->admin, $product->id);
        $catalog->updateCategory($this->admin, $category->id, ['name' => 'Nueva categoría']);
        $catalog->updateProduct($this->admin, $product->id, ['name' => 'Nuevo nombre', 'price' => '9.1234']);
        $current = $reader->forSale($this->admin, $product->id);
        $this->assertSame([$product->id, 'Café original', 'Original', '1.0001'], [$copied->productId, $copied->name, $copied->categoryName, $copied->price]);
        $this->assertSame(['Nuevo nombre', 'Nueva categoría', '9.1234'], [$current->name, $current->categoryName, $current->price]);
        $this->assertEquals($image, $catalog->product($this->admin, $product->id)->image);
        $catalog->setCategoryActive($this->admin, $category->id, false);
        $this->expectException(CatalogConflict::class);
        $reader->forSale($this->admin, $product->id);
    }

    public function test_invalid_product_price_is_programmatically_associated_with_the_editable_input(): void
    {
        $category = app(CatalogAdministration::class)->createCategory($this->admin, ['name' => 'Bebidas']);
        $this->webLogin();
        $screen = Livewire::test(Products::class)->call('create')->set('name', 'Formulario accesible')->set('categoryId', $category->id)->set('price', '-1')->call('save')->assertHasErrors('price');
        $document = new DOMDocument;
        $document->loadHTML($screen->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $input = $xpath->query('//*[@*[name()="wire:model" and .="price"]]')->item(0);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertStringContainsString('product-price-errors', $input->getAttribute('aria-describedby'));
        $this->assertSame(1, $xpath->query('//*[@id="product-price-errors"]')->length);
        $this->assertNotSame('', trim($xpath->query('//*[@id="product-price-errors"]')->item(0)->textContent));
    }

    public function test_category_required_name_error_is_programmatically_associated(): void
    {
        $this->webLogin();
        $screen = Livewire::test(Categories::class)->call('create')->call('save')->assertHasErrors('name');
        $document = new DOMDocument;
        $document->loadHTML($screen->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $input = $xpath->query('//*[@*[name()="wire:model" and .="name"]]')->item(0);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame('category-name-errors', $input->getAttribute('aria-describedby'));
        $this->assertNotSame('', trim($xpath->query('//*[@id="category-name-errors"]')->item(0)->textContent));
    }
}
