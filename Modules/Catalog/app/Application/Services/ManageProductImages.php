<?php

namespace Modules\Catalog\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Catalog\Application\ImageChange;
use Modules\Catalog\Application\Ports\ImageProcessor;
use Modules\Catalog\Application\Ports\ProductImageFiles;
use Modules\Catalog\Application\Ports\ProductRepository;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use SplFileInfo;
use Throwable;

final class ManageProductImages
{
    public function __construct(private readonly CatalogAdministration $catalog, private readonly ProductRepository $products,
        private readonly ImageProcessor $processor, private readonly ProductImageFiles $files,
        private readonly TransactionManager $transactions, private readonly IdentityAccess $identities,
        private readonly CatalogPolicy $policy, private readonly DatabaseManager $database) {}

    private function authorize(string $actor): void
    {
        $identity = $this->identities->inspect($actor);
        if ($identity === null || ! $this->policy->allows($identity, 'catalog.products.update') || ! $this->policy->allows($identity, 'catalog.products.view')) {
            throw new AuthorizationException;
        }
    }

    public function replace(string $actor, string $id, SplFileInfo $file): ProductSnapshot
    {
        return $this->change($actor, $id, [], $file, false);
    }

    public function remove(string $actor, string $id): ProductSnapshot
    {
        return $this->change($actor, $id, [], null, true);
    }

    /** Guardado atómico del formulario con imagen opcional; las reglas del producto siguen en CatalogAdministration. */
    public function saveProduct(string $actor, ?string $id, array $values, SplFileInfo $file): ProductSnapshot
    {
        return $this->change($actor, $id, $values, $file, false);
    }

    private function change(string $actor, ?string $id, array $values, ?SplFileInfo $file, bool $remove): ProductSnapshot
    {
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new LogicException('Las operaciones de archivos deben coordinar la transacción raíz.');
        }
        $this->authorize($actor);
        if ($id !== null) {
            $this->catalog->product($actor, $id);
        }
        // Trabajo costoso fuera del lock; nunca se persiste el original.
        $webp = $file === null ? null : $this->processor->process($file);
        $change = null;
        try {
            return $this->transactions->run(function () use ($actor, $id, $values, $webp, $remove, &$change): ProductSnapshot {
                $this->identities->lock();
                $this->authorize($actor);
                if ($id === null) {
                    $id = $this->catalog->createProduct($actor, $values)->id;
                } elseif ($values !== []) {
                    $this->catalog->updateProduct($actor, $id, $values);
                }
                $current = $this->products->find($id, lock: true);
                if ($remove && $current->image === null) {
                    return $current;
                }
                $change = $this->files->open($webp, $current->image);
                $record = $this->catalog->updateProduct($actor, $id, ['image' => $change->next]);
                $pending = $change;
                $this->transactions->afterCommit(fn () => $this->recover($pending));

                return $record;
            });
        } catch (Throwable $exception) {
            if ($change !== null) {
                $this->recover($change);
            }
            throw $exception;
        }
    }

    /** No propaga efectos secundarios que puedan aparentar revertir un commit ya confirmado. */
    public function recover(ImageChange $change): bool
    {
        if ($this->database->connection()->transactionLevel() !== 0) {
            return false;
        }
        try {
            $this->transactions->run(function () use ($change): void {
                $this->identities->lock();
                foreach ([$change->next, $change->previous] as $candidate) {
                    if ($candidate !== null && ! $this->products->referencesImage($candidate)) {
                        $this->files->delete($candidate);
                    }
                }
                $this->files->complete($change);
            });

            return true;
        } catch (Throwable $exception) {
            Log::warning('Catalog image cleanup pending.', ['change_id' => $change->id, 'exception_type' => $exception::class]);

            return false;
        }
    }
}
