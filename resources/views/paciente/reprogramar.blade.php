@extends('paciente.layout')

@section('titulo', 'Reprogramar cita · Doctorísimo')

@section('contenido')
    <a href="{{ route('paciente.citas') }}" class="paciente-volver">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a mis citas
    </a>

    <div class="paciente-tarjeta">
        <h1 class="paciente-titulo">Reprogramar la cita</h1>
        <p class="paciente-bajada">Elige otro de los próximos horarios del médico, con al menos 24 horas de anticipación. La cita sigue siendo la misma.</p>

        <div class="paciente-resumen">
            <p><strong>Ahora:</strong> {{ $cita->dia() }} · {{ $cita->horaLegible() }}</p>
            <p><strong>{{ $cita->medico->nombre_mostrar }}</strong> · {{ $cita->especialidades() }}</p>
            <p>{{ $cita->sede() }} — Referencia #{{ $cita->id() }}</p>
        </div>

        @if ($opciones === [])
            <div class="paciente-vacio">
                <i class="bi bi-calendar-x" aria-hidden="true"></i>
                <p>No hay horarios disponibles con al menos 24 horas de anticipación. Comunícate con el consultorio para cambiar la cita.</p>
                <a href="{{ route('paciente.citas') }}" class="btn btn-outline-primary">Volver a mis citas</a>
            </div>
        @else
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('paciente.citas.reprogramar.guardar', $cita->id()) }}">
                @csrf

                <fieldset class="mb-4">
                    <legend class="paciente-seccion-titulo">Nuevo horario</legend>

                    <div class="paciente-opciones">
                        @foreach ($opciones as $opcion)
                            <label class="paciente-opcion">
                                <input type="radio" name="horario" value="{{ $opcion->clave() }}"
                                       {{ old('horario') === $opcion->clave() ? 'checked' : '' }} required>
                                <span class="paciente-opcion-dia">{{ $opcion->dia() }}</span>
                                <span class="paciente-opcion-hora">{{ $opcion->horaLegible() }}</span>
                                <span class="paciente-opcion-sede">
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                    {{ $opcion->sede }}{{ $opcion->direccion ? ' — ' . $opcion->direccion : '' }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Mover la cita</button>
                    <a href="{{ route('paciente.citas') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        @endif
    </div>
@endsection
