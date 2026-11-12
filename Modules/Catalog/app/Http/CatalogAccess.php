<?php

namespace Modules\Catalog\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class CatalogAccess
{
    public const PRODUCT_WRITES = ['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore'];

    public function __construct(private readonly IdentityAccess $identities, private readonly CatalogPolicy $policy) {}

    public function identity(?Request $request = null): ?UserSnapshot
    {
        $id = $request ? $request->user()?->getAuthIdentifier() : Auth::guard('web')->id();

        return $id === null ? null : $this->identities->inspect((string) $id);
    }

    public function can(string $permission, ?Request $request = null): bool
    {
        $actor = $this->identity($request);

        return $actor !== null && $this->policy->allows($actor, $permission);
    }

    /** @param list<string> $permissions */
    public function requireAny(array $permissions, ?Request $request = null): string
    {
        $actor = $this->identity($request);
        abort_if($actor === null, 401);
        foreach ($permissions as $permission) {
            if ($this->policy->allows($actor, $permission)) {
                return $actor->id;
            }
        }
        abort(403);
    }

    public function web(string $kind): string
    {
        if ($kind === 'categories') {
            return $this->requireAny(['catalog.categories.manage']);
        }
        $this->requireAny(['catalog.products.view']);

        return $this->requireAny(self::PRODUCT_WRITES);
    }

    public function trash(bool $products, ?Request $request = null): void
    {
        $this->requireAny([$products ? 'catalog.products.restore' : 'catalog.categories.manage'], $request);
    }
}
