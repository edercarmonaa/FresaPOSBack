@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold mb-4">Reportes</h1><p role="alert" class="rounded bg-amber-50 p-4 mb-4">{{ $message }}</p>
@if($validation)<ul class="mb-4 list-disc pl-6">@foreach($validation  as $messages)@foreach($messages  as $message)<li>{{ $message }}</li>@endforeach @endforeach</ul>@endif
<a class="inline-flex min-h-11 items-center rounded border px-4 py-2" href="{{ route('reports.index') }}">Volver a reportes</a>
@endsection
