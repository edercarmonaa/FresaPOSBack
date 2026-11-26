@extends('layouts.app')
@section('mainClass', 'ui-catalog-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/catalog.css') }}">
@endpush
@section('content')
    <x-ui.admin-assets />
    <h1 class="ui-catalog-title">Productos</h1>
    <livewire:catalog.products />
@endsection
