@extends('clinica.layout')

@section('titulo', 'Tratamientos')

@section('contenido')
    {{-- Tratamientos del catálogo (WEB-8.1a): las plantillas de récipe del médico. Una sola acción primaria (Nuevo
         tratamiento); alta y edición son el editor, en su propia pantalla. --}}
    <x-ui.page-head titulo="Tratamientos" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo tratamiento
            </a>
            <div class="w-100">
                <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" placeholder="Nombre del tratamiento" etiqueta="Buscar tratamiento" />
            </div>
            <div class="w-100">
                <x-ui.segmentado class="catalogos-indice" :opciones="$vm->catalogos()" etiqueta="Catálogos" />
            </div>
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno>
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
            <x-ui.tabla>
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
                        <td class="celda-principal">
                            <a href="{{ $fila['urlEditar'] }}"><strong>{{ $fila['nombre'] }}</strong></a>
                            <span class="texto-tenue small d-block">Código {{ $fila['codigo'] }}</span>
                        </td>
                        <td data-etiqueta="Medicamentos">
                            @if ($fila['cuantos'] === 0)
                                <x-ui.estado tono="aviso" icono="bi-dash-circle">{{ $fila['cantidad'] }}</x-ui.estado>
                            @else
                                <x-ui.estado tono="marca" icono="bi-capsule">{{ $fila['cantidad'] }}</x-ui.estado>
                                <span class="texto-tenue small d-block mt-1 catalogo-texto-corto">{{ $fila['resumen'] }}</span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                {{-- Quitar siempre se puede: el tratamiento es una plantilla que se copia al récipe, los
                                     récipes ya hechos no lo referencian. Pide confirmación y dice qué se va. --}}
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.menu :etiqueta="'Más acciones de ' . $fila['nombre']">
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar el tratamiento
                                        </button>
                                    </x-ui.menu>
                                </form>
                            </div>
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
@endsection
