<?php

namespace Modules\Sales\Http\Livewire;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Application\Services\SalesAvailability;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Http\PosAccess;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Users\Contracts\UserSnapshot;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PointOfSale extends Component
{
    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public string $mode = 'counter';

    #[Locked]
    public string $counterState = 'open';

    #[Locked]
    public ?string $saleId = null;

    #[Locked]
    public ?string $tableId = null;

    #[Locked]
    public array $currentSale = [];

    #[Locked]
    public string $revision = '';

    #[Locked]
    public ?string $productId = null;

    #[Locked]
    public ?string $itemId = null;

    #[Locked]
    public string $productName = '';

    #[Locked]
    public string $openKey = '';

    #[Locked]
    public string $addKey = '';

    #[Locked]
    public string $categoryFilter = '';

    #[Locked]
    public string $categoryName = '';

    #[Locked]
    public int $productPage = 1;

    #[Locked]
    public int $categoryPage = 1;

    #[Locked]
    public int $salesPage = 1;

    #[Locked]
    public int $tablePage = 1;

    public string $search = '';

    public string $quantity = '1';

    public string $itemNote = '';

    public string $saleNote = '';

    public string $status = '';

    public function mount(): void
    {
        $this->actorContext = $this->actor()->id;
        $this->openKey = (string) Str::ulid();
    }

    private function actor(string $permission = 'sales.view'): UserSnapshot
    {
        $actor = app(PosAccess::class)->require($permission);
        // Un snapshot de otro empleado nunca continúa operando tras cambiar por PIN.
        abort_if($this->actorContext !== '' && $this->actorContext !== $actor->id, 403, 'Cambió el empleado activo. Vuelve a abrir Punto de venta.');

        return $actor;
    }

    private function sales(): SalesOperations
    {
        return app(SalesOperations::class);
    }

    public function updated(string $property): void
    {
        $this->actor();
        if ($property === 'search') {
            $this->productPage = 1;
        }
    }

    public function selectMode(string $mode): void
    {
        $this->actor();
        abort_unless(in_array($mode, ['counter', 'table'], true), 422);
        $this->mode = $mode;
        $this->clearSale();
    }

    public function selectCounterState(string $state): void
    {
        $this->actor();
        abort_unless(in_array($state, ['open', 'paused'], true), 422);
        $this->counterState = $state;
        $this->salesPage = 1;
    }

    public function clearSale(): void
    {
        $this->actor();
        $this->reset('saleId', 'tableId', 'currentSale', 'revision', 'productId', 'itemId', 'productName', 'quantity', 'itemNote', 'saleNote', 'status');
        $this->openKey = (string) Str::ulid();
        $this->resetErrorBag();
    }

