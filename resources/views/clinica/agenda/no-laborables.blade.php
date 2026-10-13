@extends('clinica.layout')

@section('titulo', 'Días no laborables')

@section('contenido')
    <x-ui.page-head titulo="Días no laborables" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlMarcar() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Marcar un día
            </a>
        </x-slot>
    </x-ui.page-head>

    <div class="agenda-barra">
        <div class="agenda-navegacion">
            <div class="btn-group" role="group" aria-label="Navegación">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlAnterior() }}" title="Mes anterior" aria-label="Mes anterior">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
                <a class="btn btn-outline-secondary" href="{{ $vm->urlEsteMes() }}">Este mes</a>
                <a class="btn btn-outline-secondary" href="{{ $vm->urlSiguiente() }}" title="Mes siguiente" aria-label="Mes siguiente">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
            <h2 class="agenda-titulo">{{ $vm->titulo() }}</h2>
            <span class="texto-tenue small">· {{ $vm->conteo() }}</span>
        </div>
    </div>

    <x-ui.errores />

    {{-- El formulario sirve para las dos cosas: sin `editando` da de alta; con él, edita ese día. --}}
    <form method="POST" action="{{ $vm->urlGuardar() }}">
        @csrf
        <input type="hidden" name="id" value="{{ $vm->idEditando() }}">

        <x-ui.tarjeta :titulo="$vm->tituloDelFormulario()" icono="bi-calendar-x" class="mb-3">
            <div class="row g-3 align-items-end">
                <x-ui.campo etiqueta="Día" for="dia" class="col-md-3">
                    <input type="date" name="dia" id="dia" class="form-control" value="{{ $vm->dia() }}" required>
                </x-ui.campo>

                <x-ui.campo etiqueta="Tipo" for="tipo" class="col-md-3">
                    <select name="tipo" id="tipo" class="form-select">
                        @foreach ($vm->opcionesDeTipo() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <x-ui.campo etiqueta="Motivo" for="motivo" class="col-md-6" ayuda="Es el texto que muestra la agenda, como en el escritorio.">
                    <input type="text" name="motivo" id="motivo" class="form-control" maxlength="100"
                           placeholder="Lo que se le avisa a la secretaria" value="{{ $vm->valor('motivo') }}">
                </x-ui.campo>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                @if ($vm->editando())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlCancelar() }}">Cancelar</a>
                @endif
                <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
            </div>
        </x-ui.tarjeta>
    </form>

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-calendar-check" :titulo="$vm->textoVacio()">El médico atiende todos los días de este mes.</x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Día</th>
                    <th>Tipo</th>
                    <th>Motivo</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal"><strong>{{ $fila['dia'] }}</strong></td>
                        <td data-etiqueta="Tipo"><x-ui.estado tono="aviso" icono="bi-calendar-x">{{ $fila['tipo'] }}</x-ui.estado></td>
                        <td data-etiqueta="Motivo">{{ $fila['motivo'] ?? '—' }}</td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <form method="POST" action="{{ $fila['urlEliminar'] }}" data-confirmar="{{ $fila['confirmar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>
@endsection
