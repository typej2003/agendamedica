@extends('layouts.app')

@section('title', 'Verificar teléfono · Reservar cita')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/reservas.css') }}">
@endpush

@php
    $dia = ucfirst(\Carbon\Carbon::createFromFormat('!Y-m-d', $pendiente['fecha'])->locale('es')->isoFormat('dddd D [de] MMMM'));
    $hora = \App\Support\HoraLocal::hora12($pendiente['hora']);
    $horario = $pendiente['fecha'] . '|' . $pendiente['hora'] . '|' . $pendiente['office_id'];
@endphp

@section('content')
    <div class="container reservas-cuerpo reservas-cuerpo-angosto">
        <div class="reservas-pasos" aria-label="Pasos de la reserva">
            <span class="reservas-paso-hecho"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Horario y datos</span>
            <span class="reservas-paso-activo"><span class="reservas-paso-numero">2</span> Verificar teléfono</span>
            <span class="reservas-paso-pendiente"><span class="reservas-paso-numero">3</span> Confirmación</span>
        </div>

        <div class="card reservas-panel">
            <h1 class="reservas-titulo reservas-titulo-chico">Confirma tu teléfono</h1>
            <p class="reservas-bajada">
                Enviamos un código de 6 dígitos a <strong>{{ $telefonoVisible }}</strong>.
                Escríbelo para reservar la cita.
            </p>

            <div class="reservas-resumen">
                <p class="reservas-resumen-medico">{{ $medico->nombre_mostrar }}</p>
                <p><i class="bi bi-calendar-event" aria-hidden="true"></i> {{ $dia }} · {{ $hora }}</p>
                <p><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $pendiente['sede'] ?? '' }}</p>
            </div>

            @if ($codigoDesarrollo)
                <div class="alert alert-warning" role="status">
                    <strong>Modo desarrollo:</strong> el código es <span class="reservas-codigo-dev">{{ $codigoDesarrollo }}</span>.
                    En producción se envía por WhatsApp o SMS y no se muestra.
                </div>
            @endif

            @if (session('estado'))
                <div class="alert alert-success" role="status">{{ session('estado') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('reservas.confirmar', $medico) }}" class="reservas-form">
                @csrf

                <div class="mb-3">
                    <label for="codigo" class="form-label">Código de 6 dígitos</label>
                    <input type="text" class="form-control form-control-lg reservas-codigo @error('codigo') is-invalid @enderror"
                           id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000" required autofocus>
                    @error('codigo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">Confirmar cita</button>
            </form>

            <div class="reservas-acciones-secundarias">
                <form method="POST" action="{{ route('reservas.codigo.enviar', $medico) }}">
                    @csrf
                    <input type="hidden" name="reenviar" value="1">
                    <input type="hidden" name="horario" value="{{ $horario }}">
                    <input type="hidden" name="cedula" value="{{ $pendiente['cedula'] }}">
                    <input type="hidden" name="nombres" value="{{ $pendiente['nombres'] }}">
                    <input type="hidden" name="apellidos" value="{{ $pendiente['apellidos'] }}">
                    <input type="hidden" name="telefono" value="{{ $pendiente['telefono'] }}">
                    <input type="hidden" name="email" value="{{ $pendiente['email'] }}">

                    <button type="submit" class="btn btn-link p-0">No me llegó: reenviar código</button>
                </form>

                <a href="{{ route('reservas.medico', $medico) }}">Cambiar horario o datos</a>
            </div>
        </div>
    </div>
@endsection
