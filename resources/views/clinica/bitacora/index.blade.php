@extends('clinica.layout')

@section('titulo', 'Bitácora')

@section('contenido')
    {{-- Bitácora (WEB-8.5): qué cambió en el consultorio y quién lo hizo. De sólo lectura: no hay acción primaria, los filtros están en la
         barra de herramientas. No muestra el contenido clínico, sólo qué se tocó y quién lo hizo. --}}
    <x-ui.page-head titulo="Bitácora" :subtitulo="$vm->subtitulo()" />

    <x-ui.barra-herramientas etiqueta="Filtros de la bitácora">
        <form method="GET" action="{{ $vm->urlBase() }}" class="bitacora-filtros" role="search" aria-label="Filtrar la bitácora">
            <div class="campo">
                <label class="form-label" for="bitacora-desde">Desde</label>
                <input type="date" class="form-control" id="bitacora-desde" name="desde" value="{{ $vm->desde() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="bitacora-hasta">Hasta</label>
                <input type="date" class="form-control" id="bitacora-hasta" name="hasta" value="{{ $vm->hasta() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="bitacora-usuario">Quién</label>
                <select class="form-select" id="bitacora-usuario" name="usuario">
                    @foreach ($vm->opcionesDeUsuario() as $opcion)
                        <option value="{{ $opcion['valor'] }}" {{ $vm->usuarioElegido() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label class="form-label" for="bitacora-tipo">Tipo de dato</label>
                <select class="form-select" id="bitacora-tipo" name="tipo">
                    <option value="">Todos</option>
                    @foreach ($vm->opcionesDeTipo() as $familia => $tipos)
                        <optgroup label="{{ $familia }}">
                            @foreach ($tipos as $clave => $etiqueta)
                                <option value="{{ $clave }}" {{ $vm->tipoElegido() === $clave ? 'selected' : '' }}>{{ $etiqueta }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="campo bitacora-filtro-paciente">
                <label class="form-label" for="bitacora-paciente">Paciente</label>
                <input type="search" class="form-control" id="bitacora-paciente" name="paciente" value="{{ $vm->paciente() }}"
                       placeholder="Nombre, cédula o historia" aria-describedby="bitacora-paciente-ayuda" autocomplete="off">
            </div>
            <div class="bitacora-filtros-botones">
                <button class="btn btn-primary">Filtrar</button>
                @if ($vm->hayFiltro())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlBase() }}">Limpiar</a>
                @endif
            </div>
            <p class="texto-tenue small bitacora-filtros-ayuda" id="bitacora-paciente-ayuda">
                <i class="bi bi-info-circle" aria-hidden="true"></i> {{ $vm->ayudaDelPaciente() }}
            </p>
        </form>
    </x-ui.barra-herramientas>

    @if ($vm->avisoDeLimite())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="status">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <span>{{ $vm->avisoDeLimite() }}</span>
        </div>
    @endif

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                @if ($vm->hayFiltro())
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todos los cambios</a>
                    </x-slot>
                @endif
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Cuándo</th>
                    <th>Quién</th>
                    <th>Qué</th>
                    <th>Paciente</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->lineas() as $linea)
                    <tr>
                        <td class="celda-principal" data-etiqueta="Cuándo">
                            <time title="{{ $linea['cuandoCompleto'] }}">{{ $linea['cuando'] }}</time>
                        </td>
                        <td data-etiqueta="Quién">
                            @if ($linea['quien']['sinUsuario'])
                                <span class="texto-tenue">{{ $linea['quien']['nombre'] }}</span>
                            @else
                                <strong>{{ $linea['quien']['nombre'] }}</strong>
                            @endif
                            @if ($linea['quien']['rol'])
                                <span class="d-block texto-tenue small">{{ $linea['quien']['rol'] }}</span>
                            @endif
                        </td>
                        <td data-etiqueta="Qué">
                            <strong>{{ $linea['accion'] }}</strong>
                            @if ($linea['detalle'])
                                <span class="d-block texto-tenue small">{{ $linea['detalle'] }}</span>
                            @endif
                        </td>
                        <td data-etiqueta="Paciente">
                            @if ($linea['paciente'])
                                <a href="{{ $linea['urlPaciente'] }}">{{ $linea['paciente'] }}</a>
                                @if ($linea['urlConsulta'])
                                    <a class="d-block small" href="{{ $linea['urlConsulta'] }}">Abrir la consulta</a>
                                @endif
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>

    @unless ($vm->estaVacio())
        <div class="lista-pie">
            <span class="texto-tenue small">{{ $vm->rango() }}</span>
            @if ($vm->hayAnterior() || $vm->hayMas())
                <nav class="bitacora-paginas" aria-label="Páginas de la bitácora">
                    @if ($vm->hayAnterior())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlMasRecientes() }}" rel="prev">
                            <i class="bi bi-arrow-left" aria-hidden="true"></i> Más recientes
                        </a>
                    @endif
                    @if ($vm->hayMas())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlMasAntiguas() }}" rel="next">
                            Más antiguas <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </nav>
            @endif
        </div>
    @endunless
@endsection
