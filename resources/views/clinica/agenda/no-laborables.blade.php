@extends('clinica.layout')

@php
    use App\Models\DiaNoLaborable;
    use App\Support\FechaClinica;

    $tituloMes = FechaClinica::tituloMes($mes);
    $anterior = $mes->copy()->subMonth();
    $siguiente = $mes->copy()->addMonth();
    $volver = route('clinica.agenda', ['vista' => 'dia', 'fecha' => now()->toDateString()]);

    // El formulario sirve para las dos cosas: sin `editando` da de alta; con él, edita ese día.
    $valor = fn (string $campo, $defecto = null) => old($campo, $defecto);
    $accion = route('clinica.agenda.no-laborables.guardar');
@endphp

@section('titulo', 'Días no laborables')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>Días no laborables</h1>
            <p class="page-head-sub">
                Feriados, congresos y ausencias de
                {{ trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname) }}.
                La agenda los avisa al agendar.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-sm btn-outline-secondary" href="{{ $volver }}">
                <i class="bi bi-arrow-left"></i> Volver a la agenda
            </a>
            <a class="btn btn-sm btn-primary" href="{{ route('clinica.agenda.no-laborables', ['mes' => $mes->format('Y-m')]) }}">
                <i class="bi bi-plus-lg"></i> Marcar un día
            </a>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mb-3">
        <a class="btn btn-sm btn-outline-secondary" title="Mes anterior" aria-label="Mes anterior"
           href="{{ route('clinica.agenda.no-laborables', ['mes' => $anterior->format('Y-m')]) }}">
            <i class="bi bi-chevron-left"></i>
        </a>
        <a class="btn btn-sm btn-outline-secondary"
           href="{{ route('clinica.agenda.no-laborables', ['mes' => now()->format('Y-m')]) }}">Este mes</a>
        <a class="btn btn-sm btn-outline-secondary" title="Mes siguiente" aria-label="Mes siguiente"
           href="{{ route('clinica.agenda.no-laborables', ['mes' => $siguiente->format('Y-m')]) }}">
            <i class="bi bi-chevron-right"></i>
        </a>
        <strong class="ms-2">{{ ucfirst($tituloMes) }}</strong>
        <span class="text-muted small">
            · {{ $dias->count() }} {{ $dias->count() === 1 ? 'día marcado' : 'días marcados' }}
        </span>
    </div>

    @if ($errors->any())
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ $accion }}" class="card mb-3">
        @csrf
        <input type="hidden" name="id" value="{{ $valor('id', $editando?->id) }}">

        <div class="card-body">
            <h2 class="h6 mb-2">{{ $editando ? 'Editar el día marcado' : 'Marcar un día' }}</h2>

            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-0" for="dia">Día</label>
                    <input type="date" name="dia" id="dia" class="form-control form-control-sm"
                           value="{{ $valor('dia', $editando?->dia?->toDateString() ?? ($mes->isCurrentMonth() ? now()->toDateString() : $mes->copy()->startOfMonth()->toDateString())) }}" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-0" for="tipo">Tipo</label>
                    <select name="tipo" id="tipo" class="form-select form-select-sm">
                        @foreach ($tipos as $claveTipo => $etiqueta)
                            <option value="{{ $claveTipo }}"
                                    {{ $valor('tipo', $editando?->tipo ?? DiaNoLaborable::FERIADO) === $claveTipo ? 'selected' : '' }}>
                                {{ $etiqueta }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small mb-0" for="motivo">Motivo</label>
                    <input type="text" name="motivo" id="motivo" class="form-control form-control-sm" maxlength="100"
                           placeholder="Lo que se le avisa a la secretaria" value="{{ $valor('motivo', $editando?->motivo) }}">
                    <span class="form-text small">Es el texto que muestra la agenda, como en el escritorio.</span>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-sm btn-primary flex-grow-1">
                        {{ $editando ? 'Guardar' : 'Marcar' }}
                    </button>
                    @if ($editando)
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('clinica.agenda.no-laborables', ['mes' => $mes->format('Y-m')]) }}">Cancelar</a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        @if ($dias->isEmpty())
            <div class="empty-state">
                <i class="bi bi-calendar-check"></i>
                <p>No hay días marcados en {{ strtolower($tituloMes) }}.</p>
                <p class="small">El médico atiende todos los días de este mes.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col">Día</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Motivo</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dias as $dia)
                            <tr>
                                <td>
                                    <strong>{{ FechaClinica::tituloDia($dia->dia) }}</strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $dia->etiquetaTipo() }}</span>
                                </td>
                                <td>
                                    {{ $dia->motivo ?: '—' }}
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('clinica.agenda.no-laborables', ['mes' => $mes->format('Y-m'), 'editar' => $dia->id]) }}">
                                        <i class="bi bi-pencil"></i> Editar
                                    </a>
                                    <form method="POST" action="{{ route('clinica.agenda.no-laborables.eliminar', $dia->id) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('¿Quitar la marca del {{ $dia->dia->format('d/m/Y') }}?');">
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
