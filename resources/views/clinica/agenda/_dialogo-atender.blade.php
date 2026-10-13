{{-- Un solo diálogo de "Atender" para toda la página (WEB-4.1): el botón de cada cita lo llena con sus datos
     (`public/js/clinica/shell.js`, `data-atender`).

     El formulario por defecto —lo que pasa si el JS no corre— es **re-atender**: abrir la consulta del día,
     la que ya existe si el paciente tiene una hoy (la pregunta del legado, wiki `04-historia-clinica.md`).
     El segundo botón manda `nueva=1`, que numera la siguiente consulta de la historia aunque ya haya una
     hoy. Los dos POST van a la misma ruta: lo único que cambia es qué consulta se abre. --}}
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
            <button class="btn btn-outline-primary" name="nueva" value="1">Consulta nueva</button>
            <button class="btn btn-primary" id="atender-abrir">Abrir la consulta</button>
        </div>
    </form>
</x-ui.dialogo>
