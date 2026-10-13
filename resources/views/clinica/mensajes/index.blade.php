@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- Mensajes a pacientes (WEB-8.4): los dos editores de plantillas (recordatorio de cita y saludo de cumpleaños). Cada uno se guarda con
         su botón; la configuración del envío automático (interruptor, canal y hora) sigue en Cumpleaños. El script lee los `data-*`
         (public/js/clinica/plantillas-mensaje.js). --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlCumpleanos() }}">
                <i class="bi bi-balloon" aria-hidden="true"></i> Cumpleaños y envío automático
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    @if ($vm->sinRegistro())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>Esta cuenta no tiene un número de registro médico principal, así que no hay dónde guardar las plantillas. Pídele a la administración que lo configure.</span>
        </div>
    @endif

    @if ($vm->avisoDeRegistro())
        <div class="alert alert-info d-flex align-items-start gap-2" role="status">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>{{ $vm->avisoDeRegistro() }}</span>
        </div>
    @endif

    @foreach ($vm->editores() as $editor)
        @include('clinica.mensajes._editor', ['editor' => $editor])
    @endforeach

    <p class="texto-tenue small d-flex align-items-start gap-2">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>{{ $vm->notaDeWhatsapp() }}</span>
    </p>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/plantillas-mensaje.js') }}"></script>
@endsection
