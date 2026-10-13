{{-- Tabla responsive: en pantallas chicas cada fila pasa a ser una tarjeta y cada `<td data-etiqueta="…">`
     muestra su etiqueta (no queda scroll horizontal de la página). Con `sin-tarjetas` se conserva el
     desplazamiento horizontal clásico (tablas que no tienen sentido apiladas, como el calendario).
     En modo tarjetas no hay `overflow` para que los menús de acciones de cada fila no se recorten. --}}
@props(['sinTarjetas' => false])

<div class="{{ $sinTarjetas ? 'table-responsive' : 'tabla-envoltura' }}">
    <table {{ $attributes->merge(['class' => 'table table-hover align-middle' . ($sinTarjetas ? '' : ' tabla-tarjetas')]) }}>
        {{ $slot }}
    </table>
</div>
