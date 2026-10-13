@extends('verificacion.layout')

@section('titulo', $vm->resultado()['titulo'])

@section('contenido')
    @php($resultado = $vm->resultado())

    {{-- El resultado: color + icono + texto (nunca sólo color), lo primero que lee la farmacia. --}}
    <section class="verif-resultado verif-resultado-{{ $resultado['tono'] }}" role="status" data-estado="{{ $vm->estado() }}">
        @include('verificacion._icono', ['nombre' => $resultado['icono']])
        <div>
            <h1 class="verif-resultado-titulo">{{ $resultado['titulo'] }}</h1>
            <p class="verif-resultado-texto">{{ $resultado['explicacion'] }}</p>
        </div>
    </section>

    @if ($vm->datos() !== [])
        <section class="verif-tarjeta" aria-labelledby="verif-recipe">
            <h2 class="verif-subtitulo" id="verif-recipe">El récipe</h2>
            <dl class="verif-datos">
                @foreach ($vm->datos() as $dato)
                    <div class="verif-dato">
                        <dt>{{ $dato['etiqueta'] }}</dt>
                        <dd>{{ $dato['valor'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($vm->medico())
        <section class="verif-tarjeta" aria-labelledby="verif-medico">
            <h2 class="verif-subtitulo" id="verif-medico">Médico que lo emitió</h2>
            <p class="verif-medico-nombre">{{ $vm->medico()['nombre'] }}</p>
            @if ($vm->medico()['detalles'] !== [])
                <dl class="verif-datos">
                    @foreach ($vm->medico()['detalles'] as $dato)
                        <div class="verif-dato">
                            <dt>{{ $dato['etiqueta'] }}</dt>
                            <dd>{{ $dato['valor'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    @if ($vm->medicamentos() !== [])
        <section class="verif-tarjeta" aria-labelledby="verif-medicamentos">
            <h2 class="verif-subtitulo" id="verif-medicamentos">Medicamentos</h2>
            <ol class="verif-medicamentos">
                @foreach ($vm->medicamentos() as $medicamento)
                    <li class="verif-medicamento">
                        <span class="verif-cantidad" title="Cantidad">{{ $medicamento['cantidad'] }}</span>
                        <span class="verif-medicamento-nombre">{{ $medicamento['nombre'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <p class="verif-nota">{{ $vm->nota() }}</p>

    <section class="verif-tarjeta verif-tarjeta-suave">
        <h2 class="verif-subtitulo">¿Otra hoja?</h2>
        @include('verificacion._buscar-codigo')
    </section>
@endsection
