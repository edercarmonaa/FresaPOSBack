<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
    <title>Ticket {{ $ticket->folio }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#000;font-family:Arial,sans-serif}.receipt-controls{max-width:680px;margin:20px auto;padding:16px;line-height:1.5}.receipt-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center}.receipt-actions form{margin:0}.receipt-actions button,.receipt-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border:1px solid #334155;border-radius:6px;background:#fff;color:#172554;font:inherit;text-decoration:none;cursor:pointer}.receipt-actions button{background:#172554;color:#fff}.receipt-actions :focus-visible{outline:3px solid #2563eb;outline-offset:3px}.receipt{width:80mm;max-width:calc(100% - 16px);padding:3mm;margin:16px auto 32px;background:#fff;box-shadow:0 1px 8px #cbd5e1;font-size:9.5pt;line-height:1.35;overflow-wrap:anywhere}.receipt h1{font-size:12pt;margin:0 0 2mm}.receipt p{margin:1.5mm 0}.receipt-header{text-align:center}.receipt-section{border-top:1px dashed #000;margin-top:3mm;padding-top:2mm}.receipt-line{break-inside:avoid;page-break-inside:avoid;margin:2.5mm 0}.receipt-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2mm;align-items:start}.receipt-amount{white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums;font-size:9pt}.receipt-note{white-space:pre-wrap;font-size:9pt}.receipt-total{font-size:12pt;font-weight:bold;border-top:1px solid #000;padding-top:2mm;margin-top:2mm}.receipt-total .receipt-amount{font-size:11pt}.receipt-small{font-size:8pt}.receipt-mark{text-align:center;font-weight:bold;letter-spacing:.4px}.receipt-items{list-style:none;padding:0;margin:0}.receipt-meta{margin:0}.receipt-meta dt{font-weight:bold}.receipt-meta dd{margin:0 0 1.5mm}
        @page{size:auto;margin:3mm}
        @media print{html,body{background:#fff;margin:0;padding:0;width:auto}.receipt-controls{display:none!important}.receipt{width:74mm;max-width:100%;margin:0;padding:0;border:0;box-shadow:none;overflow:visible}.receipt-section{break-inside:auto}.receipt-total{break-inside:avoid}a{color:#000;text-decoration:none}}
    </style>
</head>
<body>
<section class="receipt-controls" aria-label="Acciones del comprobante">
    <h1>{{ $printing ? 'Comprobante preparado para reimpresión' : 'Vista previa del ticket' }}</h1>
    <p>Usa papel de 80 mm en las opciones de la impresora, escala 100 % y desactiva encabezados y pies del navegador. Comprueba los márgenes del dispositivo.</p>
    <div class="receipt-actions">
        @if($printing)
            <button type="button" onclick="window.print()" data-receipt-print>Imprimir desde navegador</button>
            <a href="{{ route('tickets.preview', ['id'=>$ticket->saleId]) }}">Nueva vista previa</a>
        @elseif($intent !== null)
            <form method="post" action="{{ route('tickets.print', ['id'=>$ticket->saleId]) }}">@csrf<input type="hidden" name="intent" value="{{ $intent }}"><button type="submit">Preparar impresión / reimpresión</button></form>
        @else
            <p>Tu acceso permite consultar el ticket. Preparar una reimpresión requiere autorización.</p>
        @endif
        <a href="{{ route('sales.pos', ['sale'=>$ticket->saleId]) }}">Regresar al POS</a>
    </div>
    <p>Se registra la solicitud de reimpresión. El navegador no confirma que el papel se haya impreso.</p>
</section>
<main class="receipt" aria-label="Ticket de venta" data-receipt-folio="{{ $ticket->folio }}">
    <header class="receipt-header">
        @if(isset($ticket->establishment['name']))<h1>{{ $ticket->establishment['name'] }}</h1>@else<h1>Comprobante de venta</h1>@endif
        @foreach(['branch','address','phone','fiscal_id'] as $field)@if(isset($ticket->establishment[$field]))<p>{{ $ticket->establishment[$field] }}</p>@endif @endforeach
    </header>
    <p class="receipt-mark">{{ $printing ? 'REIMPRESIÓN · COPIA AUTORIZADA' : 'VISTA PREVIA' }}</p>
    <section class="receipt-section">
        <p>Folio: <strong>{{ $ticket->folio }}</strong></p><p>Cierre: {{ $ticket->localClosedAt }}</p><p class="receipt-small">Zona: {{ $ticket->timezone }}</p>
        <p>{{ $ticket->saleType === 'table' ? 'Venta de mesa' : 'Venta de mostrador' }}</p>
        @if($ticket->employee !== null)<p>Atendió: {{ $ticket->employee }}</p>@endif
        @if($ticket->correction !== null)<p>Venta corregida · folio original conservado</p><p class="receipt-small">Corrección confirmada: {{ $ticket->correction['confirmed_at'] }} · UTC</p>@endif
    </section>
    <section class="receipt-section" aria-label="Productos históricos">
        <ol class="receipt-items">@foreach($ticket->items as $item)
            <li class="receipt-line"><div class="receipt-row"><strong>{{ $item['name'] }}</strong><span class="receipt-amount">${{ $item['line_total'] }}</span></div><p>{{ $item['quantity'] }} × ${{ $item['unit_price'] }}</p>@if($item['note'] !== null)<p class="receipt-note">{{ $item['note'] }}</p>@endif</li>
        @endforeach</ol>
    </section>
    @if($ticket->note !== null)<section class="receipt-section"><p class="receipt-note">{{ $ticket->note }}</p></section>@endif
    <section class="receipt-section" aria-label="Importes confirmados">
        <p class="receipt-row"><span>Subtotal</span><span class="receipt-amount">${{ $ticket->subtotal }}</span></p>
        @if($ticket->discount !== null || $ticket->discountAmount !== '0.0000')
            <p class="receipt-row"><span>Descuento {{ ($ticket->discount['type'] ?? null)==='percent' ? $ticket->discount['value'].' %' : 'fijo' }}</span><span class="receipt-amount">−${{ $ticket->discountAmount }}</span></p>
        @endif
        <p class="receipt-row receipt-total"><span>Total</span><span class="receipt-amount">${{ $ticket->total }}</span></p>
    </section>
    <section class="receipt-section" aria-label="Pagos confirmados">
        @forelse($ticket->payments as $payment)<div class="receipt-line"><p class="receipt-row"><strong>{{ $payment['method_name'] }}</strong><span class="receipt-amount">${{ $payment['applied_amount'] }}</span></p>@if($payment['method_code']==='cash')<p class="receipt-row"><span>Recibido</span><span class="receipt-amount">${{ $payment['received_cash'] }}</span></p><p class="receipt-row"><span>Cambio</span><span class="receipt-amount">${{ $payment['change_cash'] }}</span></p>@endif</div>
        @empty<p>Descuento total autorizado. Sin cobro ni pagos.</p>@endforelse
    </section>
    @if(isset($ticket->establishment['footer']))<section class="receipt-section receipt-header"><p>{{ $ticket->establishment['footer'] }}</p></section>@endif
</main>
</body></html>
