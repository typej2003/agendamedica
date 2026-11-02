@extends('layouts.app')

@section('title', $medico->nombre_mostrar . ' · Reservar cita')
@section('description', 'Reserva en línea tu cita con ' . $medico->nombre_mostrar
    . ($especialidades->isNotEmpty() ? ' (' . $especialidades->pluck('name')->implode(', ') . ')' : '')
    . '. Mira sus próximos horarios y confirma con un código en tu teléfono.')

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
                    <p class="reservas-bio">{{ $medico->biography }}</p>
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
                        @if ($sede->medicalCenter?->phone)
                            <br>
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sede->medicalCenter->phone) }}" class="reservas-telefono">
                                <i class="bi bi-telephone" aria-hidden="true"></i> {{ $sede->medicalCenter->phone }}
                            </a>
                        @endif
                    </p>
                @endforeach
            </div>
        @endif

        @if ($citaLinea->tieneIndicacionesParaLaCita())
            {{-- Lo que el médico quiere que sepas antes de venir (O-52). --}}
            <section class="reservas-aviso" aria-labelledby="antes-de-venir">
                <h2 class="reservas-aviso-titulo" id="antes-de-venir">
                    <i class="bi bi-info-circle" aria-hidden="true"></i> Antes de venir
                </h2>
                <ul class="reservas-aviso-lista">
                    @if ($citaLinea->minutosAntes() !== null)
                        <li>Llega <strong>{{ $citaLinea->minutosAntes() }} minutos antes</strong> de tu cita.</li>
                    @endif
                    @if ($citaLinea->acompanantes() !== null)
                        <li>
                            @if ($citaLinea->acompanantes() === 0)
                                Esta consulta no admite acompañantes.
                            @elseif ($citaLinea->acompanantes() === 1)
                                Se admite <strong>un acompañante</strong>.
                            @else
                                Se admiten hasta <strong>{{ $citaLinea->acompanantes() }} acompañantes</strong>.
                            @endif
                        </li>
                    @endif
                    @if ($citaLinea->indicaciones() !== null)
                        <li>{{ $citaLinea->indicaciones() }}</li>
                    @endif
                </ul>
            </section>
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
                        Los días marcados tienen lugar. Elige uno para ver sus horarios.
                        Si no te sirve ninguno, comunícate con el consultorio.
                    </p>

                    {{-- El calendario del mes (O-52): los días con lugar son enlaces, así se usa sin JavaScript. --}}
                    <div class="reservas-calendario">
                        <div class="reservas-calendario-cabecera">
                            @if ($calendario['anterior'] !== null)
                                <a class="reservas-calendario-flecha"
                                   href="{{ route('reservas.medico', ['medico' => $medico, 'mes' => $calendario['anterior']]) }}"
                                   aria-label="Mes anterior">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="reservas-calendario-flecha reservas-calendario-flecha-muerta" aria-hidden="true">
                                    <i class="bi bi-chevron-left"></i>
                                </span>
                            @endif

                            <p class="reservas-calendario-mes" id="calendario-mes">{{ $calendario['titulo'] }}</p>

                            @if ($calendario['siguiente'] !== null)
                                <a class="reservas-calendario-flecha"
                                   href="{{ route('reservas.medico', ['medico' => $medico, 'mes' => $calendario['siguiente']]) }}"
                                   aria-label="Mes siguiente">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="reservas-calendario-flecha reservas-calendario-flecha-muerta" aria-hidden="true">
                                    <i class="bi bi-chevron-right"></i>
                                </span>
                            @endif
                        </div>

                        <ol class="reservas-calendario-rejilla" aria-labelledby="calendario-mes">
                            @foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $letra)
                                <li class="reservas-calendario-letra" aria-hidden="true">{{ $letra }}</li>
                            @endforeach

                            @foreach ($calendario['dias'] as $dia)
                                @if ($dia === null)
                                    <li class="reservas-calendario-hueco" aria-hidden="true"></li>
                                @elseif ($dia['disponible'])
                                    <li>
                                        <a class="reservas-calendario-dia reservas-calendario-dia-libre {{ $dia['elegido'] ? 'reservas-calendario-dia-elegido' : '' }} {{ $dia['hoy'] ? 'reservas-calendario-dia-hoy' : '' }}"
                                           href="{{ route('reservas.medico', ['medico' => $medico, 'mes' => $mes->format('Y-m'), 'fecha' => $dia['fecha']]) }}"
                                           @if ($dia['elegido']) aria-current="date" @endif
                                           aria-label="{{ \Carbon\Carbon::createFromFormat('!Y-m-d', $dia['fecha'])->locale('es')->isoFormat('D [de] MMMM') }}, con horarios">
                                            {{ $dia['numero'] }}
                                        </a>
                                    </li>
                                @else
                                    <li>
                                        <span class="reservas-calendario-dia reservas-calendario-dia-ocupado {{ $dia['hoy'] ? 'reservas-calendario-dia-hoy' : '' }}"
                                              aria-disabled="true">{{ $dia['numero'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    </div>

                    @if ($fechaElegida !== null)
                        <p class="reservas-ayuda reservas-dia-elegido">
                            Horarios del
                            <strong>{{ \Carbon\Carbon::createFromFormat('!Y-m-d', $fechaElegida)->locale('es')->isoFormat('dddd D [de] MMMM') }}</strong>.
                            <a href="{{ route('reservas.medico', ['medico' => $medico, 'mes' => $mes->format('Y-m')]) }}">Ver todos los próximos horarios</a>
                        </p>
                    @else
                        <p class="reservas-ayuda"><strong>Próximos horarios</strong></p>
                    @endif

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

                    @if ($motivos->isNotEmpty())
                        <div class="mt-3">
                            <label for="motivo" class="form-label">Motivo de la consulta</label>
                            <select class="form-select @error('motivo') is-invalid @enderror" id="motivo" name="motivo" required>
                                <option value="">Elige el motivo</option>
                                @foreach ($motivos as $motivo)
                                    <option value="{{ $motivo->codigo }}" {{ old('motivo') === $motivo->codigo ? 'selected' : '' }}>
                                        {{ $motivo->tipo_atencion }}
                                        · {{ \App\Pagos\Centavos::formatear(\App\Pagos\Centavos::deDecimal((string) $motivo->precio), 'USD') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Es el motivo de tu cita; el precio es el de la consulta.</div>
                            @error('motivo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif
                </fieldset>

                <fieldset class="reservas-paso">
                    <legend class="reservas-paso-titulo">
                        <span class="reservas-paso-numero">2</span> Tus datos
                    </legend>
                    <p class="reservas-ayuda">
                        Te enviaremos un código de 6 dígitos al teléfono para confirmar la cita. No creamos ninguna cuenta.
                    </p>

                    <div class="row g-3">
                        <div class="col-12">
                            {{-- ¿Quién reserva? (O-52). Los campos del representante son opcionales hasta que
                                 se elige «su representante», y en ese caso se validan en el servidor. --}}
                            <fieldset class="reservas-quien">
                                <legend class="form-label mb-2">¿Quién reserva?</legend>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reservado_por" id="reservado_por_p"
                                           value="P" {{ old('reservado_por', 'P') === 'P' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="reservado_por_p">Soy el paciente</label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reservado_por" id="reservado_por_r"
                                           value="R" {{ old('reservado_por') === 'R' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="reservado_por_r">
                                        Soy su representante
                                    </label>
                                </div>

                                <div class="row g-3 mt-1">
                                    <div class="col-md-6">
                                        <label for="representante_nombre" class="form-label">
                                            Tu nombre <span class="text-muted">(si eres el representante)</span>
                                        </label>
                                        <input type="text" class="form-control @error('representante_nombre') is-invalid @enderror"
                                               id="representante_nombre" name="representante_nombre"
                                               value="{{ old('representante_nombre') }}" autocomplete="name">
                                        @error('representante_nombre') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label for="representante_documento" class="form-label">
                                            Tu documento <span class="text-muted">(si eres el representante)</span>
                                        </label>
                                        <input type="text" class="form-control @error('representante_documento') is-invalid @enderror"
                                               id="representante_documento" name="representante_documento"
                                               value="{{ old('representante_documento') }}" inputmode="numeric">
                                        @error('representante_documento') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </fieldset>
                        </div>

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
