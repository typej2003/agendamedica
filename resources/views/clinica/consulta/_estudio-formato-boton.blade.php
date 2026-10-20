{{-- El botón «Plantillas del formato» del estudio: va en el encabezado de la tarjeta, junto al estado (slot
     `acciones`). El cajón que abre es `_estudio-formato`, que la sección pinta una vez fuera de la tarjeta.
     `$formato` puede ser `null` (el estudio no tiene plantillas del formato) y entonces no se pinta nada. --}}
@if (($formato ?? null) !== null)
    @php
        $idFormato = 'plantillas-formato-' . $formato['tipo'];
        $cuentaFormato = count($formato['plantillas']);
    @endphp
    <span class="d-inline-flex me-2" data-formato-panel
          data-formato-form='form[action$="/estudio/{{ $formato['tipo'] }}"]'
          data-formato-campos='@json($formato['campos'])'
          data-formato-boton="{{ $idFormato }}-disparador"
          data-url-guardar="{{ $formato['urlGuardar'] }}">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-abrir-dialogo="{{ $idFormato }}"
                aria-haspopup="dialog" aria-controls="{{ $idFormato }}" id="{{ $idFormato }}-disparador">
            <i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i>
            <span>Plantillas del formato <span data-formato-cuenta>@if ($cuentaFormato > 0)({{ $cuentaFormato }})@endif</span></span>
        </button>
    </span>
@endif
