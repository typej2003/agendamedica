{{--
    El cambio de cédula de la pantalla "Citas del paciente" (WEB-2.15): "Cédula actual → modificada" de
    `w_listado_pacientes_a_citas.srw:255-294`. Un solo diálogo para toda la página; lo abre el botón
    "Cambiar la cédula" del encabezado (`public/js/clinica/citas-paciente.js`) o, si la validación falló,
    la propia página al cargar —para que el mensaje no quede detrás de una ventana cerrada—.

    La cédula actual se muestra como dato, no como campo editable: la fuente también la mostraba aparte
    (`st_15`) y comparaba contra ella. Recibe `$vm` (CitasDelPacienteVista).
--}}
<x-ui.dialogo id="dialogo-cedula" titulo="Cambiar la cédula del paciente"
              data-abrir-al-cargar="{{ $errors->has('cedula') ? '1' : '0' }}">
    <form method="POST" action="{{ $vm->urlCambiarCedula() }}" id="cedula-form">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-3">Paciente: <strong>{{ $vm->nombre() }}</strong></p>

            <x-ui.campo etiqueta="Cédula actual" for="cedula-actual">
                <input type="text" id="cedula-actual" class="form-control" value="{{ $vm->cedula() ?? '—' }}" disabled>
            </x-ui.campo>

            <x-ui.campo etiqueta="Cédula nueva" for="cedula" requerido
                        ayuda="Solo números, igual que en el escritorio. La cédula identifica al paciente en todos los consultorios.">
                <input type="text" name="cedula" id="cedula" class="form-control" inputmode="numeric"
                       pattern="[0-9]{1,10}" maxlength="10" value="{{ old('cedula') }}"
                       @if ($errors->has('cedula')) aria-invalid="true" @endif required>
            </x-ui.campo>

            <p class="form-text mb-0">
                El cambio no toca la historia clínica ni las citas: es la misma persona con otra cédula.
            </p>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Cambiar la cédula</button>
        </div>
    </form>
</x-ui.dialogo>
