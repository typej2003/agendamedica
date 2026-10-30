@extends('layouts.app')

@section('title', 'Mis subespecialidades · Doctorísimo')

@section('content')
    <div class="container" style="max-width: 760px; padding-top: 24px; padding-bottom: 48px;">
        <h1 class="h4 mb-1">Mis subespecialidades</h1>
        <p class="text-muted">
            Las subespecialidades (postgrados) encienden los módulos y las secciones de tu consulta. Puedes
            activar y desactivar las de las especialidades que ya tienes.
        </p>

        @if (session('estado'))
            <div class="alert alert-success" role="status">{{ session('estado') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @forelse ($medicos as $bloque)
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-3">
                        {{ trim($bloque['medico']->name . ' ' . $bloque['medico']->lastname) }}
                        <span class="text-muted">· {{ $bloque['medico']->reg_medico }}</span>
                    </h2>

                    <form method="POST" action="{{ route('clinica.mis-subespecialidades.guardar') }}">
                        @csrf
                        <input type="hidden" name="medico_id" value="{{ $bloque['medico']->id }}">

                        @foreach ($bloque['especialidades'] as $fila)
                            <fieldset class="mb-3">
                                <legend class="h6">{{ $fila['especialidad']->name }}</legend>

                                @foreach ($fila['postgrados'] as $postgrado)
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="subespecialidades[]"
                                            value="{{ $postgrado->id }}"
                                            id="sub-{{ $bloque['medico']->id }}-{{ $postgrado->id }}"
                                            @checked(in_array($postgrado->id, $fila['activas'], true))
                                        >
                                        <label class="form-check-label" for="sub-{{ $bloque['medico']->id }}-{{ $postgrado->id }}">
                                            {{ $postgrado->nombre }}
                                        </label>
                                    </div>
                                @endforeach
                            </fieldset>
                        @endforeach

                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="alert alert-info">
                No tienes subespecialidades para elegir. Aparecen cuando el administrador te asigna una
                especialidad que tiene postgrados (pediatría, por ejemplo).
            </div>
        @endforelse
    </div>
@endsection
