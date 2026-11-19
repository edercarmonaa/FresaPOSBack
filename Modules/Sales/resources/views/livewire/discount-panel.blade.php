<section class="pos-editor pos-spaced sale-discounts" aria-label="Descuento autorizado de venta">
    <h3>Descuento autorizado</h3>
    @if($status)<p role="status" class="pos-success">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="pos-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($sale->sale->discountAuthorization)
        <p>{{ $sale->sale->discountAuthorization['type']==='percent' ? 'Porcentaje' : 'Importe fijo' }}: {{ $sale->sale->discountAuthorization['value'] }}{{ $sale->sale->discountAuthorization['type']==='percent' ? ' %' : '' }} · Aplicado ${{ $sale->sale->discount }} · Total ${{ $sale->sale->total }}</p>
    @else<p>Sin descuento vigente.</p>@endif
    @if($sale->sale->state->value==='open')
        <div class="pos-row"><button type="button" wire:click="prepare" wire:loading.attr="disabled">{{ $sale->sale->discountAuthorization ? 'Sustituir descuento' : 'Aplicar descuento' }}</button>@if($sale->sale->discountAuthorization)<button type="button" wire:click="prepare(true)" wire:loading.attr="disabled">Quitar descuento</button>@endif</div>
    @endif
    @if($opened)
        <form wire:submit="confirm" class="pos-spaced" aria-label="Confirmación de descuento autorizado">
            @if($removing)<p>Confirma quitar el descuento vigente. Se conservará la auditoría y se restaurará el total del subtotal vigente.</p>
            @else
                <label class="pos-label" for="discount-type">Tipo de descuento</label><select id="discount-type" wire:model.live="type" @disabled($submitted) style="width:100%;min-height:44px"><option value="percent">Porcentaje entero</option><option value="fixed">Importe fijo</option></select>
                <label class="pos-label" for="discount-value">{{ $type==='percent' ? 'Porcentaje (1 a 100)' : 'Importe fijo (hasta cuatro decimales)' }}</label><input id="discount-value" wire:model="value" inputmode="decimal" maxlength="20" autocomplete="off" @disabled($submitted)>
            @endif
            @if($submitted)<p>La solicitud ya enviada permanece congelada; el reintento conserva tipo, valor, revisión y clave.</p>@endif
            <button type="button" wire:click="calculate" wire:loading.attr="disabled" @disabled($submitted)>Calcular vista previa</button>
            @if($preview)<dl class="pos-totals"><div><dt>Subtotal</dt><dd>${{ $preview['subtotal'] }}</dd></div><div><dt>Descuento</dt><dd>${{ $preview['discount_amount'] }}</dd></div><div><dt>Total previsto</dt><dd>${{ $preview['total'] }}</dd></div></dl>@endif
            <p class="pos-muted pos-small">El servidor valida nuevamente importes y permisos al confirmar. Un total cero se cierra con folio mediante Checkout, sin pagos ficticios; no es una cancelación.</p>
            <button type="submit" wire:loading.attr="disabled">Confirmar descuento autorizado</button>
        </form>
    @endif
    @if($key!=='')<button type="button" wire:click="queryOperation" wire:loading.attr="disabled">Consultar estado del descuento</button>@endif
    <p wire:loading role="status">Procesando… Espera la confirmación.</p><p wire:offline role="alert" class="pos-error">Sin conexión. Conserva la intención y consulta su estado antes de preparar otra.</p>
</section>
