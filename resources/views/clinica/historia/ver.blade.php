@extends('clinica.layout')

@section('titulo', 'Historia del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlFicha()" volver-texto="Ficha del paciente">
        <x-slot name="acciones">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlFicha() }}">
                <i class="bi bi-person-vcard" aria-hidden="true"></i> Ver la ficha
            </a>
            {{-- La acción primaria de una pantalla de sólo lectura es imprimir: es lo único que sale de acá.
                 Se abre aparte (`target="_blank"`) porque la vista de impresión es otra página, sin el
                 armazón de la web, como el listado del día de la agenda (WEB-2.7). Sin historia —o sin
                 consultas— no se ofrece: no hay resumen que imprimir. --}}
            @if ($vm->puedeImprimir())
                <a class="btn btn-primary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </a>
            @else
                <button type="button" class="btn btn-primary" disabled
                        title="Todavía no hay consultas que resumir">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </button>
            @endif
        </x-slot>
    </x-ui.page-head>

    @if (! $vm->tieneHistoria())
        {{-- Sin historia no hay nada que mostrar: no es un error ni una lista vacía, es un paciente al que
             todavía hay que numerarle la historia (WEB-3.3). El bloque dice por qué y lleva a la ficha,
             que es donde vive la acción. --}}
        @php $sinHistoria = $vm->sinHistoria(); @endphp
        <x-ui.tarjeta id="historia-sin-historia">
            <x-ui.vacio :icono="$sinHistoria['icono']" :titulo="$sinHistoria['titulo']">
                {{ $sinHistoria['texto'] }}
                <x-slot name="acciones">
                    <a class="btn btn-primary" href="{{ $vm->urlFicha() }}">
                        <i class="bi bi-journal-medical" aria-hidden="true"></i> Ir a la ficha a asignarla
                    </a>
                </x-slot>
            </x-ui.vacio>
        </x-ui.tarjeta>
    @else
        <x-ui.tarjeta id="historia-consultas" titulo="Consultas" icono="bi-journal-text" class="mb-3">
            @if ($vm->totalConsultas() === 0)
                @php $sinConsultas = $vm->sinConsultas(); @endphp
                <x-ui.vacio :icono="$sinConsultas['icono']" :titulo="$sinConsultas['titulo']">{{ $sinConsultas['texto'] }}</x-ui.vacio>
            @else
                {{-- La línea de tiempo de la ficha, con las secciones de cada consulta: es el mismo bloque
                     que el paciente ya conoce, ahora con lo que la consulta dice. --}}
                <ol class="linea-tiempo">
                    @foreach ($vm->consultas() as $consulta)
                        <li>
                            <div class="linea-tiempo-cabeza">
                                <strong>{{ $consulta['fecha'] }}</strong>
                                <span class="texto-tenue small">{{ $consulta['numero'] }}</span>
                            </div>

                            @if ($consulta['motivos'] !== [])
                                <p class="mb-1">
                                    @foreach ($consulta['motivos'] as $motivo)
                                        <span class="chip">{{ $motivo }}</span>
                                    @endforeach
                                </p>
                            @endif

                            @if ($consulta['enfermedad'])
                                <p class="linea-tiempo-texto mb-1">
                                    <span class="etiqueta">Enfermedad actual</span> {{ $consulta['enfermedad'] }}
                                </p>
                            @endif

                            @if ($consulta['diagnostico'])
                                <p class="linea-tiempo-texto mb-1">
                                    <span class="etiqueta">Diagnóstico</span> {{ $consulta['diagnostico'] }}
                                </p>
                            @endif

                            @if ($consulta['evolucion'])
                                <p class="linea-tiempo-texto mb-0">
                                    <span class="etiqueta">Evolución</span> {{ $consulta['evolucion'] }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.tarjeta>

        <x-ui.tarjeta id="historia-antecedentes" titulo="Antecedentes" icono="bi-clipboard2-pulse" class="mb-3">
            @if ($vm->totalAntecedentes() === 0)
                @php $sinAntecedentes = $vm->sinAntecedentes(); @endphp
                <x-ui.vacio :icono="$sinAntecedentes['icono']" :titulo="$sinAntecedentes['titulo']">{{ $sinAntecedentes['texto'] }}</x-ui.vacio>
            @else
                @foreach ($vm->antecedentes() as $grupo)
                    <div class="antecedentes-grupo">
                        <h3 class="antecedentes-titulo">{{ $grupo['titulo'] }}</h3>
                        <ul class="lista-antecedentes">
                            @foreach ($grupo['filas'] as $fila)
                                <li>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span>{{ $fila['nombre'] }}</span>
                                        {{-- La alerta del antecedente: el `tipo2 = 'A'` que la fuente del
                                             legado muestra en negrita y magenta arriba de la consulta. Va
                                             con color, icono y texto, nunca sólo color. --}}
                                        @if ($fila['alerta'])
                                            <x-ui.estado tono="peligro" icono="bi-exclamation-triangle" :title="$fila['alertaTitulo']">
                                                Alerta
                                            </x-ui.estado>
                                        @endif
                                    </div>
                                    @if ($fila['detalle'])
                                        <p class="texto-tenue small mb-0">{{ $fila['detalle'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        </x-ui.tarjeta>

        <x-ui.tarjeta id="historia-documentos" titulo="Documentos" icono="bi-folder2-open" sin-relleno>
            @if ($vm->totalDocumentos() === 0)
                @php $sinDocumentos = $vm->sinDocumentos(); @endphp
                <x-ui.vacio :icono="$sinDocumentos['icono']" :titulo="$sinDocumentos['titulo']">{{ $sinDocumentos['texto'] }}</x-ui.vacio>
            @else
                <x-ui.tabla>
                    <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Fecha</th>
                        <th>Resumen</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($vm->documentos() as $documento)
                        <tr>
                            <td class="celda-principal" data-etiqueta="Documento">
                                <i class="bi {{ $documento['icono'] }} texto-tenue" aria-hidden="true"></i>
                                <strong>{{ $documento['tipo'] }}</strong>
                                @if ($documento['consulta'])
                                    <span class="texto-tenue small d-block">{{ $documento['consulta'] }}</span>
                                @endif
                            </td>
                            <td data-etiqueta="Fecha">{{ $documento['fecha'] }}</td>
                            <td data-etiqueta="Resumen">{{ $documento['resumen'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-ui.tabla>
            @endif
        </x-ui.tarjeta>
    @endif
@endsection
