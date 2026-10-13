@extends('clinica.layout')

@section('titulo', 'Citas del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlPacientes()" volver-texto="Pacientes">
        <x-slot name="acciones">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlFicha() }}">
                <i class="bi bi-person-vcard" aria-hidden="true"></i> Ver la ficha
            </a>
            {{-- La única acción primaria de la pantalla: es lo que no se puede hacer en ningún otro lado. --}}
            @if ($vm->puedeCambiarCedula())
                <button type="button" class="btn btn-primary" id="abrir-cedula">
                    <i class="bi bi-person-vcard" aria-hidden="true"></i> Cambiar la cédula
                </button>
            @else
                <button type="button" class="btn btn-primary" disabled title="{{ $vm->motivoSinCambiarCedula() }}">
                    <i class="bi bi-person-vcard" aria-hidden="true"></i> Cambiar la cédula
                </button>
            @endif
        </x-slot>
    </x-ui.page-head>

    {{-- Los errores de validación de la cédula (y los que no caen en un campo) se ven arriba: el diálogo
         también se abre solo, para no dejar el mensaje detrás de una ventana cerrada. --}}
    <x-ui.errores />

    @unless ($vm->puedeCambiarCedula())
        <div class="alert alert-warning py-2 d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span>{{ $vm->motivoSinCambiarCedula() }}</span>
        </div>
    @endunless

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    <a class="btn btn-sm btn-outline-primary" href="{{ $vm->urlAgenda() }}">Ir a la agenda</a>
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Cita</th>
                    <th>Razón</th>
                    <th>Estado</th>
                    <th>Pago</th>
                    <th><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    @php
                        $estado = $vm->estado($fila);
                        $pago = $fila->pago();
                        $sinEliminar = $vm->motivoSinEliminar($fila);
                    @endphp
                    <tr class="{{ $fila->clase() }}">
                        <td class="celda-principal">
                            <span class="d-block"><i class="bi bi-calendar3 texto-tenue" aria-hidden="true"></i>
                                <strong>{{ $vm->cuando($fila) }}</strong></span>
                            <span class="texto-tenue small"><i class="bi bi-clock" aria-hidden="true"></i> {{ $fila->hora() }}</span>
                        </td>
                        <td data-etiqueta="Razón">{{ $fila->razon() }}</td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
                        </td>
                        <td data-etiqueta="Pago">
                            <x-ui.estado :tono="$pago['tono']" :icono="$pago['icono']">{{ $pago['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                @if ($fila->urlEditar())
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $vm->urlEditar($fila->id()) }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i> Modificar
                                    </a>
                                @endif

                                @if ($fila->urlEliminar())
                                    <form method="POST" action="{{ $vm->urlEliminar($fila->id()) }}"
                                          data-confirmar="{{ $vm->confirmarEliminar($fila) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Eliminar
                                        </button>
                                    </form>
                                @elseif ($sinEliminar)
                                    <span class="texto-tenue small">{{ $sinEliminar }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>
@endsection

@section('scripts')
    @include('clinica.pacientes._dialogo-cedula')
    <script src="{{ asset('js/clinica/citas-paciente.js') }}"></script>
@endsection
