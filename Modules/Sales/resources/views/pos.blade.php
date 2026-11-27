@extends('layouts.app')
@section('mainClass', 'ui-pos-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/pos.css') }}">
@endpush
@section('content')
    <livewire:sales.pos />
@endsection
