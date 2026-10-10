{{-- Diálogo del saludo de cumpleaños (WEB-2.9): uno solo para toda la página, que el botón de cada fila
     llena con sus datos (`public/js/clinica/cumpleanos.js`). El mensaje viene **ya armado** con la
     plantilla del médico y los datos del paciente (`CumpleanosVista::mensajeDe`), así que el usuario ve
     exactamente lo que sale y puede corregirlo antes de mandarlo.

     El canal viaja como `automatico`: la pantalla usa el canal **configurado** (el mismo que va a usar el
     envío automático), y resolverlo en el servidor deja una sola versión de esa decisión. --}}
<x-ui.dialogo id="dialogo-cumple" titulo="Saludar por su cumpleaños">
    <form method="POST" action="{{ route('clinica.cumpleanos.enviar') }}">
        @csrf
        <input type="hidden" name="canal" value="automatico">
        <input type="hidden" name="paciente" id="cumple-paciente-id">

        <div class="dialogo-cuerpo">
            <p class="mb-3">Paciente: <strong id="cumple-paciente"></strong></p>

            <div id="cumple-aviso" class="alert alert-warning py-2 mb-3" hidden></div>

            <div>
                <label class="form-label" for="cumple-texto">Mensaje</label>
                <textarea name="mensaje" id="cumple-texto" rows="4" maxlength="500" class="form-control"></textarea>
                <p class="form-text mb-0" id="cumple-contador"></p>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary" id="cumple-enviar">
                <i class="bi bi-send" aria-hidden="true"></i> Enviar
            </button>
        </div>
    </form>
</x-ui.dialogo>
