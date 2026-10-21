@extends('layouts.app')

@section('title', 'Verificar correo · Registro de médicos')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

@section('content')
    <div class="container paciente-contenido">
        <div class="paciente-registro">
            <div class="paciente-registro-encabezado">
                <h1>Confirma tu correo</h1>
                <p>Enviamos un código de 6 dígitos a <strong>{{ $email }}</strong>. Escríbelo para enviar tu solicitud.</p>
            </div>

            <div class="paciente-tarjeta">
                @if (session('estado'))
                    <div class="alert alert-info" role="status">{{ session('estado') }}</div>
                @endif

                @if ($codigoDesarrollo)
                    <div class="alert alert-warning" role="status">
                        <strong>Modo desarrollo:</strong> el código es <span class="paciente-codigo-dev">{{ $codigoDesarrollo }}</span>.
                        En producción se envía por correo y no se muestra.
                    </div>
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

                <form method="POST" action="{{ route('medico.registro.confirmar') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="codigo" class="form-label">Código de 6 dígitos</label>
                        <input type="text" class="form-control form-control-lg paciente-codigo @error('codigo') is-invalid @enderror"
                               id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code" placeholder="000000" required autofocus>
                        @error('codigo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">Enviar mi solicitud</button>
                </form>

                <div class="d-flex flex-wrap justify-content-between gap-2 mt-4 pt-3 border-top">
                    <form method="POST" action="{{ route('medico.registro.reenviar') }}">
                        @csrf
                        <button type="submit" class="btn btn-link p-0">No me llegó: reenviar código</button>
                    </form>
                    <a href="{{ route('medico.registro') }}">Corregir mis datos</a>
                </div>
            </div>
        </div>
    </div>
@endsection
