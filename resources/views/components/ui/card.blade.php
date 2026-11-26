@props(['title' => null])
<section {{ $attributes->class(['ui-card']) }}>
    @if ($title)<h2 class="ui-card-title">{{ $title }}</h2>@endif
    {{ $slot }}
</section>
