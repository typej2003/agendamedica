{{-- Un solo diálogo de "Atender" para toda la página (WEB-4.1): el botón de cada cita lo llena con sus datos
     (`public/js/clinica/shell.js`, `data-atender`).

     El formulario por defecto —lo que pasa si el JS no corre— es **re-atender**: abrir la consulta del día,
     la que ya existe si el paciente tiene una hoy (la pregunta del legado, wiki `04-historia-clinica.md`), y
     marcar la cita atendida. Ese es el caso normal y tiene **sólo dos botones**: Cancelar y Atender.

     «Consulta nueva» (`nueva=1`) numera la siguiente consulta de la historia aunque ya haya una hoy, y
     **sólo tiene sentido cuando la cita ya está atendida** (el botón de la agenda dice "Editar consulta"):
     ahí el médico ya no tiene nada que marcar y lo único que puede querer es la consulta siguiente. Por eso
     el botón existe siempre en el HTML pero **oculto**, y `shell.js` lo muestra únicamente con
     `data-editar="1"` (revisión de Alexander, 2026-10-17). Sin JavaScript quedan los dos botones del caso
     normal, que es lo correcto. Los dos POST van a la misma ruta: lo único que cambia es qué consulta se abre. --}}
<x-ui.dialogo id="dialogo-atender" titulo="Abrir la consulta">
    <form method="POST" id="atender-form">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-2">Paciente: <strong id="atender-paciente"></strong></p>

            <p class="mb-2" id="atender-aviso" hidden></p>

            <p class="form-text mb-0">
                Se abre la consulta del día. Si la cita todavía no estaba atendida, queda atendida y
                confirmada, y eso no se puede deshacer.
            </p>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            {{-- Oculto por defecto: sin JS el formulario no lo manda (hidden). `shell.js` lo muestra con la
                 cita ya atendida. --}}
            <button class="btn btn-outline-primary" name="nueva" value="1" id="atender-nueva" hidden>
                Consulta nueva
            </button>
            <button class="btn btn-primary" id="atender-abrir">Atender y abrir la consulta</button>
        </div>
    </form>
</x-ui.dialogo>
