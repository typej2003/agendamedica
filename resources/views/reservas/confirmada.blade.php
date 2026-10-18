@extends('layouts.app')

@section('title', 'Cita reservada · Doctorísimo')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/reservas.css') }}">
@endpush

@section('content')
    <div class="container reservas-cuerpo reservas-cuerpo-angosto">
        <div class="card reservas-panel reservas-confirmacion">
            <div class="reservas-confirmacion-icono" aria-hidden="true">
                <i class="bi bi-check-lg"></i>
            </div>

            <h1 class="reservas-titulo">¡Cita reservada!</h1>
            <p class="reservas-bajada">
                Te esperamos. Llega unos 15 minutos antes y lleva tu cédula.
            </p>

            <dl class="reservas-detalle">
                <div>
                    <dt>Paciente</dt>
                    <dd>{{ $reserva['paciente'] }}</dd>
                </div>
                <div>
                    <dt>Médico</dt>
                    <dd>{{ $reserva['medico'] }}</dd>
                </div>
                <div>
                    <dt>Especialidad</dt>
                    <dd>{{ implode(' · ', $reserva['especialidades']) ?: 'Consulta médica' }}</dd>
                </div>
                <div>
                    <dt>Día y hora</dt>
                    <dd>{{ $reserva['dia'] }} · {{ $reserva['hora12'] }}</dd>
                </div>
                <div>
                    <dt>Lugar</dt>
                    <dd>{{ $reserva['sede'] }}{{ $reserva['direccion'] ? ' — ' . $reserva['direccion'] : '' }}</dd>
                </div>
                <div>
                    <dt>Turno</dt>
                    <dd>#{{ $reserva['turno'] }}</dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>#{{ $reserva['cita_id'] }}</dd>
                </div>
            </dl>

            <p class="reservas-letra-chica">
                Guarda esta referencia. Si necesitas cambiar o cancelar la cita, comunícate con el consultorio.
            </p>

            <div class="reservas-confirmacion-acciones">
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('reservas.catalogo') }}">Reservar otra cita</a>
            </div>
        </div>
    </div>
@endsection
