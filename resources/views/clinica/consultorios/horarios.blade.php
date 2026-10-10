@extends('clinica.layout')

@php
    use App\Models\Office;

    $valor = fn (string $campo, $defecto = null) => old($campo, $defecto);
    $etiqueta = ($consultorio->medicalCenter->name ?? 'Sede')
        . ($consultorio->office_number ? ' · ' . $consultorio->office_number : '');
    $modalidad = Office::modalidades()[$consultorio->modalidad] ?? $consultorio->modalidad;
    $volver = route('clinica.consultorios');
@endphp

@section('titulo', 'Horarios del consultorio')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Horarios</h1>
            <p class="page-head-sub">
                {{ $etiqueta }} — {{ $modalidad }}@if ($consultorio->duracion_cita) · {{ $consultorio->duracion_cita }} min por cita @endif.
                Cada bloque es una <strong>jornada</strong>: la agenda agrupa las citas por fecha, sede y bloque,
                y el cupo se mide contra el bloque, no contra el día.
            </p>
        </div>

        <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">
            <i class="bi bi-arrow-left"></i> Consultorios
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('clinica.consultorios.horarios.guardar', $consultorio) }}" class="card mb-3">
        @csrf
        <input type="hidden" name="id" value="{{ $valor('id', $editando?->id) }}">

        <div class="card-body">
            <h2 class="h6 mb-2">{{ $editando ? 'Editar el bloque' : 'Nuevo bloque de horario' }}</h2>

            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-0" for="dia_semana">Día *</label>
                    <select name="dia_semana" id="dia_semana" class="form-select form-select-sm" required>
                        <option value="">— Elegí el día —</option>
                        @foreach ($dias as $numero => $nombre)
                            <option value="{{ $numero }}"
                                    {{ (int) $valor('dia_semana', $editando?->dia_semana) === $numero ? 'selected' : '' }}>
                                {{ $nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="hora_inicio">Desde *</label>
                    <input type="time" name="hora_inicio" id="hora_inicio" class="form-control form-control-sm"
                           required value="{{ $valor('hora_inicio', $editando ? substr($editando->hora_inicio, 0, 5) : null) }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="hora_fin">Hasta *</label>
                    <input type="time" name="hora_fin" id="hora_fin" class="form-control form-control-sm"
                           required value="{{ $valor('hora_fin', $editando ? substr($editando->hora_fin, 0, 5) : null) }}">
                    <span class="form-text small">La hora de fin no cuenta como cita.</span>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-0" for="cupo">Cupo</label>
                    <input type="number" name="cupo" id="cupo" class="form-control form-control-sm"
                           min="1" max="65535" placeholder="sin tope"
                           value="{{ $valor('cupo', $editando?->cupo) }}">
                    <span class="form-text small">Vacío = sin tope.</span>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-sm btn-primary flex-grow-1">
                        {{ $editando ? 'Guardar cambios' : 'Agregar el bloque' }}
                    </button>
                    @if ($editando)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('clinica.consultorios.horarios', $consultorio) }}">Cancelar</a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        @if ($bloques->isEmpty())
            <div class="empty-state">
                <i class="bi bi-clock-history"></i>
                <p>Este consultorio todavía no tiene horarios cargados.</p>
                <p class="small">
                    La agenda funciona igual, pero sin bloques no hay tope de pacientes y, por orden de
                    llegada, la cita nace a la hora de apertura por defecto cuando nadie elige hora.
                    Cargá los días y las horas en que atendés acá.
                </p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col">Día</th>
                            <th scope="col">Horario</th>
                            <th scope="col" class="text-center">Cupo</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bloques as $bloque)
                            <tr>
                                <td><strong>{{ $bloque->nombreDelDia() }}</strong></td>
                                <td>{{ $bloque->rango() }}</td>
                                <td class="text-center">
                                    @if ($bloque->cupo)
                                        {{ $bloque->cupo }} pacientes
                                    @else
                                        <span class="text-muted small">sin tope</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('clinica.consultorios.horarios', [$consultorio, 'editar' => $bloque->id]) }}">
                                        <i class="bi bi-pencil"></i> Editar
                                    </a>
                                    <form method="POST"
                                          action="{{ route('clinica.consultorios.horarios.eliminar', [$consultorio, $bloque]) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('¿Quitar el bloque del {{ $bloque->nombreDelDia() }} de {{ $bloque->rango() }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> Quitar
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
