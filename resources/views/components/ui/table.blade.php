@props(['caption'])
<div {{ $attributes->class(['ui-table-region'])->merge(['tabindex' => '0', 'role' => 'region', 'aria-label' => $caption]) }}>
    <table class="ui-table">
        <caption>{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
