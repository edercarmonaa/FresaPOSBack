@include('sales::navigation')
@include('cash::navigation')
@unless(request()->routeIs('sales.pos'))
    @include('catalog::navigation')
    @include('sales::reasons-navigation')
    @include('reports::navigation')
@endunless
