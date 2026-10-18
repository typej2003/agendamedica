@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo() . ' · ' . $vm->nombre())

@section('estilos')
    {{-- La historia pediátrica (WEB-12.5): hoja A4 vertical con el membrete del médico; la imprime el navegador. Comparte la hoja del carnet de vacunación. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-carnet.css') }}">
@endsection

@unless ($vm->incrustado())
    @section('barra')
        {{-- Esta barra no se imprime. --}}
        <div class="barra-impresion-texto">
            <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; Ficha del paciente</a>
            <h1 class="barra-impresion-titulo">{{ $vm->titulo() }}</h1>
            <p class="barra-impresion-detalle">{{ $vm->resumen() }}</p>
        </div>

        <div class="barra-impresion-acciones">
            <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
        </div>
    @endsection
@endunless

@section('contenido')
    <main class="carnet-hoja">
        <x-impresion.membrete :membrete="$vm->membrete()" />
        <hr class="carnet-linea carnet-linea-{{ $vm->membrete()->colorLinea }}">

        <h2 class="carnet-titulo">HISTORIA PEDIÁTRICA</h2>

        <dl class="carnet-datos">
            @foreach ($vm->datos() as $dato)
                <div><dt>{{ $dato['rotulo'] }}:</dt><dd>{{ $dato['valor'] }}</dd></div>
            @endforeach
            @if ($vm->alergia())
                <div><dt>Alergias:</dt><dd><strong>{{ $vm->alergia() }}</strong></dd></div>
            @endif
        </dl>

        @if ($vm->familia() !== [])
            <section class="carnet-bloque" id="historia-familia">
                <h3 class="carnet-subtitulo">FAMILIA</h3>
                <dl class="carnet-datos">
                    @foreach ($vm->familia() as $dato)
                        <div><dt>{{ $dato['rotulo'] }}:</dt><dd>{{ $dato['valor'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif

        <section class="carnet-bloque" id="historia-antecedentes">
            <h3 class="carnet-subtitulo">ANTECEDENTES</h3>
            @forelse ($vm->antecedentes() as $grupo)
                <p class="carnet-grupo">{{ $grupo['titulo'] }}</p>
                <ul class="carnet-lista">
                    @foreach ($grupo['filas'] as $fila)
                        <li>
                            {{ $fila['nombre'] }}
                            @if ($fila['detalle'])
                                — {{ $fila['detalle'] }}
                            @endif
                            @if ($fila['alerta'])
                                <strong>(ALERTA)</strong>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="carnet-vacio">Sin antecedentes registrados.</p>
            @endforelse
        </section>

        <section class="carnet-bloque" id="historia-mediciones">
            <h3 class="carnet-subtitulo">MEDICIONES</h3>
            @if ($vm->mediciones() === [])
                <p class="carnet-vacio">Todavía no hay mediciones.</p>
            @else
                <table class="carnet-tabla">
                    <thead>
                        <tr>@foreach ($vm->columnasDeMediciones() as $columna)<th>{{ $columna }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->mediciones() as $fila)
                            <tr>
                                <td>{{ $fila['fecha'] }}</td><td>{{ $fila['edad'] }}</td>
                                @foreach ($fila['celdas'] as $celda)
                                    <td>
                                        {{ $celda['valor'] }}
                                        @if ($celda['posicion'])
                                            <br><small>{{ $celda['posicion'] }}</small>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="carnet-bloque" id="historia-vacunas">
            <h3 class="carnet-subtitulo">VACUNAS APLICADAS</h3>
            @if ($vm->vacunasAplicadas() === [])
                <p class="carnet-vacio">Todavía no se le registró ninguna dosis.</p>
            @else
                <table class="carnet-tabla">
                    <thead><tr><th>Fecha</th><th>Vacuna</th><th>Dosis</th><th>Lote</th></tr></thead>
                    <tbody>
                        @foreach ($vm->vacunasAplicadas() as $fila)
                            <tr><td>{{ $fila['fecha'] }}</td><td>{{ $fila['vacuna'] }}</td><td>{{ $fila['dosis'] }}</td><td>{{ $fila['lote'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <x-impresion.firma :membrete="$vm->membrete()" />
        <x-impresion.pie :membrete="$vm->membrete()" />
    </main>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
