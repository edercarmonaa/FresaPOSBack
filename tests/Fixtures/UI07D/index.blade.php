@extends('layouts.app')
@section('mainClass', 'ui-report-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/reports.css') }}">
@endpush
@section('content')
@php
    $presentation=\Modules\Reports\Application\ReportPresentation::class;
    $definitions=\Modules\Reports\Domain\ReportDefinition::class;
    $filters=$data->filters;
    $filterFields=$definitions::filters($data->report);
    $filterLabels=['employee_id'=>'Empleado (ID)','cash_session_id'=>'Sesión Cash (ID)','product_id'=>'Producto (ID histórico)','category_id'=>'Categoría (ID histórico)'];
    $selects=['sale_type'=>['counter'=>'Mostrador','table'=>'Mesa'],'payment_method'=>['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','other'=>'Otro autorizado'],
        'discount_type'=>['fixed'=>'Fijo','percent'=>'Porcentaje'],'movement_type'=>['cash_in'=>'Entrada','cash_out'=>'Salida'],
        'state'=>match($data->report){'sales'=>['open'=>'Abierta','paused'=>'Pausada','cancelled'=>'Cancelada','closed'=>'Pagada'],'corrections'=>['active'=>'Activa','confirmed'=>'Confirmada','unchanged'=>'Sin cambios','discarded'=>'Descartada'],default=>['open'=>'Abierta','closed'=>'Cerrada']}];
    $selectLabels=['sale_type'=>'Tipo de venta','payment_method'=>'Ventas que usaron este método','discount_type'=>'Descuento','movement_type'=>'Tipo de movimiento','state'=>'Estado'];