    public function selectSale(string $id): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor, $id): void {
            $sale = $this->sales()->sale($actor->id, SalesText::id($id));
            $this->productId = $this->itemId = null;
            $this->capture($sale);
        });
    }

    public function selectTable(string $id): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor, $id): void {
            $table = $this->sales()->table($actor->id, SalesText::id($id));
            if ($table->activeSaleId !== null) {
                $this->selectSale($table->activeSaleId);

                return;
            }
            if (! $table->isActive) {
                throw new SalesConflict('Esta mesa está inactiva.');
            }
            $this->clearSale();
            $this->tableId = $table->id;
            $this->status = 'Mesa seleccionada: '.$table->name.'. La apertura requiere caja e idempotencia disponibles.';
        });
    }

    public function refreshSale(): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor): void {
            if ($this->saleId !== null) {
                $note = $this->saleNote;
                $this->capture($this->sales()->sale($actor->id, $this->saleId));
                $this->saleNote = $note;
            }
            $this->status = 'Vista actualizada. Tus notas pendientes se conservan.';
        });
    }

    public function startSale(): void
    {
        $actor = $this->actor('sales.create');
        $this->attempt(function () use ($actor): void {
            if ($this->saleId !== null) {
                throw new SalesConflict('Ya seleccionaste una venta.');
            }
            $payload = ['type' => $this->mode, 'table_id' => $this->mode === 'table' ? $this->tableId : null];
            $sale = app(CriticalSalesWrites::class)->run($actor->id, 'sales.open', $this->openKey, $payload,
                fn () => $this->sales()->open($actor->id, new OpenSale(SaleType::from($this->mode), $payload['table_id'])));
            $this->capture($sale);
            $this->status = 'Venta iniciada.';
        });
    }

    public function selectCategory(string $id = ''): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor, $id): void {
            $category = $id === '' ? null : app(CatalogAdministration::class)->category($actor->id, SalesText::id($id));
            if ($category && ! $category->isActive) {
                throw new SalesConflict('La categoría no está disponible.');
            }
            $this->categoryFilter = $category->id ?? '';
            $this->categoryName = $category->name ?? '';
            $this->productPage = 1;
        });
    }

    public function clearSearch(): void
    {
        $this->actor();
        $this->search = '';
        $this->selectCategory();
    }

    public function gotoPage(string $target, int $page): void
    {
        $this->actor();
        abort_unless(in_array($target, ['productPage', 'categoryPage', 'salesPage', 'tablePage'], true) && $page > 0 && $page <= 1000000, 422);
        $this->{$target} = $page;
    }

    public function chooseProduct(string $id): void
    {
        $actor = $this->actor('sales.open.update');
        $this->attempt(function () use ($actor, $id): void {
            $product = app(CatalogReader::class)->forSale($actor->id, SalesText::id($id));
            $this->productId = $product->productId;
            $this->productName = $product->name;
            $this->itemId = null;
            $this->quantity = '1';
            $this->itemNote = '';
            $this->addKey = (string) Str::ulid();
        });
    }

    public function addProduct(): void
    {
        $actor = $this->actor('sales.open.update');
        $this->attempt(function () use ($actor): void {
            $this->validate(['quantity' => ['required', 'regex:/\A[1-9][0-9]{0,9}\z/', 'integer', 'min:1', 'max:'.config('sales.max_quantity')], 'itemNote' => ['string', 'max:100']], $this->messages());
            abort_if($this->productId === null || $this->saleId === null, 422);
            $payload = ['product_id' => $this->productId, 'quantity' => (int) $this->quantity, 'note' => $this->itemNote];
            $sale = app(CriticalSalesWrites::class)->run($actor->id, 'sales.'.$this->saleId.'.add', $this->addKey, $payload,
                fn () => app(CoordinateSalesWrite::class)->run($actor->id, $this->saleId, false, '"'.$this->revision.'"',
                    fn () => $this->sales()->addProduct($actor->id, $this->saleId, new AddProduct($this->productId, (int) $this->quantity, $this->itemNote))));
            $this->capture($sale);
            $this->productId = null;
            $this->status = 'Producto agregado.';
        });
    }

    public function editItem(string $id): void
    {
        $this->actor('sales.open.update');
        foreach ($this->currentSale['items'] ?? [] as $item) {
            if ($item['id'] === $id && $item['removed_at'] === null) {
                $this->itemId = $id;
                $this->productId = null;
                $this->productName = $item['name'];
                $this->quantity = (string) $item['quantity'];
                $this->itemNote = $item['note'] ?? '';
                $this->resetErrorBag();

                return;
            }
        }
        abort(404);
    }

    public function adjustQuantity(string $id, int $delta): void
    {
        $this->actor('sales.open.update');
        abort_unless(in_array($delta, [-1, 1], true), 422);
        $this->editItem($id);
        $next = (int) $this->quantity + $delta;
        if ($next < 1) {
            $this->addError('operation', 'La retirada requiere un motivo autorizado; no se elimina una línea al llegar a cero.');

            return;
        }
        $this->quantity = (string) $next;
        $this->saveQuantity();
    }

    public function saveQuantity(): void
    {
        $this->actor('sales.open.update');
        $this->attempt(function (): void {
            $this->validate(['quantity' => ['required', 'regex:/\A[1-9][0-9]{0,9}\z/', 'integer', 'min:1', 'max:'.config('sales.max_quantity')]], $this->messages());
            abort_if($this->itemId === null, 422);
            $this->mutate(fn ($actor) => $this->sales()->changeQuantity($actor, $this->saleId, $this->itemId, (int) $this->quantity));
        });
    }

    public function saveItemNote(): void
    {
        $this->actor('sales.open.update');
        $this->attempt(function (): void {
            $this->validate(['itemNote' => ['string', 'max:100']], $this->messages());
            abort_if($this->itemId === null, 422);
            $this->mutate(fn ($actor) => $this->sales()->editItemNote($actor, $this->saleId, $this->itemId, $this->itemNote));
        });
    }

    public function saveSaleNote(): void
    {
        $this->actor('sales.open.update');
        $this->attempt(function (): void {
            $this->validate(['saleNote' => ['string', 'max:150']], $this->messages());
            $this->mutate(fn ($actor) => $this->sales()->editNote($actor, $this->saleId, $this->saleNote));
            $this->saleNote = $this->currentSale['note'] ?? '';
        });
    }

    public function pauseSale(): void
    {
        $this->actor('sales.open.update');
        $this->attempt(fn () => $this->mutate(fn ($actor) => $this->sales()->pause($actor, $this->saleId)));
    }

    public function resumeSale(): void
    {
        $this->actor('sales.open.update');
        $this->attempt(fn () => $this->mutate(fn ($actor) => $this->sales()->resume($actor, $this->saleId)));
    }

    /** @param Closure(string): SaleSnapshot $operation */
    private function mutate(Closure $operation): void
    {
        $actor = $this->actor('sales.open.update');
        abort_if($this->saleId === null, 422);
        $sale = app(CoordinateSalesWrite::class)->run($actor->id, $this->saleId, false, '"'.$this->revision.'"', fn () => $operation($actor->id));
        $this->capture($sale, false);
        $this->status = 'Venta actualizada.';
    }

    private function capture(SaleSnapshot $sale, bool $replaceNote = true): void
    {
        $this->saleId = $sale->sale->id;
        $this->tableId = $sale->sale->tableId;
        $this->revision = SalesRevision::of($sale);
        $this->currentSale = (new SaleResource($sale))->resolve(request());
        $this->currentSale['table_name'] = $sale->sale->tableId === null ? null : $this->sales()->table($this->actor()->id, $sale->sale->tableId)->name;
        if ($replaceNote) {
            $this->saleNote = $sale->sale->note ?? '';
        }
    }

    private function messages(): array
    {
        return ['quantity.*' => 'Usa una cantidad entera entre 1 y '.config('sales.max_quantity').'.', 'itemNote.max' => 'La nota de producto admite hasta 100 caracteres.', 'saleNote.max' => 'La nota de venta admite hasta 150 caracteres.'];
    }

    private function attempt(Closure $operation, bool $reset = true): void
    {
        if ($reset) {
            $this->resetErrorBag();
            $this->status = '';
        }
        try {
            $operation();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        } catch (CashSessionUnavailable $exception) {
            $this->addError('operation', 'No hay una sesión de caja válida. Actualiza la venta y el estado de caja.');
        } catch (SalesDependencyUnavailable $exception) {
            $this->addError('operation', 'Operación no disponible: falta integrar caja, idempotencia o motivos autorizados.');
        } catch (SalesConflict $exception) {
            $this->addError('operation', 'No se confirmó el cambio. '.$exception->getMessage().' Actualiza la venta para revisar su estado.');
        } catch (ModelNotFoundException $exception) {
            $this->addError('operation', 'La venta, mesa o línea ya no está disponible. Actualiza la vista.');
        } catch (InvalidArgumentException $exception) {
            $this->addError('operation', 'Revisa los identificadores y filtros de la solicitud.');
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->addError('operation', 'No fue posible conectar o completar la operación. Conservamos tus datos; vuelve a consultar antes de reintentar.');
        }
        if ($reset && $this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('pos-error');
        }
    }

    public function render(): View
    {
        $actor = $this->actor();
        $products = $categories = $tables = $sales = null;
        $cash = $critical = $editable = $stale = false;
        $cashSessionId = null;
        $this->attempt(function () use ($actor, &$products, &$categories, &$tables, &$sales, &$cash, &$critical, &$editable, &$stale, &$cashSessionId): void {
            $catalog = app(CatalogAdministration::class);
            $products = $catalog->products($actor->id, new ProductQuery(name: $this->search, categoryId: $this->categoryFilter === '' ? null : $this->categoryFilter, page: $this->productPage, perPage: 10, availableForSale: true));
            $categories = $catalog->categories($actor->id, new CategoryQuery(isActive: true, page: $this->categoryPage, perPage: 10));
            if ($this->mode === 'table') {
                $tables = $this->sales()->tables($actor->id, new TableQuery(page: $this->tablePage, perPage: 10));
            } else {
                $sales = $this->sales()->sales($actor->id, new SaleQuery(state: SaleState::from($this->counterState), type: SaleType::Counter, page: $this->salesPage, perPage: 10));
            }
            $selected = $this->saleId === null ? null : $this->sales()->sale($actor->id, $this->saleId);
            $stale = $selected !== null && ! hash_equals($this->revision, SalesRevision::of($selected));
            $cashSessionId = app(SalesAvailability::class)->activeId($actor->id, $selected?->sale->cashSessionId);
            $cash = $cashSessionId !== null;
            $critical = $cash && app(SalesAvailability::class)->critical();
            $editable = ! $stale && $cash && in_array('sales.open.update', $actor->permissions, true) && ($selected?->sale->state === SaleState::Open);
        }, false);
        $images = [];
        foreach ($products->items ?? [] as $product) {
            if ($product->image !== null && config('filesystems.disks.'.$product->image->disk.'.visibility') === 'public') {
                $url = Storage::disk($product->image->disk)->url($product->image->key);
                if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://') || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))) {
                    $images[$product->id] = $url;
                }
            }
        }

        return view('sales::livewire.point-of-sale', compact('actor', 'products', 'categories', 'tables', 'sales', 'cash', 'critical', 'editable', 'stale', 'images', 'cashSessionId'));
    }
}
