@extends('layouts.app')
@section('mainClass', 'ui-cash-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/cash.css') }}">
@endpush
@section('content')
    <x-ui.admin-assets />
    <livewire:cash.desk />
@endsection
