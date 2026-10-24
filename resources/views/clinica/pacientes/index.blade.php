@extends('clinica.layout')

@section('titulo', 'Pacientes')

@section('contenido')
    <x-ui.page-head titulo="Pacientes" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ route('clinica.pacientes.nuevo') }}">
                <i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo paciente
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- El buscador y el filtro "Sin historia" (WEB-3.3) van en su barra, no en el encabezado: el filtro es lo que la
         secretaría necesita para ir a llenar las que faltan, y el conteo de cada segmento sale del ViewModel y respeta
         la búsqueda escrita. --}}
    <x-ui.barra-herramientas>
        <x-ui.buscador :agregar-url="route('clinica.pacientes.nuevo')" agregar-texto="Nuevo paciente" data-buscar-filas=".identidad-nombre"
                       :accion="route('clinica.pacientes')" :valor="$vm->buscar()"
                       :limpiar="$vm->urlLimpiarBusqueda()"
                       placeholder="Nombre, cédula o n.º de historia" etiqueta="Buscar paciente">
            {{-- Buscar dentro del segmento en el que se está: si no, escribir en el buscador
                 devolvería a "Todos" sin que nadie lo haya pedido. --}}
            @if ($vm->hayFiltro())
                <input type="hidden" name="historia" value="{{ $vm->filtro() }}">
            @endif
        </x-ui.buscador>
        <x-slot name="filtros">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Filtrar por historia clínica" />
        </x-slot>
    </x-ui.barra-herramientas>

    <div data-buscar-resultados>
    {{-- La corrección de errores de tecleo (PecleSearch) se avisa y se puede deshacer: el listado busca
         con el término corregido y este aviso ofrece buscar lo escrito tal cual (`?literal=1`). Va dentro
         de la región que reemplaza la búsqueda en vivo, para que aparezca y desaparezca con ella. --}}
    @if ($vm->hayCorreccion())
        <p class="small texto-tenue mb-2" data-busqueda-corregida>
            Se buscó «{{ $vm->terminoBuscado() }}» porque «{{ $vm->buscar() }}» parece un error de tecleo.
            <a href="{{ $vm->urlBuscarLoEscrito() }}">Buscar «{{ $vm->buscar() }}» tal cual</a>.
        </p>
    @endif
    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                @if ($vm->hayBusqueda() || $vm->hayFiltro())
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlTodos() }}">Ver todos los pacientes</a>
                    </x-slot>
                @endif
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Paciente</th>
                    <th>Historia</th>
                    <th>Edad</th>
                    <th>Contacto</th>
                    <th><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <x-ui.identidad :nombre="$fila['nombre']" :detalle="$fila['cedula']" :href="$fila['url']" />
                        </td>
                        <td data-etiqueta="Historia">
                            <x-ui.estado :tono="$fila['historia']['tono']" :icono="$fila['historia']['icono']">{{ $fila['historia']['texto'] }}</x-ui.estado>
                        </td>
                        <td data-etiqueta="Edad" @class(['celda-vacia' => ! $fila['edad']])>
                            @if ($fila['edad'])
                                <span>{{ $fila['edad'] }}</span>
                                <span class="texto-tenue small d-block">{{ $fila['nacimiento'] }}</span>
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td data-etiqueta="Contacto" @class(['celda-vacia' => ! ($fila['telefono'] || $fila['correo'])])>
                            @if ($fila['telefono'] || $fila['correo'])
                                @if ($fila['telefono'])
                                    <span class="d-block"><i class="bi bi-telephone texto-tenue" aria-hidden="true"></i> {{ $fila['telefono'] }}</span>
                                @endif
                                @if ($fila['correo'])
                                    <span class="d-block small texto-tenue text-break"><i class="bi bi-envelope" aria-hidden="true"></i> {{ $fila['correo'] }}</span>
                                @endif
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ $fila['url'] }}">
                                Ver ficha <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
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
            @if ($vm->haySobrantes())
                {{ $vm->paginador()->links() }}
            @endif
        </div>
    @endunless
    </div>
@endsection
