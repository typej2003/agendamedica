@extends('clinica.layout')

@section('titulo', 'Listados')

@section('contenido')
    {{-- **Listados clínicos entre fechas** (WEB-6.8). Hoy están el de embarazos / partos —el
         *Listado de Partos* del escritorio—, el de pacientes por rango —*Indicadores pacientes*— y el de
         consultas por rango —*Indicadores de Consultas*—: la pantalla es la misma y el título sale del ViewModel. Es de sólo lectura: no hay alta, y lo único
         que se hace con el resultado es imprimirlo. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            @unless ($vm->estaVacio())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlImprimir() }}">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </a>
            @endunless
        </x-slot>
    </x-ui.page-head>

    {{-- El selector de listados: la entrada del menú es una sola, así que los listados se cambian acá. --}}
    @php
        $claveActual = $vm->claveDelListado();
        $listados = collect(\App\Clinica\Listados\ListadosDisponibles::claves())->map(fn ($clave) => [
            'texto'  => \App\Clinica\Listados\ListadosDisponibles::titulo($clave),
            'url'    => route('clinica.listados', ['listado' => $clave]),
            'activa' => $clave === $claveActual,
        ])->all();
    @endphp
    <x-ui.segmentado class="mb-3" :opciones="$listados" etiqueta="Listados" />

    {{-- Cada listado trae sus filtros y su tabla: el de pacientes por rango vive en su parcial; el de
         embarazos / partos, que es el de por defecto, sigue acá abajo. --}}
    @if ($vm->claveDelListado() === \App\Clinica\Listados\ListadosDisponibles::PACIENTES)
        @include('clinica.listados._pacientes')
    @elseif ($vm->claveDelListado() === \App\Clinica\Listados\ListadosDisponibles::CONSULTAS)
        @include('clinica.listados._consultas')
    @else

    {{-- Los filtros van en la barra de herramientas y no en el encabezado (guía de UI §3). Los campos son los
         del reporte del escritorio: el rango de fechas (que es de **FPP**, como el legado), las edades y el
         modo "Por semanas" con su semana. --}}
    <x-ui.barra-herramientas etiqueta="Filtros del listado">
        <form method="GET" action="{{ $vm->urlBase() }}" class="listados-filtros" role="search" aria-label="Filtrar el listado">
            {{-- Un GET reemplaza la barra de la dirección: la clave del listado viaja en un campo oculto para
                 que filtrar no vuelva al listado por defecto. --}}
            <input type="hidden" name="listado" value="{{ $vm->claveDelListado() }}">
            <div class="campo">
                <label class="form-label" for="listados-desde">Desde</label>
                <input type="date" class="form-control" id="listados-desde" name="desde" value="{{ $vm->desde() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="listados-hasta">Hasta</label>
                <input type="date" class="form-control" id="listados-hasta" name="hasta" value="{{ $vm->hasta() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="listados-edad-inicial">Edad desde</label>
                <input type="number" min="0" max="120" class="form-control" id="listados-edad-inicial"
                       name="edad_inicial" value="{{ $vm->edadInicial() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="listados-edad-final">Edad hasta</label>
                <input type="number" min="0" max="120" class="form-control" id="listados-edad-final"
                       name="edad_final" value="{{ $vm->edadFinal() }}">
            </div>
            <div class="campo">
                <label class="form-label" for="listados-semana">Semana de gestación</label>
                <input type="number" min="1" max="40" class="form-control" id="listados-semana"
                       name="semana" value="{{ $vm->semanaPedida() }}">
            </div>
            <div class="campo listados-filtro-semanas">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="listados-por-semanas" name="por_semanas"
                           value="1" @if ($vm->porSemanas()) checked @endif>
                    <label class="form-check-label" for="listados-por-semanas">Por semanas</label>
                </div>
            </div>
            <div class="listados-filtros-botones">
                <button class="btn btn-primary">Filtrar</button>
                @if ($vm->hayFiltro())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlBase() }}">Limpiar</a>
                @endif
            </div>
            <p class="texto-tenue small listados-filtros-ayuda" id="listados-ayuda">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Las fechas son las de la <strong>FPP</strong> (la FUR más 283 días), como el reporte del escritorio.
                Con <strong>Por semanas</strong> puesto, las fechas no se usan y se listan las gestas de esa semana exacta.
            </p>
        </form>
    </x-ui.barra-herramientas>

    @if ($vm->aviso())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="status">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <span>{{ $vm->aviso() }}</span>
        </div>
    @endif

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                @if ($vm->hayFiltro() && ! $vm->semanaInvalida())
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todos los embarazos</a>
                    </x-slot>
                @endif
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Paciente</th>
                    <th>F. ingreso</th>
                    <th>Edad</th>
                    <th>F.U.R.</th>
                    <th>F.P.P.</th>
                    <th>Procedencia</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal" data-etiqueta="Paciente">
                            <x-ui.identidad :nombre="$fila['nombre']" :detalle="$fila['historia']" :href="$fila['url']" />
                        </td>
                        <td data-etiqueta="F. ingreso">{{ $fila['fingreso'] }}</td>
                        <td data-etiqueta="Edad" @class(['celda-vacia' => ! $fila['edad']])>
                            @if ($fila['edad'])
                                {{ $fila['edad'] }}
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td data-etiqueta="F.U.R.">{{ $fila['fur'] }}</td>
                        <td data-etiqueta="F.P.P.">{{ $fila['fpp'] }}</td>
                        <td data-etiqueta="Procedencia">
                            @if ($fila['procedencia'])
                                {{ $fila['procedencia'] }}
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
        <p class="texto-tenue small mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i> {{ $vm->notaDeProcedencia() }}
        </p>
    @endunless
    @endif
@endsection
