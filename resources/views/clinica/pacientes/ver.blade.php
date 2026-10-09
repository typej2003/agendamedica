@extends('clinica.layout')

@section('titulo', 'Ficha del paciente')

@section('contenido')
    <div class="page-head">
        <div>
            <h1>{{ trim($paciente->apellidos . ', ' . $paciente->nombres) }}</h1>
            @if ($paciente->numhistoria)
                <p class="page-head-sub">Historia N.º {{ $paciente->numhistoria }}</p>
            @endif
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('clinica.pacientes') }}">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">Datos del paciente</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-4">Cédula</dt><dd class="col-8">{{ $paciente->cedula ?: '—' }}</dd>
                        <dt class="col-4">N.º de historia</dt>
                        <dd class="col-8">
                            @if ($paciente->numhistoria)
                                {{ $paciente->numhistoria }}
                            @else
                                <span class="badge bg-secondary">Sin historia</span>
                            @endif
                        </dd>
                        <dt class="col-4">Nacimiento</dt>
                        <dd class="col-8">
                            {{ \App\Support\FechaClinica::formato($paciente->fnacimiento) }}
                            @php $edad = \App\Support\FechaClinica::edad($paciente->fnacimiento); @endphp
                            @if ($edad !== null) <span class="text-muted">({{ $edad }} años)</span> @endif
                        </dd>
                        <dt class="col-4">Sexo</dt><dd class="col-8">{{ $paciente->sexo ?: '—' }}</dd>
                        <dt class="col-4">Teléfono</dt><dd class="col-8">{{ $paciente->telefono ?: '—' }}</dd>
                        <dt class="col-4">Correo</dt><dd class="col-8">{{ $paciente->email ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header">Consultas registradas</div>
                <div class="card-body p-0">
                    @if ($paciente->numhistoria === null)
                        <p class="text-muted p-3 mb-0">
                            El paciente todavía no tiene número de historia, así que no hay consultas asociadas.
                        </p>
                    @elseif ($consultas->isEmpty())
                        <p class="text-muted p-3 mb-0">Sin consultas registradas.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($consultas as $consulta)
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span>{{ \App\Support\FechaClinica::formato($consulta->fecha) }}</span>
                                        <span class="text-muted small">Consulta {{ $consulta->nroconsulta }}</span>
                                    </div>
                                    @if (trim((string) $consulta->enfermedadactual) !== '')
                                        <div class="small text-muted text-truncate">{{ $consulta->enfermedadactual }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Ficha de <strong>sólo lectura</strong>: editar, iniciar consulta y recetar llegan en los módulos siguientes.
    </p>
@endsection
