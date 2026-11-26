@extends('layouts.app')
@section('mainClass', 'ui-reasons-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/reasons.css') }}">
@endpush
@section('content')
    <x-ui.admin-assets />
    <livewire:sales.reasons />
@endsection
