@extends('layouts.app')

@section('title', 'Crear cuenta de paciente · Doctorísimo')
@section('description', 'Crea tu cuenta de paciente en Doctorísimo para ver, cancelar y reprogramar tus citas médicas. Confirmas tu teléfono con un código.')
@section('robots', 'noindex, follow')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

@section('content')
    <div class="container paciente-contenido">
        <div class="paciente-registro">
            <div class="paciente-registro-encabezado">
                <h1>Crea tu cuenta de paciente</h1>
                <p>Con tu cuenta puedes ver, cancelar y reprogramar tus citas. Te enviaremos un código al teléfono para confirmar.</p>
            </div>

            <div class="paciente-tarjeta">
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <strong>Revisa lo que marcamos:</strong>
                        <ul class="mb-0 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('paciente.registro.codigo.enviar') }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="cedula" class="form-label">Cédula</label>
                            <input type="text" class="form-control" id="cedula" name="cedula"
                                   value="{{ old('cedula', $cedula) }}" inputmode="numeric" placeholder="12345678" required>
                            <div class="form-text">Solo los números.</div>
                        </div>

                        <div class="col-md-4">
                            <label for="nombres" class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="nombres" name="nombres"
                                   value="{{ old('nombres') }}" autocomplete="given-name" required>
                        </div>

                        <div class="col-md-4">
                            <label for="apellidos" class="form-label">Apellido</label>
                            <input type="text" class="form-control" id="apellidos" name="apellidos"
                                   value="{{ old('apellidos') }}" autocomplete="family-name" required>
                        </div>

                        <div class="col-md-6">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control" id="telefono" name="telefono"
                                   value="{{ old('telefono', $telefono) }}" autocomplete="tel" placeholder="0412-1234567" required>
                            <div class="form-text">Ahí te llega el código de confirmación.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label">Correo</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="{{ old('email') }}" autocomplete="email" placeholder="tucorreo@ejemplo.com" required>
                            <div class="form-text">Con este correo vas a iniciar sesión.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" class="form-control" id="password" name="password"
                                   autocomplete="new-password" minlength="8" required>
                            <div class="form-text">Mínimo 8 caracteres.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="password_confirmation" class="form-label">Repite la contraseña</label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                                   autocomplete="new-password" minlength="8" required>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Continuar y verificar mi teléfono</button>
                        <a href="{{ route('login') }}" class="btn btn-outline-secondary">Ya tengo cuenta</a>
                    </div>

                    <p class="paciente-letra-chica mt-3 mb-0">
                        Al crear tu cuenta aceptas que el consultorio te contacte por tus citas. Tus datos se usan solo para eso.
                    </p>
                </form>
            </div>
        </div>
    </div>
@endsection
