{{-- Tabla responsive: cuando el **contenedor** no da para más, cada fila pasa a ser una tarjeta y cada
     `<td data-etiqueta="…">` muestra su etiqueta. **No hay scroll horizontal**: la tabla nunca se desliza dentro
     de su tarjeta (regla del usuario, 2026-11-06). Con `sin-tarjetas` se conserva el desplazamiento clásico, y es
     sólo para las grillas que se recorren así (el calendario). En modo tarjetas no hay `overflow` para que los
     menús de acciones de cada fila no se recorten. --}}
@props(['sinTarjetas' => false])

<div class="{{ $sinTarjetas ? 'tabla-envoltura tabla-envoltura-desplazable' : 'tabla-envoltura' }}">
    <table {{ $attributes->merge(['class' => 'table table-hover align-middle' . ($sinTarjetas ? '' : ' tabla-tarjetas')]) }}>
        {{ $slot }}
    </table>
</div>
