<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Core\Contracts\ApiDownloadResponse;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\ApiResponder;
use Modules\Core\Contracts\ExceptionDiagnostic;
use Modules\Core\Contracts\OperationRejected;
use Modules\Reports\Application\ExportOperationalReport;
use Modules\Reports\Contracts\GeneratedReport;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Reports\Http\Requests\ReportRequest;
use Modules\Reports\Http\Resources\ReportResource;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReportController
{
    public function __construct(private readonly OperationalReports $reports, private readonly ExportOperationalReport $exports,
        private readonly AuthenticatedRequestAccess $access, private readonly ApiResponder $responses) {}

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    public function api(ReportRequest $request, string $report): Response
    {
        $actor = $this->actor($request);
        $data = $this->reports->read($actor, $report, $request->criteria(), fn () => $this->access->requireApi($request, $actor));

        return $this->responses->success($request, (new ReportResource($data))->resolve($request));
    }

    public function apiExport(ReportRequest $request, string $report, string $format): Response
    {
        $actor = $this->actor($request);
        $file = $this->exports->generate($actor, $report, $format, $request->criteria(), fn () => $this->access->requireApi($request, $actor));

        return $this->download($file);
    }

    public function web(ReportRequest $request, string $report = 'summary'): Response
    {
        try {
            $actor = $this->actor($request);
            $data = $this->reports->read($actor, $report, $request->criteria(), fn () => $this->access->requireWeb($request, $actor));

            return response()->view('reports::index', ['data' => $data, 'reportNames' => ReportDefinition::NAMES]);
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function webExport(ReportRequest $request, string $report, string $format): Response
    {
        try {
            $actor = $this->actor($request);
            $file = $this->exports->generate($actor, $report, $format, $request->criteria(), fn () => $this->access->requireWeb($request, $actor));

            return $this->download($file);
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function download(GeneratedReport $file): Response
    {
        return new ApiDownloadResponse($file->contents, $file->mime, $file->filename);
    }

    private function error(\Throwable $exception): Response
    {
        $status = match (true) {
            $exception instanceof AuthorizationException => 403,$exception instanceof ValidationException => 422,
            $exception instanceof OperationRejected => $exception->apiCode->status(),$exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => app(ApiExceptionMappings::class)->codeFor($exception)?->status() ?? 500
        };
        if ($status >= 500) {
            Log::error('Report request failed.', ExceptionDiagnostic::context($exception));
        }
        $message = match ($status) {
            403 => 'Tu acceso actual no permite consultar o exportar este reporte financiero.',401,419 => 'La sesión expiró. Inicia sesión nuevamente.',
            422 => 'Revisa los filtros, el rango máximo de 366 días y el límite de exportación: PDF 300 filas, Excel 1000 filas.',503 => 'El reporte está ocupado. Intenta nuevamente con un rango menor.',
            default => 'No se pudo completar el reporte. Los datos financieros permanecen conservados.'
        };

        return response()->view('reports::error', ['message' => $message, 'validation' => $exception instanceof ValidationException ? $exception->errors() : []], $status);
    }
}
