@extends('layouts.app')

@section('title', 'Solicitud recibida · Registro de médicos')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

@section('content')
    <div class="container paciente-contenido">
        <div class="paciente-registro">
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
        </div>
    </div>
@endsection
