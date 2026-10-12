{{-- Un bloque de renglones para escribir a mano en un formato en blanco (WEB-5.5b): su rótulo (si lo tiene) y las líneas, que llenan el alto que
     le toca por su `peso` y se cortan limpias donde se acaba el cuerpo. Recibe `$seccion` (`etiqueta`, `peso`). --}}
<div class="seccion-en-blanco seccion-peso-{{ $seccion['peso'] }}">
    @if ($seccion['etiqueta'] !== null)
        <p class="documento-parrafo seccion-en-blanco-rotulo"><strong class="documento-rotulo">{{ $seccion['etiqueta'] }}</strong></p>
    @endif

    <div class="renglones-en-blanco" aria-hidden="true">
        @for ($renglon = 0; $renglon < 40; $renglon++)
            <span class="renglon-en-blanco"></span>
        @endfor
    </div>
</div>
