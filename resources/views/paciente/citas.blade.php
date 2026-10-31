@extends('paciente.layout')

@section('titulo', 'Mis citas · Doctorísimo')

@section('contenido')
    <div class="paciente-cabecera">
        <div>
            <h1 class="paciente-titulo">Hola, {{ \Illuminate\Support\Str::words(auth()->user()->name, 1, '') }}</h1>
            <p class="paciente-bajada">Estas son tus citas. Puedes cancelar o reprogramar las que todavía no llegan.</p>
        </div>
        <a href="{{ route('reservas.catalogo') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle" aria-hidden="true"></i> Reservar otra cita
        </a>
    </div>

    <section class="paciente-seccion" aria-labelledby="proximas-titulo">
        <h2 class="paciente-seccion-titulo" id="proximas-titulo">Próximas</h2>

        @if ($proximas->isEmpty())
            <div class="paciente-vacio">
                <i class="bi bi-calendar2-plus" aria-hidden="true"></i>
                <p>No tienes citas próximas.</p>
                <a href="{{ route('reservas.catalogo') }}" class="btn btn-primary">Reservar una cita</a>
            </div>
        @else
            @foreach ($proximas as $cita)
                <article class="paciente-cita">
                    <div class="paciente-cita-fecha" aria-hidden="true">
                        <span class="paciente-cita-dia">{{ $cita->diaNumero() }}</span>
                        <span class="paciente-cita-mes">{{ $cita->mes() }}</span>
                    </div>

                    <div class="paciente-cita-cuerpo">
                        <h3 class="paciente-cita-medico">{{ $cita->medico->nombre_mostrar }}</h3>
                        <p class="paciente-cita-especialidad">{{ $cita->especialidades() }}</p>
                        <p class="paciente-cita-dato"><i class="bi bi-calendar-event" aria-hidden="true"></i> {{ $cita->dia() }}</p>
                        <p class="paciente-cita-dato">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $cita->sede() }}
                            @if ($cita->direccion()) — {{ $cita->direccion() }} @endif
                        </p>
                        <p class="paciente-cita-dato">
                            <i class="bi bi-hash" aria-hidden="true"></i> Referencia #{{ $cita->id() }}
                            @if ($cita->turno() !== null) · Turno #{{ $cita->turno() }} @endif
                        </p>
                        @if ($cita->motivo() !== '—')
                            <p class="paciente-cita-dato"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> {{ $cita->motivo() }}</p>
                        @endif
                    </div>

                    <div class="paciente-cita-lado">
                        <span class="paciente-cita-hora">{{ $cita->horaLegible() }}</span>
                        <span class="paciente-estado {{ $cita->estadoClase() }}">{{ $cita->estado() }}</span>

                        @include('paciente._pagar', ['cita' => $cita])

                        @if ($cita->puedeGestionarse())
                            <div class="paciente-cita-acciones">
                                <a href="{{ route('paciente.citas.reprogramar', $cita->id()) }}" class="btn btn-outline-primary btn-sm">
                                    Reprogramar
                                </a>
                                <a href="{{ route('paciente.citas.cancelar', $cita->id()) }}" class="btn btn-outline-danger btn-sm">
                                    Cancelar
                                </a>
                            </div>
                        @else
                            <p class="paciente-aviso-cita">{{ $cita->aviso }}</p>
                        @endif
                    </div>
                </article>
            @endforeach
        @endif
    </section>

    @if ($pasadas->isNotEmpty())
        <section class="paciente-seccion" aria-labelledby="historial-titulo">
            <h2 class="paciente-seccion-titulo" id="historial-titulo">Historial</h2>

            @foreach ($pasadas as $cita)
                <article class="paciente-cita">
                    <div class="paciente-cita-fecha" aria-hidden="true">
                        <span class="paciente-cita-dia">{{ $cita->diaNumero() }}</span>
                        <span class="paciente-cita-mes">{{ $cita->mes() }}</span>
                    </div>

                    <div class="paciente-cita-cuerpo">
                        <h3 class="paciente-cita-medico">{{ $cita->medico->nombre_mostrar }}</h3>
                        <p class="paciente-cita-especialidad">{{ $cita->especialidades() }}</p>
                        <p class="paciente-cita-dato"><i class="bi bi-calendar-event" aria-hidden="true"></i> {{ $cita->dia() }} · {{ $cita->horaLegible() }}</p>
                        <p class="paciente-cita-dato"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $cita->sede() }}</p>
                    </div>

                    <div class="paciente-cita-lado">
                        <span class="paciente-estado {{ $cita->estadoClase() }}">{{ $cita->estado() }}</span>

                        @include('paciente._pagar', ['cita' => $cita])
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection
