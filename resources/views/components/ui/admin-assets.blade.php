@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/ui/admin.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/ui/admin.js') }}" defer></script>
    @endpush
@endonce
