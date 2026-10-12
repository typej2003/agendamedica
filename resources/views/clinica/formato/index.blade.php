@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            {{-- «Imprimir prueba» imprime la hoja de ejemplo que ya está en la vista previa (el script llama a su `print()`);
                 «Abrir la hoja» es la misma página completa, con su barra, en otra pestaña. --}}
            <a class="btn btn-outline-secondary" href="{{ $vm->urlVistaPreviaCompleta() }}" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir la hoja
            </a>
            <button type="button" class="btn btn-outline-secondary" data-imprimir-prueba>
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir prueba
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    @if ($vm->sinRegistro())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>Esta cuenta no tiene un número de registro médico principal, así que no hay dónde guardar el formato. Pídele a la administración que lo configure.</span>
        </div>
    @endif

    @if ($vm->avisoDeRegistro())
        <div class="alert alert-info d-flex align-items-start gap-2" role="status">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>{{ $vm->avisoDeRegistro() }}</span>
        </div>
    @endif

    <div class="row g-3" data-formato-impresion>
        {{-- La vista previa va primero en el HTML (en el celular se ve arriba) y a la derecha en pantallas anchas. --}}
        <div class="col-12 col-xl-7 order-xl-2">
            @include('clinica.formato._previa')
        </div>

        <div class="col-12 col-xl-5 order-xl-1 formato-secciones">
            @include('clinica.formato._membrete')
            @include('clinica.formato._logo')
            @include('clinica.formato._estilo')
            @include('clinica.formato._firma')
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/formato-impresion.js') }}"></script>
@endsection
