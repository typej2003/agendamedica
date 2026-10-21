{{-- El alta de un bloque de horario de un consultorio, un solo panel para toda la pantalla: el botón «Horario» de cada
     consultorio le pone la ruta, el consultorio y el título (`sedes.js`). Si el POST volvió con errores, llega ya armado
     para ese consultorio y con lo escrito. Las reglas (días, solapes, cupo) son las de `GuardarBloqueHorario`. --}}
@php $horario = $vm->horario(); @endphp
<x-ui.dialogo id="dialogo-horario" :titulo="$horario['titulo']" lateral>
    <form method="POST" action="{{ $horario['accion'] }}">
        @csrf
        <input type="hidden" name="formulario" value="horario">
        <input type="hidden" name="volver" value="sedes">
        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
        <input type="hidden" name="consultorio" value="{{ $horario['consultorio'] }}">

        <div class="dialogo-cuerpo">
            <div class="row g-3">
                {{-- Los días son un grupo de botones (`<x-ui.dias-semana>`, con su `aria-label`): la etiqueta no es un
                     `<label for>` porque no hay un solo control al que apuntar. --}}
                <div class="campo col-12">
                    <span class="form-label d-block">Días <span aria-hidden="true">*</span></span>
                    <x-ui.dias-semana nombre="dias" :elegidos="$horario['dias']" prefijo="horario-dia" />
                    @error('dias')
                        <span class="campo-error d-block">{{ $message }}</span>
                    @enderror
                </div>

                <x-ui.campo etiqueta="Desde" for="horario-desde" error="hora_inicio" requerido class="col-6">
                    <x-ui.hora name="hora_inicio" id="horario-desde" :valor="$horario['hora_inicio']" required />
                </x-ui.campo>

                <x-ui.campo etiqueta="Hasta" for="horario-hasta" error="hora_fin" requerido class="col-6">
                    <x-ui.hora name="hora_fin" id="horario-hasta" :valor="$horario['hora_fin']" required />
                </x-ui.campo>

                <x-ui.campo etiqueta="Cupo" for="horario-cupo" error="cupo" class="col-6" ayuda="Vacío = sin tope.">
                    <input type="number" name="cupo" id="horario-cupo" class="form-control" min="1" max="65535"
                           value="{{ $horario['cupo'] }}">
                </x-ui.campo>
            </div>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Agregar el horario</button>
        </div>
    </form>
</x-ui.dialogo>
