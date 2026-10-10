@extends('clinica.layout')

@php
    use App\Models\Office;

    $valor = fn (string $campo, $defecto = null) => old($campo, $defecto);
    $volver = route('clinica.inicio');
    $sinSedes = $sedes->isEmpty();

    // Un consultorio se identifica por su sede y su número; el nombre del lugar sale de la otra tabla.
    $etiqueta = fn (Office $o) => ($o->medicalCenter->name ?? 'Sede')
        . ($o->office_number ? ' · ' . $o->office_number : '');
@endphp

@section('titulo', 'Consultorios')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Consultorios</h1>
            <p class="page-head-sub">
                Dónde atiende
                {{ trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname) }}.
                La modalidad y la duración son lo que la agenda usa para armar la jornada.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-sm btn-outline-secondary {{ $verInactivos ? '' : 'active' }}"
               href="{{ route('clinica.consultorios') }}">Activos</a>
            <a class="btn btn-sm btn-outline-secondary {{ $verInactivos ? 'active' : '' }}"
               href="{{ route('clinica.consultorios', ['inactivos' => 1]) }}">Todos</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}
        </div>
    @endif

    @if ($sinSedes)
        {{-- Sin sedes no hay dónde colgar un consultorio: se avisa con el camino, no un formulario
             que no se puede completar. --}}
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-hospital"></i>
            <div>
                Todavía no hay <strong>sedes</strong> cargadas, así que no se puede crear un consultorio.
                <a href="{{ route('clinica.sedes') }}">Cargá primero la sede</a> donde atendés.
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('clinica.consultorios.guardar') }}" class="card mb-3">
            @csrf
            <input type="hidden" name="id" value="{{ $valor('id', $editando?->id) }}">
            <input type="hidden" name="ver_inactivos" value="{{ $verInactivos ? 1 : 0 }}">

            <div class="card-body">
                <h2 class="h6 mb-2">{{ $editando ? 'Editar el consultorio' : 'Nuevo consultorio' }}</h2>

                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small mb-0" for="medical_center_id">Sede *</label>
                        <select name="medical_center_id" id="medical_center_id" class="form-select form-select-sm" required>
                            <option value="">— Elegí la sede —</option>
                            @foreach ($sedes as $sede)
                                <option value="{{ $sede->id }}"
                                        {{ (int) $valor('medical_center_id', $editando?->medical_center_id) === $sede->id ? 'selected' : '' }}>
                                    {{ $sede->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="office_number">Consultorio *</label>
                        <input type="text" name="office_number" id="office_number" class="form-control form-control-sm"
                               maxlength="50" placeholder="101-A, Piso 2, Consultorio 3…"
                               value="{{ $valor('office_number', $editando?->office_number) }}">
                    </div>

                    <div class="col-md-5">
                        <label class="form-label small mb-0" for="modalidad">Cómo se atiende *</label>
                        <select name="modalidad" id="modalidad" class="form-select form-select-sm" required>
                            @foreach ($modalidades as $clave => $etiquetaModalidad)
                                <option value="{{ $clave }}"
                                        {{ $valor('modalidad', $editando?->modalidad ?? Office::MODALIDAD_ORDEN) === $clave ? 'selected' : '' }}>
                                    {{ $etiquetaModalidad }}
                                </option>
                            @endforeach
                        </select>
                        <span class="form-text small">
                            <strong>Orden de llegada</strong>: la secretaria arma la cola.
                            <strong>Hora de cita</strong>: cada paciente tiene su hora.
                        </span>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="duracion_cita">Duración de la cita</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="duracion_cita" id="duracion_cita" class="form-control"
                                   min="5" max="480" step="5"
                                   placeholder="{{ $duracionPorDefecto }}"
                                   value="{{ $valor('duracion_cita', $editando?->duracion_cita) }}">
                            <span class="input-group-text">min</span>
                        </div>
                        <span class="form-text small">Vacío = sin duración configurada.</span>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small mb-0" for="phone">Teléfono del consultorio</label>
                        <input type="text" name="phone" id="phone" class="form-control form-control-sm" maxlength="50"
                               value="{{ $valor('phone', $editando?->phone) }}">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activo" value="1"
                                   {{ $valor('activo', $editando?->activo ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="activo">Se sigue usando</label>
                        </div>
                    </div>

                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button class="btn btn-sm btn-primary flex-grow-1">
                            {{ $editando ? 'Guardar cambios' : 'Registrar el consultorio' }}
                        </button>
                        @if ($editando)
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('clinica.consultorios', $verInactivos ? ['inactivos' => 1] : []) }}">Cancelar</a>
                        @endif
                    </div>
                </div>
            </div>
        </form>
    @endif

    <div class="card">
        @if ($consultorios->isEmpty())
            <div class="empty-state">
                <i class="bi bi-door-closed"></i>
                <p>No hay consultorios {{ $verInactivos ? '' : 'activos' }}.</p>
                <p class="small">
                    Sin un consultorio cargado no se puede agendar: la agenda necesita saber la sede,
                    la modalidad y el horario.
                </p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col">Sede</th>
                            <th scope="col">Consultorio</th>
                            <th scope="col">Cómo se atiende</th>
                            <th scope="col" class="text-center">Duración</th>
                            <th scope="col" class="text-center">Horarios</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($consultorios as $consultorio)
                            <tr>
                                <td><strong>{{ $consultorio->medicalCenter->name ?? 'Sede' }}</strong></td>
                                <td>{{ $consultorio->office_number }}</td>
                                <td>
                                    <span class="badge {{ $consultorio->modalidad === Office::MODALIDAD_ORDEN ? 'bg-info text-dark' : 'bg-secondary' }}">
                                        {{ $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    {{ $consultorio->duracion_cita ? $consultorio->duracion_cita . ' min' : '—' }}
                                </td>
                                <td class="text-center">
                                    @if ($consultorio->schedules->isEmpty())
                                        {{-- Los bloques son WEB-2.8b.3: decirlo es más útil que un cero. --}}
                                        <span class="text-muted small">sin cargar</span>
                                    @else
                                        <span class="badge bg-secondary">{{ $consultorio->schedules->count() }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($consultorio->activo)
                                        <span class="badge bg-success">Activo</span>
                                    @else
                                        <span class="badge bg-secondary">Inactivo</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('clinica.consultorios', array_filter(['editar' => $consultorio->id, 'inactivos' => $verInactivos ? 1 : null])) }}">
                                        <i class="bi bi-pencil"></i> Editar
                                    </a>
                                    <form method="POST" action="{{ route('clinica.consultorios.activar', $consultorio->id) }}"
                                          class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="ver_inactivos" value="{{ $verInactivos ? 1 : 0 }}">
                                        <button class="btn btn-sm {{ $consultorio->activo ? 'btn-outline-danger' : 'btn-outline-primary' }}">
                                            @if ($consultorio->activo)
                                                <i class="bi bi-slash-circle"></i> Desactivar
                                            @else
                                                <i class="bi bi-check-circle"></i> Activar
                                            @endif
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
