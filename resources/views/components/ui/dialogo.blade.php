@props(['id', 'titulo', 'lateral' => false])

{{-- Diálogo nativo (<dialog>): Escape, foco y fondo los resuelve el navegador. Un solo diálogo por tipo de
     acción para toda la página: el script de la pantalla lo llena con los `data-*` del botón que lo abre
     y llama a `showModal()`. Adentro va un <form> con `.dialogo-cuerpo` y `.dialogo-pie`; "Cancelar" usa
     `data-cerrar-dialogo`.

     `lateral`: el diálogo entra por la derecha a alto completo. Es para **formularios** (cobrar, el
     representante, cambiar la cédula); una confirmación corta ("¿atender ahora?") sigue centrada. --}}
<dialog id="{{ $id }}" {{ $attributes->merge(['class' => 'dialogo' . ($lateral ? ' dialogo-lateral' : '')]) }} aria-labelledby="{{ $id }}-titulo">
    <div class="dialogo-caja">
        <h2 class="dialogo-titulo" id="{{ $id }}-titulo">{{ $titulo }}</h2>
        {{ $slot }}
    </div>
</dialog>
