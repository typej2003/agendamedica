{{-- **El listado de consultas por rango** (WEB-6.8): los *Indicadores de Consultas* del escritorio
     (`w_reporte_consulta_barbaro.srw`). Una fila por consulta; los filtros son el rango de fechas **de la consulta**,
     la edad y —opcionales, escritos— el motivo, el diagnóstico, el medicamento y el antecedente. Es parte de
     `index`: el encabezado y el botón de imprimir los pone él. --}}
<x-ui.barra-herramientas etiqueta="Filtros del listado">
    <form method="GET" action="{{ $vm->urlBase() }}" class="listados-filtros" role="search" aria-label="Filtrar el listado">
        {{-- Un GET reemplaza la barra de la dirección: la clave del listado viaja en un campo oculto. --}}
        <input type="hidden" name="listado" value="{{ $vm->claveDelListado() }}">
        <div class="campo">
            <label class="form-label" for="listados-desde">Consultas desde</label>
            <input type="date" class="form-control" id="listados-desde" name="desde" value="{{ $vm->desde() }}">
        </div>
        <div class="campo">
            <label class="form-label" for="listados-hasta">Consultas hasta</label>
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
            <label class="form-label" for="listados-motivo">Motivo de consulta</label>
            <input type="text" class="form-control" id="listados-motivo" name="motivo" maxlength="60"
                   value="{{ $vm->motivo() }}" autocomplete="off">
        </div>
        <div class="campo">
            <label class="form-label" for="listados-diagnostico">Diagnóstico</label>
            <input type="text" class="form-control" id="listados-diagnostico" name="diagnostico" maxlength="60"
                   value="{{ $vm->diagnostico() }}" autocomplete="off">
        </div>
        <div class="campo">
            <label class="form-label" for="listados-medicamento">Medicamento</label>
            <input type="text" class="form-control" id="listados-medicamento" name="medicamento" maxlength="60"
                   value="{{ $vm->medicamento() }}" autocomplete="off">
        </div>
        <div class="campo">
            <label class="form-label" for="listados-antecedente">Antecedente</label>
            <input type="text" class="form-control" id="listados-antecedente" name="antecedente" maxlength="60"
                   value="{{ $vm->antecedente() }}" autocomplete="off">
        </div>
        <div class="listados-filtros-botones">
            <button class="btn btn-primary">Filtrar</button>
            @if ($vm->hayFiltro())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlBase() }}">Limpiar</a>
            @endif
        </div>
        <p class="texto-tenue small listados-filtros-ayuda" id="listados-ayuda">
            <i class="bi bi-info-circle" aria-hidden="true"></i> {{ $vm->notaDeLaBusqueda() }}
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
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todas las consultas</a>
                </x-slot>
            @endif
        </x-ui.vacio>
    @else
        {{-- Las columnas de listas (una por renglón en la celda): el antecedente sólo si se filtró por uno. --}}
        @php $listas = $vm->columnasDeListas(); @endphp
        <div class="listados-tabla-ancha">
        <x-ui.tabla>
            <thead>
            <tr>
                <th># Hist.</th>
                <th>Paciente</th>
                <th>Edad</th>
                <th>Fecha</th>
                @foreach ($listas as $etiqueta)
                    <th>{{ $etiqueta }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($vm->filas() as $fila)
                <tr>
                    <td data-etiqueta="# Hist.">
                        @if ($fila['historia']) {{ $fila['historia'] }} @else <span class="texto-tenue">—</span> @endif
                    </td>
                    <td class="celda-principal" data-etiqueta="Paciente">
                        <x-ui.identidad :nombre="$fila['nombre']" :href="$fila['url']" />
                    </td>
                    <td data-etiqueta="Edad">
                        @if ($fila['edad']) {{ $fila['edad'] }} @else <span class="texto-tenue">—</span> @endif
                    </td>
                    <td data-etiqueta="Fecha">{{ $fila['fecha'] }}</td>
                    @foreach ($listas as $clave => $etiqueta)
                        <td data-etiqueta="{{ $etiqueta }}">
                            @forelse ($fila[$clave] as $texto)
                                <div>{{ $texto }}</div>
                            @empty
                                <span class="texto-tenue">—</span>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </x-ui.tabla>
        </div>
    @endif
</x-ui.tarjeta>
