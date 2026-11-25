<?php

namespace Modules\Core\Contracts;

use Illuminate\Http\Response;

/** Descarga binaria explícita; el normalizador mantiene JSON para cualquier error. */
final class ApiDownloadResponse extends Response
{
    public function __construct(string $contents, string $mime, string $filename)
    {
        if (! in_array($mime, ['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)
            || ! preg_match('/\A[a-z0-9.-]{1,160}\z/', $filename)) {
            throw new \InvalidArgumentException('Descarga no admitida.');
        }
        parent::__construct($contents, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
