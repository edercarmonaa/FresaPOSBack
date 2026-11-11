<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Http\InputMessages;
use Modules\Core\Contracts\ApiResponder;
use Modules\Users\Contracts\IdentityAccess;

final class ApiAuthenticationController
{
    public function __construct(private readonly Authenticator $authentication, private readonly ApiResponder $responses) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:72'], 'device_name' => ['sometimes', 'string', 'max:64']], InputMessages::get());
        $result = $this->authentication->loginApi($request, $data['username'], $data['password'], $data['device_name'] ?? 'mobile');

        return $this->responses->success($request, $result);
    }

    public function me(Request $request, IdentityAccess $identities): JsonResponse
    {
        return $this->responses->success($request, ['user' => $identities->inspect((string) $request->user()->getAuthIdentifier())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logoutApi($request);

        return $this->responses->success($request, null);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72'], 'password' => ['required', 'string', 'confirmed', 'max:72']], InputMessages::get());

        return $this->responses->success($request, $this->authentication->changePassword($request, $data['current_password'], $data['password'], true));
    }
}
