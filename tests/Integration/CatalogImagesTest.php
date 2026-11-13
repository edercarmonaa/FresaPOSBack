<?php

namespace Tests\Integration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Livewire;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Catalog\Application\Ports\ImageProcessor;
use Modules\Catalog\Application\Services\ManageProductImages;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Http\Livewire\Products;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use RuntimeException;
use Tests\Fixtures\Images\FailingFilesystem;
use Tests\Support\CatalogSqlServerTestCase;
use Tests\Support\ImageFixtures;

class CatalogImagesTest extends CatalogSqlServerTestCase
{
    use ImageFixtures;

    private string $admin;

    private string $category;

    private string $product;

    private CatalogAdministration $catalog;

    private ManageProductImages $images;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->admin = app(BootstrapAdministrator::class)->create('Images test', 'images.test', 'ImagesInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'ImagesInitial!123', 'ImagesChanged!123');
        $this->catalog = app(CatalogAdministration::class);
        $this->images = app(ManageProductImages::class);
        $this->category = $this->catalog->createCategory($this->admin, ['name' => 'Bebidas'])->id;
        $this->product = $this->catalog->createProduct($this->admin, ['name' => 'Café', 'category_id' => $this->category, 'price' => '25.5000'])->id;
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->call($method, '/api/v1/'.$path, $data, [], [], ['HTTP_ACCEPT' => 'application/json', ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]), 'HTTP_X_REQUEST_ID' => 'image-request']);
    }

    private function token(string $username = 'images.test', string $password = 'ImagesChanged!123'): string
    {
        return $this->api('POST', 'auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function imagePath(?string $id = null): string
    {
        return 'catalog/products/'.($id ?? $this->product).'/image';
    }

    private function webLogin(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $this->post('/login', ['username' => 'images.test', 'password' => 'ImagesChanged!123'])->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $this->call('GET', '/account', [], $this->cookies)->assertOk();
    }

    private function faultyDisk(): FailingFilesystem
    {
        $disk = Storage::disk('public');
        $fault = new FailingFilesystem($disk->getDriver(), $disk->getAdapter(), $disk->getConfig());
        Storage::set('public', $fault);

        return $fault;
    }

    public function test_api_upload_replace_remove_versions_and_audit_without_changing_other_product_fields(): void
    {
        $token = $this->token();
        $first = $this->api('POST', $this->imagePath(), ['image' => $this->imageFile('jpeg')], $token)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.price', '25.5000')->assertJsonPath('data.category_id', $this->category)->assertJsonPath('meta.request_id', 'image-request');
        $old = $this->catalog->product($this->admin, $this->product)->image;
        Storage::disk('public')->assertExists($old->key);
        $this->assertMatchesRegularExpression('~\Acatalog/products/[0-7][0-9a-hjkmnp-tv-z]{25}\.webp\z~', $old->key);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($old->key))[2]);
        $next = $this->api('POST', $this->imagePath(), ['image' => $this->imageFile('png')], $token)->assertOk()->assertJsonMissingPath('data.image.key')->assertJsonMissingPath('data.image.disk')->assertJsonPath('data.is_active', true);
        $current = $this->catalog->product($this->admin, $this->product)->image;
        $this->assertNotSame($first->json('data.image.version'), $next->json('data.image.version'));
        $this->assertNotSame($first->json('data.image.url'), $next->json('data.image.url'));
        Storage::disk('public')->assertMissing($old->key);
        $this->api('DELETE', $this->imagePath(), [], $token)->assertOk()->assertJsonPath('data.image', null)->assertJsonPath('data.name', 'Café');
        Storage::disk('public')->assertMissing($current->key);
        foreach (['image_added', 'image_replaced', 'image_removed'] as $event) {
            $rows = $this->database->table('audit_security_events')->where('event_type', 'catalog.product.'.$event)->get();
            $this->assertCount(1, $rows);
            $this->assertSame($this->admin, $rows[0]->actor_id);
            $this->assertSame($this->product, $rows[0]->record_id);
            $this->assertSame('image-request', $rows[0]->request_id);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('catalog-image-journal'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_api_rejects_anonymous_read_only_and_missing_products_and_client_paths(): void
    {
        $this->api('POST', $this->imagePath(), ['image' => $this->imageFile()])->assertUnauthorized();
        $this->api('DELETE', $this->imagePath())->assertUnauthorized();
        $employee = app(UserAdministration::class)->create($this->admin, ['name' => 'Cashier', 'username' => 'images.cashier', 'password' => 'EmployeeInitial!123'])->id;
        app(UserAdministration::class)->assignRole($this->admin, $employee, 'cashier');
        app(IdentityAccess::class)->changePassword($employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('images.cashier', 'EmployeeChanged!123');
        $this->api('POST', $this->imagePath(), ['image' => $this->imageFile()], $token)->assertForbidden();
        $this->api('DELETE', $this->imagePath(), [], $token)->assertForbidden();
        $token = $this->token();
        $this->api('POST', $this->imagePath((string) Str::ulid()), ['image' => $this->imageFile()], $token)->assertNotFound();
        $this->api('POST', $this->imagePath(), ['image' => $this->imageFile(), 'image_key' => '../private.env'], $token)->assertUnprocessable();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_api_invalid_large_and_empty_uploads_return_safe_validation_errors(): void
    {
        $token = $this->token();
        foreach (['<?php echo "SECRET";', '<svg/>', '', str_repeat('x', 5 * 1024 * 1024 + 1)] as $content) {
            $this->api('POST', $this->imagePath(), ['image' => UploadedFile::fake()->createWithContent('fake.jpg', $content)], $token)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('image')->assertDontSee('SECRET');
        }
        $this->api('POST', $this->imagePath(), [], $token)->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_audit_failure_rolls_back_reference_and_cleans_new_file_without_losing_old_image(): void
    {
        $old = $this->images->replace($this->admin, $this->product, $this->imageFile())->image;
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'catalog.product.image_replaced') {
                throw new RuntimeException('Injected audit failure');
            }
        });
        try {
            $this->images->replace($this->admin, $this->product, $this->imageFile('webp'));
            $this->fail('La auditoría debe participar en el rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        }
        $this->assertEquals($old, $this->catalog->product($this->admin, $this->product)->image);
        $this->assertSame([$old->key], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles('catalog-image-journal'));
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'catalog.product.image_replaced')->count());
    }

    public function test_processor_failure_preserves_reference_and_creates_no_new_files(): void
    {
        $old = $this->images->replace($this->admin, $this->product, $this->imageFile())->image;
        $processor = $this->createStub(ImageProcessor::class);
        $processor->method('process')->willThrowException(new RuntimeException('Processing unavailable'));
        $this->app->instance(ImageProcessor::class, $processor);
        try {
            app(ManageProductImages::class)->replace($this->admin, $this->product, $this->imageFile());
            $this->fail('Debe propagar el fallo de procesamiento.');
        } catch (RuntimeException) {
            $this->assertEquals($old, $this->catalog->product($this->admin, $this->product)->image);
        }
        $this->assertSame([$old->key], Storage::disk('public')->allFiles());
    }

    public function test_partial_storage_failure_has_a_private_journal_and_controlled_recovery(): void
    {
        $fault = $this->faultyDisk();
        $fault->failWrites = true;
        try {
            $this->images->replace($this->admin, $this->product, $this->imageFile());
            $this->fail('Debe rechazar una escritura incompleta.');
        } catch (RuntimeException) {
            $this->assertNull($this->catalog->product($this->admin, $this->product)->image);
        }
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->assertCount(1, Storage::disk('local')->files('catalog-image-journal'));
        $this->travel(25)->hours();
        $this->assertSame(0, Artisan::call('catalog:images:reconcile'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $fault->failWrites = false;
        $this->assertSame(0, Artisan::call('catalog:images:reconcile', ['--apply' => true]));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->files('catalog-image-journal'));
    }

    public function test_cleanup_failure_does_not_reverse_commit_and_retries_safely_later(): void
    {
        $old = $this->images->replace($this->admin, $this->product, $this->imageFile())->image;
        $fault = $this->faultyDisk();
        $fault->failDeletes = true;
        $new = $this->images->replace($this->admin, $this->product, $this->imageFile('webp'))->image;
        $this->assertNotEquals($old, $new);
        $this->assertEquals($new, $this->catalog->product($this->admin, $this->product)->image);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertCount(1, Storage::disk('local')->files('catalog-image-journal'));
        $this->travel(25)->hours();
        $this->assertSame(1, Artisan::call('catalog:images:reconcile', ['--apply' => true]));
        $fault->failDeletes = false;
        $this->assertSame(0, Artisan::call('catalog:images:reconcile', ['--apply' => true]));
        $this->assertSame([$new->key], Storage::disk('public')->allFiles());
    }

    public function test_shared_reference_and_soft_deleted_products_protect_the_physical_image(): void
    {
        $image = $this->images->replace($this->admin, $this->product, $this->imageFile())->image;
        $other = $this->catalog->createProduct($this->admin, ['name' => 'Otro', 'category_id' => $this->category, 'price' => '1', 'image' => $image])->id;
        $this->catalog->deleteProduct($this->admin, $other);
        $this->images->remove($this->admin, $this->product);
        Storage::disk('public')->assertExists($image->key);
        $restored = $this->catalog->restoreProduct($this->admin, $other);
        $this->assertEquals($image, $restored->image);
        $this->images->remove($this->admin, $other);
        Storage::disk('public')->assertMissing($image->key);
    }

    public function test_product_soft_delete_keeps_image_and_forbids_image_mutation_until_restored(): void
    {
        $image = $this->images->replace($this->admin, $this->product, $this->imageFile())->image;
        $this->catalog->deleteProduct($this->admin, $this->product);
        $token = $this->token();
        $this->api('DELETE', $this->imagePath(), [], $token)->assertNotFound();
        $this->api('POST', $this->imagePath(), ['image' => $this->imageFile()], $token)->assertNotFound();
        $this->assertEquals($image, $this->catalog->restoreProduct($this->admin, $this->product)->image);
        Storage::disk('public')->assertExists($image->key);
    }

    public function test_outer_transactions_are_rejected_before_file_io(): void
    {
        $this->database->beginTransaction();
        try {
            $this->images->replace($this->admin, $this->product, $this->imageFile());
            $this->fail('La transacción raíz debe quedar en el coordinador de archivos.');
        } catch (\LogicException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            $this->database->rollBack();
        }
    }

    public function test_livewire_create_with_preview_replace_remove_and_preserved_form_fields(): void
    {
        $this->webLogin();
        $screen = Livewire::test(Products::class)->call('create')->set('name', 'Producto con foto')->set('categoryId', $this->category)->set('price', '2.1234')->set('image', $this->imageFile())->assertHasNoErrors()->assertSee('Vista previa')->call('save')->assertHasNoErrors()->assertSet('formOpen', false);
        $id = $this->database->table('catalog_products')->where('name', 'Producto con foto')->value('id');
        $old = $this->catalog->product($this->admin, $id)->image;
        $screen->call('edit', $id)->assertSee('Eliminar imagen')->set('image', $this->imageFile('jpeg'))->call('save')->assertHasNoErrors();
        $this->assertNotEquals($old, $this->catalog->product($this->admin, $id)->image);
        $screen->call('edit', $id)->set('name', 'Sin guardar todavía')->call('removeImage')->assertSet('name', 'Sin guardar todavía')->assertSet('currentImageUrl', null)->assertHasNoErrors();
        $this->assertNull($this->catalog->product($this->admin, $id)->image);
        $this->assertSame('Producto con foto', $this->catalog->product($this->admin, $id)->name);
        $this->assertSame('2.1234', $this->catalog->product($this->admin, $id)->price);
    }

    public function test_livewire_invalid_upload_keeps_form_values_and_does_not_create_product(): void
    {
        $this->webLogin();
        Livewire::test(Products::class)->call('create')->set('name', 'Formulario conservado')->set('categoryId', $this->category)->set('price', '3.25')
            ->set('image', UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "x";'))->call('save')->assertHasErrors('image')->assertSet('name', 'Formulario conservado')->assertSet('formOpen', true);
        $this->assertSame(1, $this->database->table('catalog_products')->count());
    }

    public function test_livewire_invalid_processing_rolls_back_creation_and_retains_values(): void
    {
        $this->webLogin();
        $broken = substr($this->imageBytes('jpeg'), 0, -40);
        Livewire::test(Products::class)->call('create')->set('name', 'Con imagen corrupta')->set('categoryId', $this->category)->set('price', '3.25')
            ->set('image', UploadedFile::fake()->createWithContent('corrupt.jpg', $broken))->call('save')->assertHasErrors('image')->assertSet('name', 'Con imagen corrupta')->assertSet('formOpen', true);
        $this->assertSame(1, $this->database->table('catalog_products')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_removing_an_absent_image_is_idempotent_without_duplicate_audit(): void
    {
        $before = $this->database->table('audit_security_events')->count();
        $this->images->remove($this->admin, $this->product);
        $this->images->remove($this->admin, $this->product);
        $this->assertSame($before, $this->database->table('audit_security_events')->count());
        $this->assertSame([], Storage::disk('local')->files('catalog-image-journal'));
    }

    public function test_new_product_and_image_roll_back_together_when_audit_fails(): void
    {
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'catalog.product.image_added') {
                throw new RuntimeException('Image audit failed');
            }
        });
        try {
            $this->images->saveProduct($this->admin, null, ['name' => 'No debe persistir', 'category_id' => $this->category, 'price' => '2'], $this->imageFile());
            $this->fail('Debe revertir producto e imagen.');
        } catch (RuntimeException) {
            $this->assertSame(1, $this->database->table('catalog_products')->count());
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->files('catalog-image-journal'));
    }

    public function test_native_livewire_upload_endpoint_and_preview_require_session_permission_and_no_store(): void
    {
        $url = app(GenerateSignedUploadUrl::class)->forLocal();
        Auth::forgetGuards();
        $this->call('POST', $url, ['files' => [$this->imageFile()]], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
        $this->webLogin();
        $screen = Livewire::test(Products::class)->call('edit', $this->product)->set('image', $this->imageFile());
        $preview = $screen->get('imagePreview');
        Auth::forgetGuards();
        $response = $this->call('GET', $preview, [], $this->cookies, [], ['HTTP_ACCEPT' => 'image/png'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        app(SessionRegistry::class)->revokeAuthorized($this->admin, AccessSession::query()->where('user_id', $this->admin)->firstOrFail()->id);
        Auth::forgetGuards();
        $this->call('GET', $preview, [], $this->cookies, [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
    }

    public function test_livewire_five_mib_limit_is_enforced_by_temporary_upload_validation(): void
    {
        $this->webLogin();
        $jpeg = str_pad($this->imageBytes('jpeg'), 5 * 1024 * 1024 + 1, "\0");
        Livewire::test(Products::class)->call('edit', $this->product)->set('image', UploadedFile::fake()->createWithContent('large.jpg', $jpeg))->assertHasErrors('image');
        $this->assertNull($this->catalog->product($this->admin, $this->product)->image);
    }

    public function test_create_only_user_cannot_request_an_upload_signature_or_remove_image(): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->admin, 'image_creator', 'Creador de pruebas');
        $users->setRolePermissions($this->admin, 'image_creator', ['catalog.products.view', 'catalog.products.create']);
        $employee = $users->create($this->admin, ['name' => 'Creator', 'username' => 'images.creator', 'password' => 'CreatorInitial!123'])->id;
        $users->assignRole($this->admin, $employee, 'image_creator');
        app(IdentityAccess::class)->changePassword($employee, 'CreatorInitial!123', 'CreatorChanged!123');
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $this->call('POST', '/login', ['username' => 'images.creator', 'password' => 'CreatorChanged!123']);
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $this->call('GET', '/admin/catalog/products', [], $cookies)->assertOk();
        Livewire::test(Products::class)->call('create')->assertDontSee('type="file"', false)->call('_startUpload', 'image', [], false)->assertForbidden();
        Livewire::test(Products::class)->call('removeImage')->assertForbidden();
    }
}