@endphp
<header class="ui-report-heading mb-5"><p class="text-sm text-slate-600">Información operativa y financiera</p><h1 class="ui-report-title">Reportes</h1><p class="mt-2 text-sm text-slate-600">{{ $data->filters['from'] }} al {{ $data->filters['until'] }} · {{ $data->timezone }} · Generado {{ $presentation::value($data->generatedAt,'datetime',$data->timezone) }}</p></header>
<nav class="report-nav mb-6" aria-label="Elegir reporte">@foreach($reportNames  as $key=>$name)<a href="{{ route('reports.show',['report'=>$key,'range'=>'custom','from'=>$filters['from'],'until'=>$filters['until']]) }}" @if($key===$data->report) aria-current="page" @endif>{{ $name }}</a>@endforeach</nav>
<section class="ui-report-card rounded-xl border border-slate-200 bg-white p-4 mb-6" aria-labelledby="filters-heading"><h2 id="filters-heading" class="text-xl font-semibold mb-4">{{ $data->title }}</h2>
<form method="get" action="{{ route('reports.show',['report'=>$data->report]) }}" class="report-filters">
<label>Período<select name="range" data-report-range>@foreach(['today'=>'Hoy','yesterday'=>'Ayer','last_seven_days'=>'Últimos siete días','current_month'=>'Mes actual','previous_month'=>'Mes anterior','custom'=>'Personalizado'] as $value=>$label)<option value="{{ $value }}" @selected($value===($filters['range']??'custom'))>{{ $label }}</option>@endforeach</select></label>
<label>Desde<input type="date" name="from" value="{{ $filters['from'] }}" data-report-date required></label><label>Hasta<input type="date" name="until" value="{{ $filters['until'] }}" data-report-date required></label>
@foreach($filterFields  as $field)
@if(isset($selects[$field]))<label>{{ $selectLabels[$field] }}<select name="{{ $field }}"><option value="">Todos</option>@foreach($selects[$field] as $value=>$label)<option value="{{ $value }}" @selected(($filters[$field]??'')===$value)>{{ $label }}</option>@endforeach</select></label>
@else<label>{{ $filterLabels[$field]??$field }}<input name="{{ $field }}" value="{{ $filters[$field]??'' }}" maxlength="26" placeholder="Opcional"></label>@endif
@endforeach
<label>Ordenar<select name="sort">@foreach($definitions::sorts($data->report) as $sort)<option value="{{ $sort }}" @selected($sort===($filters['sort']??''))>{{ $data->columns[$sort][0]??$sort }}</option>@endforeach</select></label>
<label>Dirección<select name="direction"><option value="desc" @selected($filters['direction']==='desc')>Descendente</option><option value="asc" @selected($filters['direction']==='asc')>Ascendente</option></select></label>
<label>Filas por página<select name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($data->perPage===$size)>{{ $size }}</option>@endforeach</select></label>
<div class="flex flex-wrap gap-2 items-end"><button class="report-action primary" type="submit">Consultar</button><a class="report-action" href="{{ route('reports.show',['report'=>$data->report]) }}">Hoy / limpiar</a></div>
</form><p class="mt-3 text-sm text-slate-600">Hasta 366 días. Los identificadores permiten elegir exactamente empleado, sesión o versión histórica. El filtro de método conserva todos los pagos mixtos de las ventas seleccionadas.</p></section>
<section aria-label="Indicadores del conjunto filtrado" class="report-kpis mb-6">@foreach($data->totals  as $key=>$value)<dl class="report-kpi"><dt>{{ $presentation::METRICS[$key]??'Indicador' }}</dt><dd>{{ $value===null?'No disponible':$value }}</dd></dl>@endforeach</section>
@if($data->report==='summary'&&$data->series)
<section class="ui-report-card rounded-xl border bg-white p-4 mb-6" aria-labelledby="chart-title"><h2 id="chart-title" class="text-lg font-semibold mb-3">Ingreso confirmado por día comercial</h2><p class="text-sm text-slate-600 mb-3">Todos los días con ventas del rango. Los valores también están disponibles en la tabla.</p><div class="report-chart" role="region" aria-label="Ingreso confirmado por día comercial" tabindex="0">@php($max=$presentation::maximum($data->series,'income'))@foreach($data->series  as $day)<div class="report-chart-row"><span>{{ substr($day['business_date'],0,10) }}</span><svg aria-hidden="true" viewBox="0 0 {{ $max }} 1" preserveAspectRatio="none"><rect width="{{ $day['income'] }}" height="1" fill="currentColor"/></svg><strong>${{ $day['income'] }}</strong></div>@endforeach</div></section>
@endif
<section class="ui-report-card rounded-xl border bg-white p-4 mb-6"><h2 class="text-lg font-semibold mb-3">Criterios del reporte</h2><ul class="list-disc pl-5 text-sm space-y-2">@foreach($data->notes  as $note)<li>{{ $note }}</li>@endforeach</ul></section>
<section class="ui-report-card rounded-xl border bg-white p-4" aria-labelledby="results-heading"><div class="flex flex-wrap justify-between gap-4 mb-4"><div><h2 id="results-heading" class="text-xl font-semibold">Resultados</h2><p class="text-sm text-slate-600">{{ $data->total }} filas en el conjunto filtrado. Los indicadores incluyen todas las páginas.</p></div>
@can('reports.export')<div class="flex gap-2 flex-wrap">@foreach(['pdf'=>'Exportar PDF','xlsx'=>'Exportar Excel'] as $format=>$label)<form method="post" action="{{ route('reports.export',['report'=>$data->report,'format'=>$format]) }}">@csrf @foreach($filters  as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<button type="submit" class="report-action" @disabled($data->total>($format==='pdf'?300:1000))>{{ $label }}</button></form>@endforeach</div>@endcan</div>
<p class="text-sm text-slate-600 mb-4">Exporta el conjunto completo: PDF hasta 300 filas; Excel hasta 1000. Cada generación autorizada queda registrada.</p>
<div class="ui-report-table-scroll" role="region" aria-label="Resultados del reporte" tabindex="0"><table class="report-table"><caption class="sr-only">{{ $data->title }}</caption>@if($data->report==='sales')<colgroup>@foreach([8,10,7,8,10,7,7,7,6,9,6,7,8] as $width)<col style="width:{{ $width }}%">@endforeach</colgroup>@endif<thead><tr>@foreach($data->columns  as [$label,$type])<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Detalle autorizado</th></tr></thead><tbody>
@forelse($data->items  as $item)<tr>@foreach($data->columns  as $key=>[$label,$type])<td data-label="{{ $label }}" class="{{ in_array($type,['money','integer','decimal'])?'money':'' }} {{ str_ends_with($key,'_id')?'identifier':'' }}">{{ $presentation::value($item[$key]??null,$type,$data->timezone) }}</td>@endforeach
<td data-label="Detalle" class="report-links">@if(isset($item['sale_id']))<a href="{{ route('sales.pos',['sale'=>$item['sale_id']]) }}">Abrir venta</a>@elseif(isset($item['cash_session_id']))<a href="{{ route('cash.desk') }}">Abrir Cash</a>@else — @endif</td></tr>
@empty<tr><td colspan="{{ count($data->columns)+1 }}">No hay operaciones para los filtros elegidos.</td></tr>@endforelse
</tbody></table></div>
@php($lastPage=max(1,intdiv($data->total+$data->perPage-1,$data->perPage)))<nav class="report-pager mt-5" aria-label="Paginación de reportes">@if($data->page>1)<a class="report-action" href="{{ route('reports.show',['report'=>$data->report,...$filters,'page'=>$data->page-1,'per_page'=>$data->perPage]) }}">Anterior</a>@endif<span>Página {{ $data->page }} de {{ $lastPage }}</span>@if($data->page<$lastPage)<a class="report-action" href="{{ route('reports.show',['report'=>$data->report,...$filters,'page'=>$data->page+1,'per_page'=>$data->perPage]) }}">Siguiente</a>@endif</nav></section>
<script>document.querySelectorAll('[data-report-range]').forEach(function(select){function sync(){select.form.querySelectorAll('[data-report-date]').forEach(function(input){input.disabled=select.value!=='custom';});}select.addEventListener('change',sync);sync();});document.querySelectorAll('.report-filters').forEach(function(form){form.addEventListener('submit',function(){form.querySelectorAll('input:not([type=date]),select:not([name=range])').forEach(function(input){if(input.value===''){input.disabled=true;}});});});</script>
@endsection
