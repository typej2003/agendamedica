{{-- Alta y edición del consultorio del médico del contexto. La sede viene elegida si se abrió desde su tarjeta, y
     «Nueva sede» la crea sin salir del panel (`_dialogo-sede-rapida`). `reg_medico` y el médico salen del contexto en
     `ConsultorioController`, nunca de acá. --}}
<x-ui.dialogo id="dialogo-consultorio" :titulo="$vm->tituloConsultorio()" lateral data-modo="{{ $vm->editandoConsultorio() ? 'editar' : 'nuevo' }}">
    <form method="POST" action="{{ $vm->urlGuardarConsultorio() }}">
        @csrf
        <input type="hidden" name="formulario" value="consultorio">
        <input type="hidden" name="id" value="{{ $vm->idConsultorio() }}">
        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">

        <div class="dialogo-cuerpo">
            <div class="row g-3">
                <x-ui.campo etiqueta="Consultorio" for="consultorio-sede" error="medical_center_id" requerido class="col-12">
                    <div class="input-group">
                        <select name="medical_center_id" id="consultorio-sede" class="form-select" required>
                            <option value="">— Elige el consultorio —</option>
                            @foreach ($vm->opcionesDeSede() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-outline-secondary" data-abrir-sede-rapida>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva sede
                        </button>
                    </div>
                    <span class="form-text d-block" role="status" data-aviso-sede></span>
                </x-ui.campo>

                <x-ui.campo etiqueta="Número o sala" for="consultorio-numero" error="office_number" requerido class="col-12">
                    <input type="text" name="office_number" id="consultorio-numero" class="form-control" maxlength="50" autofocus
                           placeholder="101-A, Piso 2…" value="{{ $vm->valorConsultorio('office_number') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Cómo se atiende" for="consultorio-modalidad" error="modalidad" requerido class="col-12">
                    <select name="modalidad" id="consultorio-modalidad" class="form-select" required>
                        @foreach ($vm->opcionesDeModalidad() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <x-ui.campo etiqueta="Duración de la cita" for="consultorio-duracion" error="duracion_cita" class="col-12"
                            ayuda="El tope de pacientes lo pone el cupo de cada horario.">
                    <div class="input-group">
                        <input type="number" name="duracion_cita" id="consultorio-duracion" class="form-control" min="5" max="480" step="5"
                               placeholder="{{ $vm->duracionPorDefecto() }}" value="{{ $vm->valorConsultorio('duracion_cita') }}">
                        <span class="input-group-text">min</span>
                    </div>
                </x-ui.campo>

                <x-ui.campo etiqueta="Teléfono" for="consultorio-telefono" error="phone" class="col-12">
                    <input type="text" name="phone" id="consultorio-telefono" class="form-control" maxlength="50"
                           value="{{ $vm->valorConsultorio('phone') }}">
                </x-ui.campo>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="activo" id="consultorio-activo" value="1" {{ $vm->consultorioSeSigueUsando() ? 'checked' : '' }}>
                        <label class="form-check-label" for="consultorio-activo">Se sigue usando</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="dialogo-pie">
            @if ($vm->editandoConsultorio())
                <a class="btn btn-outline-secondary" href="{{ $vm->url() }}">Cancelar</a>
            @else
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            @endif
            <button class="btn btn-primary">{{ $vm->botonConsultorio() }}</button>
        </div>
    </form>
</x-ui.dialogo>
