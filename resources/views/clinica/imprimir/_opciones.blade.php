{{-- Las opciones de la hoja del récipe (WEB-5.2): qué se imprime y con qué nombre sale el medicamento. Es el
     contenido del diálogo "Imprimir récipe" y lo comparten la sección de récipes de la consulta, la lista de
     récipes de una cita y la vista previa. Al confirmar, guarda la elección en el formato del médico (la ven él y
     su asistente) y sigue a la vista previa del récipe que se eligió. Recibe `$dialogo` (OpcionesDeImpresionVista). --}}
<form method="POST" action="{{ $dialogo->urlGuardar() }}" class="opciones-impresion">
    @csrf
    {{-- A qué vista previa se sigue: lo llena el botón que abre el diálogo (hay un diálogo para toda la página). --}}
    <input type="hidden" name="siguiente" value="{{ $dialogo->siguiente() }}" data-siguiente>

    <div class="dialogo-cuerpo">
        <fieldset class="opciones-grupo">
            <legend class="etiqueta">Qué imprimir</legend>
            @foreach ($dialogo->modos() as $opcion)
                <label class="opcion-de-impresion">
                    <input type="radio" name="modo" value="{{ $opcion['valor'] }}" @if ($opcion['marcada']) checked @endif>
                    <span class="opcion-de-impresion-texto">
                        <strong>{{ $opcion['texto'] }}</strong>
                        <small>{{ $opcion['ayuda'] }}</small>
                    </span>
                </label>
            @endforeach
        </fieldset>

        <fieldset class="opciones-grupo">
            <legend class="etiqueta">Nombre del medicamento</legend>
            @foreach ($dialogo->nombres() as $opcion)
                <label class="opcion-de-impresion">
                    <input type="radio" name="nombre" value="{{ $opcion['valor'] }}" @if ($opcion['marcada']) checked @endif>
                    <span class="opcion-de-impresion-texto">
                        <strong>{{ $opcion['texto'] }}</strong>
                        <small>{{ $opcion['ayuda'] }}</small>
                    </span>
                </label>
            @endforeach
        </fieldset>

        <p class="opciones-nota">Lo que elijas queda guardado para este médico: tú y su asistente imprimen igual.</p>
    </div>

    <div class="dialogo-pie">
        <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
        <button class="btn btn-primary">Ver la hoja</button>
    </div>
</form>
