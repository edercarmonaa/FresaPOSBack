<?php

namespace Modules\Auth\Contracts;

use Illuminate\Http\Request;
use Modules\Users\Contracts\UserSnapshot;

interface Authenticator
{
    public function loginWeb(Request $request, string $username, #[\SensitiveParameter] string $password): UserSnapshot;

    /** @return array<string,mixed> */
    public function loginApi(Request $request, string $username, #[\SensitiveParameter] string $password, string $deviceName): array;

    public function logoutWeb(Request $request): void;

    public function logoutApi(Request $request): void;

    /** @return array<string,mixed> */
    public function changePassword(Request $request, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $next, bool $api): array;
}
