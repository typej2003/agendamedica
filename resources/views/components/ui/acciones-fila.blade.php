@props(['etiqueta' => null])

{{--
**Las acciones de una fila**, al extremo derecho y en una sola línea (guía de UI §5 y la pauta que fijó Alexander el
2026-10-17):

- **Hasta dos acciones → botones directos**, en la misma línea, alineados a la derecha y con el mismo `gap` (`.acciones-fila`
  del sistema). Es lo que se ve en el catálogo de medicamentos: «Editar» y la papelera.
- **Desde tres acciones → menú «…»** (`<x-ui.menu>`), nunca tres o más botones sueltos.
- **Un menú de una sola opción está prohibido**: si sólo hay una acción secundaria, va como botón (o como parte del
  botón principal); el `<details>` de un solo ítem rompe la alineación de la fila y esconde lo único que se puede hacer.

El botón de una acción destructiva va **al lado** de las demás, con el tono de peligro (`btn-outline-danger`) y su
`data-confirmar` en el `<form>` que lo envía (`shell.js` pide la confirmación). Un botón que es sólo icono lleva
`aria-label` y `title`.

Ejemplo (dos acciones, la segunda destructiva):

    <x-ui.acciones-fila>
        <a class="btn btn-sm btn-outline-secondary" href="…"><i class="bi bi-pencil"></i> Editar</a>
        <form method="POST" action="…" data-confirmar="¿…?">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger" aria-label="Quitar …" title="Quitar …">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </form>
    </x-ui.acciones-fila>

El contenedor NO decide el ancho de la fila: se apoya en `.acciones-fila`, que ya alinea al extremo y unifica el
espaciado; la celda que lo contiene va con `text-end` (y `celda-acciones` en las tablas).

Con `etiqueta`, el grupo se anuncia (`role="group"` + `aria-label`): un `aria-label` suelto en un `<div>` no lo lee
ningún lector de pantalla.
--}}
<div {{ $attributes->merge(['class' => 'acciones-fila']) }} @if ($etiqueta) role="group" aria-label="{{ $etiqueta }}" @endif>
    {{ $slot }}
</div>
