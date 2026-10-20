@extends('clinica.layout')

@section('titulo', 'Tratamientos')

@section('contenido')
    {{-- Tratamientos del catálogo (WEB-8.1a): las plantillas de récipe del médico. Una sola acción primaria (Nuevo
         tratamiento); alta y edición son el editor, en su propia pantalla. --}}
    <x-ui.page-head titulo="Tratamientos" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo tratamiento
            </a>
        </x-slot>
    </x-ui.page-head>

    @include('clinica.catalogos._novedades-iniciales')

    <x-ui.barra-herramientas>
        <x-ui.buscador :agregar-url="$vm->urlNuevo()" agregar-texto="Crear" :accion="$vm->urlBase()" :valor="$vm->buscar()" placeholder="Nombre del tratamiento" etiqueta="Buscar tratamiento" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <div data-buscar-resultados>
    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlBase() }}">Ver todos los tratamientos</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer tratamiento
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-tratamientos">
                <thead>
                <tr>
                    <th>Tratamiento</th>
                    <th>Medicamentos</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila (pauta de Alexander): el código sale del listado y queda en el `title` del
                             nombre y en el editor del tratamiento. --}}
                        <td class="celda-principal" title="Código {{ $fila['codigo'] }}">
                            <a class="texto-una-linea" href="{{ $fila['urlEditar'] }}"><strong>{{ $fila['nombre'] }}</strong></a>
                        </td>
                        <td data-etiqueta="Medicamentos">
                            @if ($fila['cuantos'] === 0)
                                <x-ui.estado tono="aviso" icono="bi-dash-circle">{{ $fila['cantidad'] }}</x-ui.estado>
                            @else
                                <span class="d-flex align-items-center gap-2 min-w-0">
                                    <x-ui.estado tono="marca" icono="bi-capsule">{{ $fila['cantidad'] }}</x-ui.estado>
                                    <span class="texto-una-linea texto-tenue small" title="{{ $fila['resumen'] }}">{{ $fila['resumen'] }}</span>
                                </span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Dos acciones directas, en la misma línea; el menú «…» de una sola opción está prohibido
                                 (pauta de Alexander). Quitar siempre se puede: el tratamiento es una plantilla que se copia
                                 al récipe, los récipes ya hechos no lo referencian. Pide confirmación y dice qué se va. --}}
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Quitar el tratamiento"
                                            aria-label="Quitar el tratamiento {{ $fila['nombre'] }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </x-ui.acciones-fila>
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
        </div>
    @endunless
    </div>
@endsection
