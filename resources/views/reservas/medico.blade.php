@extends('layouts.app')

@section('title', $medico->nombre_mostrar . ' · Reservar cita')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/reservas.css') }}">
@endpush

@section('content')
    <div class="container reservas-cuerpo">
        <a href="{{ route('reservas.catalogo') }}" class="reservas-volver">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a los médicos
        </a>

        <header class="reservas-ficha">
            <div class="reservas-avatar reservas-avatar-grande" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($medico->name ?? 'M', 0, 1) . mb_substr($medico->lastname ?? '', 0, 1)) }}
            </div>

            <div>
                <p class="reservas-etiqueta reservas-etiqueta-suave">Agenda publicada</p>
                <h1 class="reservas-titulo reservas-titulo-chico">{{ $medico->nombre_mostrar }}</h1>
                <p class="reservas-medico-especialidad">
                    {{ $especialidades->pluck('name')->join(' · ') ?: 'Consulta médica' }}
                </p>

                @if ($medico->biography)
                    <p class="reservas-bio">{{ \Illuminate\Support\Str::limit($medico->biography, 260) }}</p>
                @endif
            </div>
        </header>

        @if ($sedes->isNotEmpty())
            <div class="reservas-sedes">
                @foreach ($sedes as $sede)
                    <p>
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <strong>{{ $sede->name }}</strong>
                        @if ($sede->address)
                            — {{ $sede->address }}
                        @endif
                    </p>
                @endforeach
            </div>
        @endif

        @if (session('estado'))
            <div class="alert alert-success" role="status">{{ session('estado') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-warning" role="alert">{{ session('error') }}</div>
        @endif

        @if ($opciones === [])
            <div class="reservas-vacio">
                <i class="bi bi-calendar-x" aria-hidden="true"></i>
                <h2>Este médico no tiene horarios publicados en este momento</h2>
                <p>Comunícate con su consultorio para agendar, o elige otro médico del catálogo.</p>
                <a class="btn btn-outline-primary" href="{{ route('reservas.catalogo') }}">Ver otros médicos</a>
            </div>
        @else
            <form method="POST" action="{{ route('reservas.codigo.enviar', $medico) }}" class="reservas-form">
                @csrf

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

                <fieldset class="reservas-paso">
                    <legend class="reservas-paso-titulo">
                        <span class="reservas-paso-numero">1</span> Elige el horario
                    </legend>
                    <p class="reservas-ayuda">
                        Estos son los próximos horarios con lugar. Si no te sirve ninguno, comunícate con el consultorio.
                    </p>

                    <div class="reservas-opciones">
                        @foreach ($opciones as $opcion)
                            <label class="reservas-opcion">
                                <input type="radio" name="horario" value="{{ $opcion->clave() }}"
                                       {{ old('horario') === $opcion->clave() ? 'checked' : '' }} required>
                                <span class="reservas-opcion-contenido">
                                    <span class="reservas-opcion-dia">{{ $opcion->dia() }}</span>
                                    <span class="reservas-opcion-hora">{{ $opcion->horaLegible() }}</span>
                                    <span class="reservas-opcion-sede">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                        {{ $opcion->sede }}{{ $opcion->direccion ? ' — ' . $opcion->direccion : '' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset class="reservas-paso">
                    <legend class="reservas-paso-titulo">
                        <span class="reservas-paso-numero">2</span> Tus datos
                    </legend>
                    <p class="reservas-ayuda">
                        Te enviaremos un código de 6 dígitos al teléfono para confirmar la cita. No creamos ninguna cuenta.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="cedula" class="form-label">Cédula</label>
                            <input type="text" class="form-control @error('cedula') is-invalid @enderror"
                                   id="cedula" name="cedula" value="{{ old('cedula') }}"
                                   inputmode="numeric" placeholder="12345678" required>
                            <div class="form-text">Solo los números.</div>
                            @error('cedula') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label for="nombres" class="form-label">Nombre</label>
                            <input type="text" class="form-control @error('nombres') is-invalid @enderror"
                                   id="nombres" name="nombres" value="{{ old('nombres') }}"
                                   autocomplete="given-name" required>
                            @error('nombres') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label for="apellidos" class="form-label">Apellido</label>
                            <input type="text" class="form-control @error('apellidos') is-invalid @enderror"
                                   id="apellidos" name="apellidos" value="{{ old('apellidos') }}"
                                   autocomplete="family-name" required>
                            @error('apellidos') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control @error('telefono') is-invalid @enderror"
                                   id="telefono" name="telefono" value="{{ old('telefono') }}"
                                   autocomplete="tel" placeholder="0412-1234567" required>
                            <div class="form-text">Ahí te llega el código de confirmación.</div>
                            @error('telefono') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label">Correo <span class="text-muted">(opcional)</span></label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email') }}"
                                   autocomplete="email" placeholder="tucorreo@ejemplo.com">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </fieldset>

                <div class="reservas-enviar">
                    <button type="submit" class="btn btn-primary btn-lg">
                        Continuar y verificar mi teléfono
                    </button>
                    <p class="reservas-letra-chica">
                        Al continuar aceptas que el consultorio te contacte por la cita. Tus datos se usan solo para eso.
                    </p>
                </div>
            </form>
        @endif
    </div>
@endsection
