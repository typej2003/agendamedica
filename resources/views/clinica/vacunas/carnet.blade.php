@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo() . ' · ' . $vm->nombre())

@section('estilos')
    {{-- El carnet de vacunación (WEB-7.4.4c): hoja A4 vertical con el membrete del médico; lo imprime el navegador. Usa el membrete, la firma y el
         pie de `clinica-impresion.css` (los mismos de los récipes) y sólo agrega su propia hoja de estilos. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-carnet.css') }}">
@endsection

@unless ($vm->incrustado())
    @section('barra')
        {{-- Esta barra no se imprime. --}}
        <div class="barra-impresion-texto">
            <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; Calendario de vacunas</a>
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

        <h2 class="carnet-titulo">VACUNAS APLICADAS</h2>

        <dl class="carnet-datos">
            @foreach ($vm->datos() as $dato)
                <div><dt>{{ $dato['rotulo'] }}:</dt><dd>{{ $dato['valor'] }}</dd></div>
            @endforeach
        </dl>

        <section class="carnet-bloque">
            @if ($vm->aplicadas() === [])
                <p class="carnet-vacio">Todavía no se le registró ninguna dosis.</p>
            @else
                <table class="carnet-tabla" id="carnet-aplicadas">
                    <thead>
                        <tr>
                            <th>Fecha</th><th>Vacuna</th><th>Dosis</th><th>Edad</th><th>Lote</th><th>Vía / zona</th><th>Próxima dosis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->aplicadas() as $fila)
                            <tr>
                                <td>{{ $fila['fecha'] }}</td><td>{{ $fila['vacuna'] }}</td><td>{{ $fila['dosis'] }}</td><td>{{ $fila['edad'] }}</td>
                                <td>{{ $fila['lote'] }}</td><td>{{ $fila['via'] }}</td><td>{{ $fila['proxima'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        @if ($vm->pendientes() !== [])
            <section class="carnet-bloque">
                <h3 class="carnet-subtitulo">DOSIS POR APLICAR</h3>
                <table class="carnet-tabla" id="carnet-pendientes">
                    <thead>
                        <tr><th>Vacuna</th><th>Dosis</th><th>Le toca</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->pendientes() as $fila)
                            <tr>
                                <td>{{ $fila['vacuna'] }}</td><td>{{ $fila['dosis'] }}</td><td>{{ $fila['toca'] }}</td>
                                <td @if ($fila['atrasada']) class="carnet-atrasada" @endif>{{ $fila['estado'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <x-impresion.firma :membrete="$vm->membrete()" />
        <x-impresion.pie :membrete="$vm->membrete()" />
    </main>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
