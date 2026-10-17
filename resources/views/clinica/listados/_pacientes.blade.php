{{-- **El listado de pacientes por rango** (WEB-6.8): los *Indicadores pacientes* del escritorio
     (`w_reprotes_pacientes.srw`). Los filtros son los del reporte —ingreso desde/hasta, edad, sexo, estado civil y
     procedencia— y la tabla, sus ocho columnas, sin totales (el legado no los tiene). Es parte de `index`: el
     encabezado y el botón de imprimir los pone él. --}}
<x-ui.barra-herramientas etiqueta="Filtros del listado">
    <form method="GET" action="{{ $vm->urlBase() }}" class="listados-filtros" role="search" aria-label="Filtrar el listado">
        {{-- Un GET reemplaza la barra de la dirección: la clave del listado viaja en un campo oculto. --}}
        <input type="hidden" name="listado" value="{{ $vm->claveDelListado() }}">
        <div class="campo">
            <label class="form-label" for="listados-desde">Ingreso desde</label>
            <input type="date" class="form-control" id="listados-desde" name="desde" value="{{ $vm->desde() }}">
        </div>
        <div class="campo">
            <label class="form-label" for="listados-hasta">Ingreso hasta</label>
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
            <label class="form-label" for="listados-sexo">Sexo</label>
            <select class="form-select" id="listados-sexo" name="sexo">
                @foreach ($vm->opcionesDeSexo() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label class="form-label" for="listados-estado-civil">Estado civil</label>
            <select class="form-select" id="listados-estado-civil" name="estado_civil">
                @foreach ($vm->opcionesDeEstadoCivil() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label class="form-label" for="listados-procedencia">Procedencia</label>
            <select class="form-select" id="listados-procedencia" name="procedencia">
                @foreach ($vm->opcionesDeProcedencia() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="listados-filtros-botones">
            <button class="btn btn-primary">Filtrar</button>
            @if ($vm->hayFiltro())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlBase() }}">Limpiar</a>
            @endif
        </div>
        <p class="texto-tenue small listados-filtros-ayuda" id="listados-ayuda">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            El rango es el de la <strong>fecha de ingreso</strong> del paciente. La edad se cuenta en años cumplidos hoy;
            un paciente sin fecha de nacimiento no aparece mientras se filtre por edad.
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
            @if ($vm->hayFiltro())
                <x-slot name="acciones">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todos los pacientes</a>
                </x-slot>
            @endif
        </x-ui.vacio>
    @else
        <x-ui.tabla>
            <thead>
            <tr>
                <th># Hist.</th>
                <th>Paciente</th>
                <th>Cédula</th>
                <th>Edad</th>
                <th>E. civil</th>
                <th>Sexo</th>
                <th>F. ingreso</th>
                <th>Empresa</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($vm->filas() as $fila)
                <tr>
                    <td data-etiqueta="# Hist." @class(['celda-vacia' => ! $fila['historia']])>
                        @if ($fila['historia']) {{ $fila['historia'] }} @else <span class="texto-tenue">—</span> @endif
                    </td>
                    <td class="celda-principal" data-etiqueta="Paciente">
                        <x-ui.identidad :nombre="$fila['nombre']" :href="$fila['url']" />
                    </td>
                    @foreach ([
                        'Cédula' => 'cedula', 'Edad' => 'edad', 'E. civil' => 'ecivil', 'Sexo' => 'sexo',
                    ] as $etiqueta => $clave)
                        <td data-etiqueta="{{ $etiqueta }}" @class(['celda-vacia' => ! $fila[$clave]])>
                            @if ($fila[$clave]) {{ $fila[$clave] }} @else <span class="texto-tenue">—</span> @endif
                        </td>
                    @endforeach
                    <td data-etiqueta="F. ingreso">{{ $fila['fingreso'] }}</td>
                    <td data-etiqueta="Empresa" @class(['celda-vacia' => ! $fila['empresa']])>
                        @if ($fila['empresa']) {{ $fila['empresa'] }} @else <span class="texto-tenue">—</span> @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-ui.tabla>
    @endif
</x-ui.tarjeta>

@unless ($vm->estaVacio())
    <p class="texto-tenue small mt-2">
        <i class="bi bi-info-circle" aria-hidden="true"></i> {{ $vm->notaDeEmpresa() }}
    </p>
@endunless
