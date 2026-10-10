@props(['id', 'titulo'])

{{-- Diálogo nativo (<dialog>): Escape, foco y fondo los resuelve el navegador. Un solo diálogo por tipo de
     acción para toda la página: el script de la pantalla lo llena con los `data-*` del botón que lo abre
     y llama a `showModal()`. Adentro va un <form> con `.dialogo-cuerpo` y `.dialogo-pie`; "Cancelar" usa
     `data-cerrar-dialogo`. --}}
<dialog id="{{ $id }}" {{ $attributes->merge(['class' => 'dialogo']) }} aria-labelledby="{{ $id }}-titulo">
    <div class="dialogo-caja">
        <h2 class="dialogo-titulo" id="{{ $id }}-titulo">{{ $titulo }}</h2>
        {{ $slot }}
    </div>
</dialog>
