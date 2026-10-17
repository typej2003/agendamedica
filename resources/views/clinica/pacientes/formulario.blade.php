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

        @include('clinica.pacientes._campos')

        @unless ($vm->editando())
            <x-ui.tarjeta class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="atender_hoy" id="atender_hoy" value="1">
                    <label class="form-check-label" for="atender_hoy">
                        ¿Atenderá a este paciente hoy?
                    </label>
                </div>
                <p class="form-text mb-0" id="atender-hoy-ayuda">
                    Si lo marcas, al guardar te llevo a la cita de hoy con el paciente ya elegido: ahí se pide la
                    razón de la consulta y se arma la cola del día.
                </p>
            </x-ui.tarjeta>
        @endunless

        <div class="d-flex justify-content-end gap-2 mb-3">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlCancelar() }}">Cancelar</a>
            <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
        </div>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/paciente-formulario.js') }}"></script>
@endsection
