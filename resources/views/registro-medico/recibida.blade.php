@extends('layouts.app')

@section('title', $automatica ? 'Tu cuenta está lista · Registro de médicos' : 'Solicitud recibida · Registro de médicos')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

@section('content')
    <div class="container paciente-contenido">
        <div class="paciente-registro">
            @if ($automatica)
                <div class="paciente-registro-encabezado">
                    <h1>¡Tu cuenta está lista!</h1>
                    <p>Te mandamos a <strong>{{ $email }}</strong> tu correo de acceso y una clave temporal.</p>
                </div>

                <div class="paciente-tarjeta">
                    <p>
                        Ya puedes entrar y atender. Al iniciar sesión por primera vez te vamos a pedir que cambies
                        la clave temporal por la tuya.
                    </p>
                    <p class="text-muted small mb-3">
                        Tu carnet médico queda pendiente de verificación: no te impide usar la aplicación y lo
                        revisamos nosotros por separado.
                    </p>
                    <a href="{{ route('login') }}" class="btn btn-primary">Iniciar sesión</a>
                </div>
            @else
                <div class="paciente-registro-encabezado">
                    <h1>Recibimos tu solicitud</h1>
                    <p>La revisamos y te escribimos a <strong>{{ $email }}</strong> con la respuesta.</p>
                </div>

                <div class="paciente-tarjeta">
                    <p>
                        Verificamos tu cédula y tu registro del MPPS. Cuando la aprobemos, te llegará al correo una clave
                        temporal para entrar; la cambias al iniciar sesión por primera vez.
                    </p>
                    <a href="{{ route('welcome') }}" class="btn btn-outline-secondary">Volver al inicio</a>
                </div>
            @endif
        </div>
    </div>
@endsection
