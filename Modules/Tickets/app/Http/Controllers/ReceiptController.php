<?php

namespace Modules\Tickets\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\ApiResponder;
use Modules\Core\Contracts\OperationRejected;
use Modules\Tickets\Contracts\SaleReceipts;
use Modules\Users\Contracts\IdentityAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReceiptController
{
    public function __construct(private readonly SaleReceipts $receipts, private readonly AuthenticatedRequestAccess $access,
        private readonly ApiResponder $responses, private readonly IdentityAccess $identities) {}

    public function show(Request $request, string $id): Response
    {
        $this->noInput($request);
        $actor = (string) $request->user()?->getAuthIdentifier();
        $receipt = $this->receipts->view($actor, $id, access: fn () => $this->access->requireApi($request, $actor));

        return $this->responses->success($request, $receipt->jsonSerialize());
    }

    public function byFolio(Request $request, string $folio): Response
    {
        $this->noInput($request);
        $actor = (string) $request->user()?->getAuthIdentifier();
        $receipt = $this->receipts->view($actor, folio: $folio, access: fn () => $this->access->requireApi($request, $actor));

        return $this->responses->success($request, $receipt->jsonSerialize());
    }

    private function noInput(Request $request): void
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['receipt' => 'El comprobante se obtiene de la venta, sin parámetros financieros.']);
        }
    }

    public function preview(Request $request, string $id): Response
    {
        return $this->web($request, $id, false);
    }

    public function print(Request $request, string $id): Response
    {
        return $this->web($request, $id, true);
    }

    private function web(Request $request, string $id, bool $printing): Response
    {
        $id = strtolower($id);
        try {
            $actor = (string) Auth::guard('web')->id();
            $access = fn () => $this->access->requireWeb($request, $actor);
            if ($printing) {
                $request->validate(['intent' => ['required', 'string', 'max:8192']]);
                if (array_diff(array_keys($request->all()), ['intent', '_token']) !== []) {
                    throw ValidationException::withMessages(['receipt' => 'Campos no permitidos.']);
                }
                try {
                    $intent = json_decode(Crypt::decryptString($request->string('intent')->toString()), true, 512, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    abort(422, 'Abre nuevamente la vista previa.');
                }
                abort_unless(is_array($intent) && ($intent['actor'] ?? null) === $actor && ($intent['sale'] ?? null) === $id, 403);
                abort_unless(is_int($intent['issued'] ?? null) && $intent['issued'] <= time() && time() - $intent['issued'] <= 900, 419);
                $ticket = $this->receipts->reprint($actor, $id, $intent['revision'], $intent['key'], $access);
                $token = null;
            } else {
                $this->noInput($request);
                $ticket = $this->receipts->view($actor, $id, access: $access);
                $canPrint = in_array('tickets.print', $this->identities->inspect($actor)->permissions ?? [], true);
                $token = $canPrint ? Crypt::encryptString(json_encode(['actor' => $actor, 'sale' => $ticket->saleId, 'revision' => $ticket->revision,
                    'key' => (string) Str::uuid(), 'issued' => time()], JSON_THROW_ON_ERROR)) : null;
            }

            return response()->view('tickets::receipt', ['ticket' => $ticket, 'printing' => $printing, 'intent' => $token]);
        } catch (\Throwable $exception) {
            $status = match (true) {
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof ValidationException => 422,
                $exception instanceof OperationRejected => $exception->apiCode->status(),
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => app(ApiExceptionMappings::class)->codeFor($exception)?->status() ?? 500,
            };
            if ($status >= 500) {
                report($exception);
            }
            $message = match ($status) {
                401, 419 => 'La sesión o la intención expiró. Inicia sesión y abre nuevamente el ticket.',
                403 => 'Tu acceso actual no permite consultar o preparar este comprobante.',
                404 => 'No se encontró el comprobante solicitado.',
                409 => 'El ticket no está disponible: revisa el cierre, la corrección activa o la versión de la vista previa.',
                422 => 'La solicitud no es válida. Abre nuevamente la vista previa.',
                default => 'No se pudo preparar el comprobante. Consulta nuevamente; la venta y sus pagos se conservan.',
            };

            return response()->view('tickets::error', compact('message'), $status);
        }
    }
}
