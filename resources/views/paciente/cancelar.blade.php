@extends('paciente.layout')

@section('titulo', 'Cancelar cita · Doctorísimo')

@section('contenido')
    <a href="{{ route('paciente.citas') }}" class="paciente-volver">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a mis citas
    </a>

    <div class="paciente-tarjeta">
        <h1 class="paciente-titulo">¿Cancelar esta cita?</h1>
        <p class="paciente-bajada">Esta acción no se puede deshacer. Si quieres moverla a otro día, usa «Reprogramar».</p>

        <div class="paciente-resumen">
            <p><strong>{{ $cita->medico->nombre_mostrar }}</strong> · {{ $cita->especialidades() }}</p>
            <p>{{ $cita->dia() }} · {{ $cita->hora12() }}</p>
            <p>{{ $cita->sede() }}@if ($cita->direccion()) — {{ $cita->direccion() }}@endif</p>
            <p>Referencia #{{ $cita->id() }} · Turno #{{ $cita->turno() }}</p>
        </div>

        <form method="POST" action="{{ route('paciente.citas.cancelar.guardar', $cita->id()) }}" class="d-flex flex-wrap gap-2">
            @csrf
            <button type="submit" class="btn btn-danger">Sí, cancelar la cita</button>
            <a href="{{ route('paciente.citas') }}" class="btn btn-outline-secondary">No, volver</a>
        </form>
    </div>
@endsection
