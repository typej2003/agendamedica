@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" :volver-texto="$vm->textoVolver()" />

    <x-ui.errores />

    <form method="POST" action="{{ $vm->urlGuardar() }}">
        @csrf
        @if ($vm->editando())
            @method('PUT')
        @endif

        @if (! $vm->editando() && ($hermano ?? null) !== null)
            <input type="hidden" name="hermano_de" value="{{ $hermano->id }}">
            <x-ui.estado tono="info" icono="bi-people" class="mb-3">
                Vas a registrar a un hermano(a) de {{ $hermano->nombres }}: al guardar comparte su familia y el teléfono de contacto.
            </x-ui.estado>
        @endif

        @include('clinica.pacientes._campos')

        {{-- El representante del alta de pediatría (2026-10-19: un representante puede tener varios hijos): va
             después de los datos del niño porque es el adulto que responde por él, y sólo el alta de pediatría lo
             pide. En la edición y en las demás especialidades el bloque de la ficha sigue siendo el camino. --}}
        @if ($representanteAlta->ofrece())
            @include('clinica.pacientes._representante-alta', ['representanteAlta' => $representanteAlta])
        @endif

        {{-- «¿Atenderá a este paciente hoy?» ya no se pregunta acá: el alta desde la web **numera la historia**
             en el mismo paso (O-04) y, al volver a la ficha, la pregunta es «¿Atender ahora?» (O-05), que es
             donde se abre la consulta o se le crea la cita del día. --}}

        <div class="d-flex justify-content-end gap-2 mb-3">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlCancelar() }}">Cancelar</a>
            <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
        </div>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/paciente-formulario.js') }}"></script>
    @if ($representanteAlta->ofrece())
        <script src="{{ asset('js/clinica/representante-alta.js') }}"></script>
    @endif
@endsection
