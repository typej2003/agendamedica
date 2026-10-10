@extends('clinica.layout')

@section('titulo', 'Pacientes')

@section('contenido')
    <x-ui.page-head titulo="Pacientes" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <x-ui.buscador :accion="route('clinica.pacientes')" :valor="$vm->buscar()"
                           placeholder="Nombre, cédula o n.º de historia" etiqueta="Buscar paciente" />
        </x-slot>
    </x-ui.page-head>

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                @if ($vm->hayBusqueda())
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('clinica.pacientes') }}">Ver todos los pacientes</a>
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
@endsection
