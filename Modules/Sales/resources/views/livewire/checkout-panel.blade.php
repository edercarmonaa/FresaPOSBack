<section class="pos-spaced pos-editor" aria-label="Cobro de venta">
    <h3>Cobro de venta</h3>
    @if($status !== '')<p role="status" class="pos-success">{{ $status }}</p>@endif
    @if($errors->any())<div class="pos-error" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @if($receipt !== [])
        <h3>{{ $receipt['total']==='0.0000' ? 'Venta cerrada con descuento total' : 'Venta cobrada' }}</h3><p>Folio <strong data-checkout-folio>{{ $receipt['folio'] }}</strong></p>
        <p>{{ $receipt['total']==='0.0000' ? 'Total de cierre' : 'Total pagado' }}: <strong>${{ $receipt['total'] }}</strong></p><p class="pos-muted pos-small">Cierre: {{ $receipt['closed_at'] }} · UTC</p>
        @forelse($receipt['payments'] ?? [] as $payment)
            <div class="pos-line"><strong>{{ ['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','other'=>'Otro autorizado'][$payment['method_code']] }}</strong><p>Aplicado: ${{ $payment['applied_amount'] }}</p>@if($payment['received_cash'] !== null)<p>Recibido: ${{ $payment['received_cash'] }} · Cambio: <strong>${{ $payment['change_cash'] }}</strong></p>@endif</div>
        @empty<p>Venta totalmente descontada con autorización previa, sin pagos.</p>@endforelse
    @else
        <button type="button" wire:click="openCheckout" wire:loading.attr="disabled" @disabled($pending)>{{ $submitted ? 'Preparar cobro' : 'Cobrar' }}</button>
        @if($opened)
            <form wire:submit="confirmCheckout" class="pos-spaced">
                @foreach($payments as $index => $payment)
                    <fieldset class="pos-line" wire:key="checkout-payment-{{ $index }}" @disabled($submitted || $pending) style="min-width:0">
                        <legend>Pago {{ $index + 1 }}</legend>
                        <label class="pos-label" for="checkout-method-{{ $index }}">Método</label>
                        <select id="checkout-method-{{ $index }}" wire:model.live="payments.{{ $index }}.payment_method_id" style="width:100%;min-height:44px"><option value="">Selecciona un método</option>@foreach($methods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach</select>
                        <label class="pos-label" for="checkout-applied-{{ $index }}">Importe aplicado</label><input id="checkout-applied-{{ $index }}" wire:model="payments.{{ $index }}.applied_amount" inputmode="decimal" maxlength="20" autocomplete="off">
                        @if(collect($methods)->firstWhere('id', $payment['payment_method_id'] ?? '')?->code === 'cash')
                            <label class="pos-label" for="checkout-received-{{ $index }}">Efectivo recibido</label><input id="checkout-received-{{ $index }}" wire:model="payments.{{ $index }}.received_cash" inputmode="decimal" maxlength="20" autocomplete="off">
                        @else
                            <label class="pos-label" for="checkout-reference-{{ $index }}">Referencia del comprobante</label><input id="checkout-reference-{{ $index }}" wire:model="payments.{{ $index }}.reference" maxlength="64" autocomplete="off" aria-describedby="checkout-reference-help">
                        @endif
                        <button type="button" wire:click="removePayment({{ $index }})" wire:loading.attr="disabled">Retirar este pago</button>
                    </fieldset>
                @endforeach
                <p id="checkout-reference-help" class="pos-muted pos-small">Transferencia y otro requieren referencia. No ingreses números de tarjeta, CVV o cuentas bancarias.</p>
                @if(($preview['total'] ?? null)==='0.0000')<p>Descuento total autorizado. Este cierre genera folio, conserva la venta y no registra pagos, efectivo ni cambio.</p>@endif
                <div class="pos-row"><button type="button" wire:click="addPayment" @disabled($submitted || $pending || ($preview['total'] ?? null)==='0.0000') wire:loading.attr="disabled">Agregar método</button><button type="button" wire:click="calculate" @disabled($submitted || $pending) wire:loading.attr="disabled">{{ ($preview['total'] ?? null)==='0.0000' ? 'Verificar cierre sin pago' : 'Calcular cambio' }}</button></div>
                @if($preview !== [])<dl class="pos-totals"><div><dt>Total vigente</dt><dd>${{ $preview['total'] }}</dd></div><div><dt>Faltante</dt><dd>${{ $preview['remaining'] }}</dd></div><div><dt>Cambio calculado</dt><dd>${{ $preview['change'] }}</dd></div></dl>@endif
                <p class="pos-muted pos-small">{{ ($preview['total'] ?? null)==='0.0000' ? 'Confirmar cierra esta venta con folio y sin pagos. Revisa el descuento autorizado.' : 'Confirmar registra los pagos y cierra financieramente esta venta. Revisa los importes.' }}</p>
                <button type="submit" class="pos-primary" wire:loading.attr="disabled" @disabled($pending)>{{ ($preview['total'] ?? null)==='0.0000' ? 'Confirmar cierre sin pago' : 'Confirmar cobro' }}</button>
            </form>
        @endif
    @endif
    <button type="button" wire:click="queryCheckout" wire:loading.attr="disabled" class="pos-spaced">Consultar estado del cobro</button>
    <p wire:loading role="status" class="pos-muted">Procesando… Conservamos la intención; espera la confirmación.</p>
    <p wire:offline role="alert" class="pos-error">Sin conexión. Al regresar, consulta el estado antes de reintentar el mismo cobro.</p>
</section>
