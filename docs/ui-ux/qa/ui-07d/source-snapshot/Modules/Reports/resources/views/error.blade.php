@extends('layouts.app')
@section('mainClass', 'ui-report-page ui-report-admin-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/reports.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui/report-admin.css') }}">
@endpush
@section('content')
<div class="ui-report-card ui-report-error"><h1 class="ui-report-title mb-4">Reportes</h1><p role="alert" class="ui-report-warning">{{ $message }}</p>
@if($validation)<ul class="mb-4 list-disc pl-6">@foreach($validation  as $messages)@foreach($messages  as $message)<li>{{ $message }}</li>@endforeach @endforeach</ul>@endif
<a class="report-action ui-button ui-button--secondary" href="{{ route('reports.index') }}">Volver a reportes</a>
</div>
@endsection
