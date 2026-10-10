@extends('clinica.layout')

@section('titulo', 'Motivos de cita')

@section('contenido')
    <x-ui.page-head titulo="Motivos de cita" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda" />

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-tags" :titulo="$vm->textoVacio()">{{ $vm->ayudaVacio() }}</x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Motivo</th>
                    <th>Sede</th>
                    <th class="text-end">Precio</th>
                    <th>Citas pendientes</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <strong>{{ $fila['razon'] }}</strong>
                            <span class="texto-tenue small d-block">Código {{ $fila['codigo'] }}</span>
                        </td>
                        <td data-etiqueta="Sede">{{ $fila['sede'] }}</td>
                        <td data-etiqueta="Precio" class="text-md-end">{{ $fila['precio'] ?? '—' }}</td>
                        <td data-etiqueta="Citas pendientes">
                            <x-ui.estado :tono="$fila['marca']['tono']" :icono="$fila['marca']['icono']">{{ $fila['marca']['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Una sola acción por fila y sin confirmación: la marca se cambia y se vuelve a
                                 cambiar (no borra nada, el motivo sigue ofreciéndose al agendar). --}}
                            <form method="POST" action="{{ $fila['urlAlternar'] }}">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-sm btn-outline-secondary text-nowrap" title="{{ $fila['accion']['title'] }}"
                                        aria-label="{{ $fila['accion']['etiqueta'] }}">
                                    <i class="bi {{ $fila['accion']['icono'] }}" aria-hidden="true"></i> {{ $fila['accion']['texto'] }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>

            <div class="tarjeta-pie">
                <span class="texto-tenue small">{{ $vm->conteo() }}</span>
            </div>
        @endif
    </x-ui.tarjeta>
@endsection
