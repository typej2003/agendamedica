@extends('layouts.app')

@section('title', 'Reservar cita · Doctorísimo')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/reservas.css') }}">
@endpush

@section('content')
    <section class="reservas-hero">
        <div class="container">
            <span class="reservas-etiqueta"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Reserva en línea</span>
            <h1 class="reservas-titulo">Reserva tu cita</h1>
            <p class="reservas-bajada">
                Elige especialidad y médico, mira los próximos horarios y confirma con un código en tu teléfono.
                Sin crear una cuenta.
            </p>

            <form method="GET" action="{{ route('reservas.catalogo') }}" class="reservas-buscador" role="search">
                @if ($especialidad !== '')
                    <input type="hidden" name="especialidad" value="{{ $especialidad }}">
                @endif
                <label class="visually-hidden" for="reservas-buscar">Buscar médico por nombre</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" class="form-control" id="reservas-buscar" name="buscar"
                           value="{{ $buscar }}" placeholder="Buscar por nombre o apellido">
                    <button class="btn btn-primary" type="submit">Buscar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="container reservas-cuerpo" aria-labelledby="reservas-medicos-titulo">
        <div class="reservas-filtros" role="group" aria-label="Filtrar por especialidad">
            <a href="{{ route('reservas.catalogo', array_filter(['buscar' => $buscar])) }}"
               class="reservas-chip {{ $especialidad === '' ? 'reservas-chip-activo' : '' }}">Todas</a>

            @foreach ($especialidades as $especialidadOpcion)
                @php($activa = $especialidad === $especialidadOpcion->slug)
                <a href="{{ route('reservas.catalogo', array_filter(['especialidad' => $especialidadOpcion->slug, 'buscar' => $buscar])) }}"
                   class="reservas-chip {{ $activa ? 'reservas-chip-activo' : '' }}"
                   @if ($activa) aria-current="true" @endif>
                    {{ $especialidadOpcion->name }}
                </a>
            @endforeach
        </div>

        <h2 class="visually-hidden" id="reservas-medicos-titulo">Médicos con agenda publicada</h2>

        @if ($medicos->isEmpty())
            <div class="reservas-vacio">
                <i class="bi bi-calendar-x" aria-hidden="true"></i>
                <h3>No hay médicos con agenda publicada para esta búsqueda</h3>
                <p>Prueba con otra especialidad o con otro nombre. Si tu médico no aparece, comunícate con su consultorio.</p>
                <a class="btn btn-outline-primary" href="{{ route('reservas.catalogo') }}">Ver todos los médicos</a>
            </div>
        @else
            <div class="row g-3">
                @foreach ($medicos as $medico)
                    <div class="col-md-6 col-xl-4">
                        <article class="card reservas-medico">
                            <div class="reservas-medico-cuerpo">
                                <div class="reservas-avatar" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($medico->name ?? 'M', 0, 1) . mb_substr($medico->lastname ?? '', 0, 1)) }}
                                </div>

                                <div class="reservas-medico-texto">
                                    <h3 class="reservas-medico-nombre">{{ $medico->nombre_mostrar }}</h3>
                                    <p class="reservas-medico-especialidad">
                                        {{ $medico->specialties->pluck('name')->join(' · ') }}
                                    </p>

                                    @php($sedesDelMedico = $sedes[$medico->id] ?? [])
                                    @if ($sedesDelMedico !== [])
                                        <p class="reservas-medico-sede">
                                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                            {{ implode(' · ', $sedesDelMedico) }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <a href="{{ route('reservas.medico', $medico) }}" class="btn btn-primary w-100">
                                Ver horarios <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
