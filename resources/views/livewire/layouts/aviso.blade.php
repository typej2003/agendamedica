{{-- El número de pendientes junto a un ítem del menú lateral (`$total`, y `$que` para el lector de pantalla). --}}
@if ($total > 0)
    <span class="app-sidebar-aviso">
        <span aria-hidden="true">{{ $total > 99 ? '99+' : $total }}</span>
        <span class="visually-hidden">{{ $total }} {{ $que }}</span>
    </span>
@endif
