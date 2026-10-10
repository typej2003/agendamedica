@extends('clinica.layout')

@section('titulo', 'Consultorios')

@section('contenido')
    <x-ui.page-head titulo="Consultorios" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Volver">
        <x-slot name="acciones">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué consultorios ver" />
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    @if ($vm->sinSedes())
        {{-- Sin sedes no hay dónde colgar un consultorio: se avisa con el camino, no un formulario que no se
             puede completar. --}}
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-hospital" aria-hidden="true"></i>
            <div>
                Todavía no hay <strong>sedes</strong> cargadas, así que no se puede crear un consultorio.
                <a href="{{ $vm->urlSedes() }}">Carga primero la sede</a> donde atiendes.
            </div>
        </div>
    @else
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            <input type="hidden" name="ver_inactivos" value="{{ $vm->verInactivos() }}">

            <x-ui.tarjeta :titulo="$vm->tituloDelFormulario()" icono="bi-door-closed" class="mb-3">
                <div class="row g-3">
                    <x-ui.campo etiqueta="Sede" for="medical_center_id" requerido class="col-md-4">
                        <select name="medical_center_id" id="medical_center_id" class="form-select" required>
                            <option value="">— Elige la sede —</option>
                            @foreach ($vm->opcionesDeSede() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Consultorio" for="office_number" requerido class="col-md-3">
                        <input type="text" name="office_number" id="office_number" class="form-control" maxlength="50"
                               placeholder="101-A, Piso 2, Consultorio 3…" value="{{ $vm->valor('office_number') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Cómo se atiende" for="modalidad" requerido class="col-md-5"
                                ayuda="Orden de llegada: la secretaria arma la cola. Hora de cita: cada paciente tiene su hora.">
                        <select name="modalidad" id="modalidad" class="form-select" required>
                            @foreach ($vm->opcionesDeModalidad() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Duración de la cita" for="duracion_cita" class="col-md-3" ayuda="Vacío = sin duración configurada.">
                        <div class="input-group">
                            <input type="number" name="duracion_cita" id="duracion_cita" class="form-control" min="5" max="480" step="5"
                                   placeholder="{{ $vm->duracionPorDefecto() }}" value="{{ $vm->valor('duracion_cita') }}">
                            <span class="input-group-text">min</span>
                        </div>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Teléfono del consultorio" for="phone" class="col-md-4">
                        <input type="text" name="phone" id="phone" class="form-control" maxlength="50" value="{{ $vm->valor('phone') }}">
                    </x-ui.campo>

                    <div class="col-md-3 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activo" value="1" {{ $vm->seSigueUsando() ? 'checked' : '' }}>
                            <label class="form-check-label" for="activo">Se sigue usando</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    @if ($vm->editando())
                        <a class="btn btn-outline-secondary" href="{{ $vm->urlCancelar() }}">Cancelar</a>
                    @endif
                    <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
                </div>
            </x-ui.tarjeta>
        </form>
    @endif

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-door-closed" :titulo="$vm->textoVacio()">
                Sin un consultorio cargado no se puede agendar: la agenda necesita saber la sede, la modalidad y el
                horario. Y sin horarios cargados (sus bloques de día y hora) la jornada no tiene tope de pacientes.
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Consultorio</th>
                    <th>Cómo se atiende</th>
                    <th>Duración</th>
                    <th>Horarios</th>
                    <th>Estado</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <strong>{{ $fila['sede'] }}</strong>
                            <span class="texto-tenue d-block small">{{ $fila['consultorio'] }}</span>
                        </td>
                        <td data-etiqueta="Cómo se atiende">
                            <x-ui.estado :tono="$fila['modalidad']['tono']" :icono="$fila['modalidad']['icono']">{{ $fila['modalidad']['texto'] }}</x-ui.estado>
                        </td>
                        <td data-etiqueta="Duración">{{ $fila['duracion'] ?? '—' }}</td>
                        <td data-etiqueta="Horarios">
                            {{-- Los bloques se cargan en su propia pantalla (WEB-2.8b.3): sin bloques el
                                 atajo invita a cargarlos. --}}
                            <a class="small" href="{{ $fila['urlHorarios'] }}">{{ $fila['bloques'] ?? 'cargar horarios' }}</a>
                        </td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$fila['estado']['tono']" :icono="$fila['estado']['icono']">{{ $fila['estado']['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlHorarios'] }}">
                                    <i class="bi bi-clock" aria-hidden="true"></i> Horarios
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <x-ui.menu>
                                    <form method="POST" action="{{ $fila['urlActivar'] }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="ver_inactivos" value="{{ $vm->verInactivos() }}">
                                        <button class="menu-item {{ $fila['activar']['peligro'] ? 'menu-item-peligro' : '' }}">
                                            <i class="bi {{ $fila['activar']['icono'] }}" aria-hidden="true"></i> {{ $fila['activar']['texto'] }}
                                        </button>
                                    </form>
                                </x-ui.menu>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>
@endsection
