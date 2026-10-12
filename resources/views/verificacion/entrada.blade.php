@extends('verificacion.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- La entrada por código (WEB-5.6): para quien no pudo escanear el QR de la hoja. --}}
    <section class="verif-tarjeta">
        <h1 class="verif-titulo">Verificar un récipe</h1>
        <p class="verif-texto">
            Escanee el código QR de la hoja con la cámara del teléfono. Si no se lee, escriba aquí el código que aparece junto al QR
            (<strong>Verifique en {{ $vm->direccion() }}</strong>).
        </p>

        @include('verificacion._buscar-codigo')
    </section>

    <p class="verif-nota">{{ $vm->nota() }}</p>
@endsection
