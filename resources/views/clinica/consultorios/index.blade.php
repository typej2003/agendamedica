@extends('clinica.layout')

@section('titulo', 'Consultorios')

@section('contenido')
    <x-ui.page-head titulo="Consultorios" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Volver">
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-slot name="filtros">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué consultorios ver" />
        </x-slot>
    </x-ui.barra-herramientas>

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

                    <x-ui.campo etiqueta="Duración de la cita" for="duracion_cita" class="col-md-3" :ayuda="$vm->ayudaDuracion()">
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

    <x-ui.tarjeta sin-relleno data-tour="consultorios-lista">
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-door-closed" :titulo="$vm->textoVacio()">
                Sin un consultorio cargado no se puede agendar: la agenda necesita saber la sede, la modalidad y el
                horario. Y sin horarios cargados (sus bloques de día y hora) la jornada no tiene tope de pacientes.
            </x-ui.vacio>
        @else
            {{-- Los **bloques de horario** de cada consultorio se ven y se editan acá mismo (A-10): la duración
                 de la cita y los horarios estaban cada uno en su pantalla y el médico no los encontraba juntos.
                 Cada fila los lista, deja quitar uno y agrega otro con las mismas rutas de siempre
                 (`clinica.consultorios.horarios.guardar` / `…eliminar`), así que las reglas de solape y el cupo
                 opcional son las de `GuardarBloqueHorario` y no se repiten acá. --}}
            <x-ui.tabla class="consultorios-tabla">
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
                        <td data-etiqueta="Horarios" class="celda-bloques" data-tour="consultorios-bloques">
                            @if ($fila['bloques'] === [])
                                <p class="texto-tenue small mb-2">
                                    Todavía no tiene bloques: la agenda no le pone tope de pacientes ni hora de apertura.
                                </p>
                            @else
                                <ul class="bloques-lista">
                                    @foreach ($fila['bloques'] as $bloque)
                                        <li class="bloques-item">
                                            <span class="bloques-item-texto">
                                                <strong>{{ $bloque['dia'] }}</strong>
                                                <span class="tnum">{{ $bloque['horario'] }}</span>
                                                <span class="texto-tenue small">· {{ $bloque['cupo'] ?? 'sin tope' }}</span>
                                            </span>
                                            <form method="POST" action="{{ $bloque['urlEliminar'] }}" data-confirmar="{{ $bloque['confirmar'] }}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="volver" value="consultorios">
                                                <input type="hidden" name="ver_inactivos" value="{{ $vm->verInactivos() }}">
                                                <button class="btn btn-sm btn-outline-danger"
                                                        title="Quitar el bloque del {{ $bloque['dia'] }} de {{ $bloque['horario'] }}"
                                                        aria-label="Quitar el bloque del {{ $bloque['dia'] }} de {{ $bloque['horario'] }}">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- El alta de un bloque, sin salir de la pantalla. El `consultorio` oculto es lo que
                                 permite reponer lo escrito en **este** formulario si la validación falla (hay uno
                                 por consultorio y todos mandan los mismos nombres). --}}
                            <form method="POST" action="{{ $fila['urlGuardarBloque'] }}" class="bloques-alta" data-tour="consultorios-alta">
                                @csrf
                                <input type="hidden" name="volver" value="consultorios">
                                <input type="hidden" name="ver_inactivos" value="{{ $vm->verInactivos() }}">
                                <input type="hidden" name="consultorio" value="{{ $fila['id'] }}">

                                <x-ui.dias-semana nombre="dias" :elegidos="$fila['diasElegidos']" :prefijo="'bloque-dia-' . $fila['id']"
                                                  class="dias-semana-compactos" />

                                <label class="visually-hidden" for="bloque-desde-{{ $fila['id'] }}">Desde qué hora</label>
                                <input type="time" name="hora_inicio" id="bloque-desde-{{ $fila['id'] }}" class="form-control form-control-sm"
                                       value="{{ $fila['alta']['hora_inicio'] }}" required aria-label="Desde qué hora">

                                <label class="visually-hidden" for="bloque-hasta-{{ $fila['id'] }}">Hasta qué hora</label>
                                <input type="time" name="hora_fin" id="bloque-hasta-{{ $fila['id'] }}" class="form-control form-control-sm"
                                       value="{{ $fila['alta']['hora_fin'] }}" required aria-label="Hasta qué hora">

                                <label class="visually-hidden" for="bloque-cupo-{{ $fila['id'] }}">Cupo del bloque (vacío = sin tope)</label>
                                <input type="number" name="cupo" id="bloque-cupo-{{ $fila['id'] }}" class="form-control form-control-sm"
                                       min="1" max="65535" placeholder="Cupo" value="{{ $fila['alta']['cupo'] }}"
                                       aria-label="Cupo del bloque, vacío = sin tope">

                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar
                                </button>
                            </form>
                            <p class="form-text mb-0">
                                El cupo es opcional (vacío = sin tope). Para cargar muchos bloques, entra a
                                <a href="{{ $fila['urlHorarios'] }}">la pantalla de horarios de este consultorio</a>.
                            </p>
                        </td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$fila['estado']['tono']" :icono="$fila['estado']['icono']">{{ $fila['estado']['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Tres acciones: van en el menú «…» (pauta de Alexander), ordenadas por uso —los horarios y la
                                 edición primero— y con **desactivar al final, en rojo**. El `<form>` del interruptor envía el
                                 `PATCH` de siempre. --}}
                            <x-ui.acciones-fila etiqueta="Acciones del consultorio {{ $fila['consultorio'] }}">
                                <x-ui.menu etiqueta="Acciones del consultorio {{ $fila['consultorio'] }}">
                                    <a class="menu-item" href="{{ $fila['urlHorarios'] }}">
                                        <i class="bi bi-clock" aria-hidden="true"></i> Horarios del consultorio
                                    </a>
                                    <a class="menu-item" href="{{ $fila['urlEditar'] }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i> Editar el consultorio
                                    </a>
                                    <form method="POST" action="{{ $fila['urlActivar'] }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="ver_inactivos" value="{{ $vm->verInactivos() }}">
                                        <button class="menu-item {{ $fila['activar']['peligro'] ? 'menu-item-peligro' : '' }}">
                                            <i class="bi {{ $fila['activar']['icono'] }}" aria-hidden="true"></i> {{ $fila['activar']['texto'] }}
                                        </button>
                                    </form>
                                </x-ui.menu>
                            </x-ui.acciones-fila>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>
@endsection
