<?php

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;

// Marca respuestas construidas por Core; evita confiar en JSON arbitrario de errores.
final class ApiJsonResponse extends JsonResponse {}
