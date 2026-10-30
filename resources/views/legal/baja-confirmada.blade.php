@extends('layouts.app')

@section('title', 'Baja confirmada · Doctorísimo')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}">
@endpush

@section('content')
    <div class="container legal">
        <article class="legal-documento legal-documento-angosto">
            <h1>Baja confirmada</h1>

            @if ($solicitud === null)
                <div class="legal-aviso legal-aviso-error">
                    <strong>Este enlace no sirve.</strong>
                    Puede que ya lo hayas usado o que la baja se haya cancelado. Si todavía quieres eliminar tu
                    cuenta, <a href="{{ route('legal.baja.formulario') }}">vuelve a pedirla</a>.
                </div>
            @else
                <p>
                    Tu cuenta de <strong>{{ config('legal.app') }}</strong> quedó programada para eliminarse el
                    <strong>{{ $solicitud->ejecutar_despues_de?->translatedFormat('j \d\e F \d\e Y') }}</strong>.
                </p>
                <p>
                    Tienes <strong>{{ $dias }} días</strong> para arrepentirte. Se cancela sola si
                    <strong>inicias sesión</strong> en la web o en la app antes de esa fecha, o con este botón:
                </p>

                <form method="POST" action="{{ route('legal.baja.cancelar', $solicitud->token) }}" class="legal-formulario">
                    @csrf
                    <button type="submit" class="legal-boton legal-boton-secundario">Cancelar la baja</button>
                </form>

                <p class="legal-nota">
                    No te vamos a pedir que expliques nada. Si dejas pasar la fecha, la cuenta y los datos
                    asociados se eliminan y no se pueden recuperar.
                </p>
            @endif

            <p class="legal-pie-documento">
                <a href="{{ route('welcome') }}">Volver a Doctorísimo</a>
            </p>
        </article>
    </div>
@endsection
