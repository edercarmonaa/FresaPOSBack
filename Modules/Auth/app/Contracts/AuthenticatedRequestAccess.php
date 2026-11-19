<?php

namespace Modules\Auth\Contracts;

use Illuminate\Http\Request;

interface AuthenticatedRequestAccess
{
    public function requireWeb(Request $request, string $actorId): void;

    public function requireApi(Request $request, string $actorId): void;
}
