@extends('layouts.app')

@section('title', 'Baja cancelada · Doctorísimo')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <article class="legal-documento legal-documento-angosto">
            <h1>Baja cancelada</h1>

            @if ($solicitud === null)
                <div class="legal-aviso legal-aviso-error">
                    <strong>Este enlace no sirve.</strong>
                    Puede que la baja ya se haya ejecutado o que el enlace esté incompleto.
                </div>
            @elseif ($solicitud->estado === \App\Models\SolicitudDeBaja::EJECUTADA)
                <div class="legal-aviso legal-aviso-error">
                    <strong>La baja ya se ejecutó.</strong>
                    Esa cuenta y sus datos se eliminaron, así que no hay nada que cancelar.
                </div>
            @else
                <p>
                    Listo: <strong>la baja quedó cancelada</strong> y tu cuenta de
                    {{ config('legal.app') }} sigue activa, con todos sus datos.
                </p>
                <p class="legal-nota">
                    Puedes volver a pedirla cuando quieras desde
                    <a href="{{ route('legal.baja.formulario') }}">la página de eliminación de cuenta</a>.
                </p>
            @endif

            <p class="legal-pie-documento">
                <a href="{{ route('welcome') }}">Volver a Doctorísimo</a>
            </p>
        </article>
    </div>
@endsection
