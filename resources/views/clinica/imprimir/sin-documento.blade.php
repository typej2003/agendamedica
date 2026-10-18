@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

{{-- La página del documento que no existe (O-16): el respaldo de «guardar y después imprimir». No es un 404 —la consulta y el
     documento son válidos— sino el aviso de que falta el dato, con la vuelta a la sección donde se carga. No lleva hoja: no se
     imprime nada. --}}
@section('estilos')
    <link rel="stylesheet" href="{{ asset('css/clinica-impresion.css') }}">
    <style>
        .sin-documento { max-width: 46rem; margin: 4rem auto; padding: 0 1rem; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .sin-documento-caja { padding: 2rem; border: 1px solid #d8d4e4; border-radius: 14px; background: #fff; }
        .sin-documento-titulo { margin: 0 0 .5rem; font-size: 1.35rem; }
        .sin-documento-detalle { margin: 0 0 1rem; color: #4a4459; }
        .sin-documento-mensaje { margin: 0 0 1.5rem; padding: .85rem 1rem; border-left: 4px solid #783080; border-radius: 6px; background: #f5edf7; font-weight: 600; }
        .sin-documento-volver { display: inline-block; min-height: 44px; padding: .6rem 1.1rem; border: 1px solid #783080; border-radius: 10px; color: #783080; font-weight: 600; text-decoration: none; }
        .sin-documento-volver:hover { background: #f5edf7; }
    </style>
@endsection

@section('contenido')
    <main class="sin-documento">
        <div class="sin-documento-caja">
            <h1 class="sin-documento-titulo">{{ $vm->titulo() }}</h1>
            <p class="sin-documento-detalle">{{ $vm->detalle() }}</p>
            <p class="sin-documento-mensaje" role="status">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $vm->mensaje() }}
            </p>
            <a class="sin-documento-volver" href="{{ $vm->urlVolver() }}">&larr; {{ $vm->textoVolver() }}</a>
        </div>
    </main>
@endsection
