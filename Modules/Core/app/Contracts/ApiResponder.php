<?php

namespace Modules\Core\Contracts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Contrato de transporte para la capa Http; no se inyecta en entidades de dominio.
interface ApiResponder
{
    /** @param array<string, mixed> $meta */
    public function success(Request $request, mixed $data, array $meta = [], int $status = 200): JsonResponse;

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string|list<string>>  $headers
     */
    public function error(Request $request, ApiCode $code, array $errors = [], ?int $status = null, array $headers = []): JsonResponse;
}
