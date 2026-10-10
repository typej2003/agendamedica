{{-- Recordatorio de una cita (WEB-2.6): mismo diálogo para todas las filas, que lo llena con sus datos
     (`public/js/clinica/agenda.js`). Los tres canales se muestran siempre; el que el paciente no puede
     recibir queda deshabilitado y el aviso dice cuál dato falta (regla de la wiki). Recibe `$canales`. --}}
<x-ui.dialogo id="dialogo-recordatorio" titulo="Recordar al paciente">
    <form method="POST" id="mensaje-form">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-3">Paciente: <strong id="mensaje-paciente"></strong></p>

            <div class="mb-3">
                <span class="form-label d-block">Por dónde</span>
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($canales as $clave => $etiqueta)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="canal" id="canal-{{ $clave }}"
                                   value="{{ $clave }}" data-canal="{{ $clave }}">
                            <label class="form-check-label" for="canal-{{ $clave }}">{{ $etiqueta }}</label>
                        </div>
                    @endforeach
                </div>
                <p class="form-text mb-0" id="mensaje-falta"></p>
            </div>

            <div>
                <label class="form-label" for="mensaje-texto">Mensaje</label>
                <textarea name="mensaje" id="mensaje-texto" rows="4" maxlength="500" class="form-control"></textarea>
                <p class="form-text mb-0" id="mensaje-contador"></p>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary" id="mensaje-enviar">Enviar</button>
        </div>
    </form>
</x-ui.dialogo>
