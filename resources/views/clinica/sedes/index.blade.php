@extends('clinica.layout')

@section('titulo', 'Sedes')

@section('contenido')
    <x-ui.page-head titulo="Sedes"
                    subtitulo="Los lugares donde atienden los médicos del consultorio. Aquí se elige la sede al agendar y al crear un consultorio."
                    :volver="$vm->urlVolver()" volver-texto="Volver">
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-slot name="filtros">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué sedes ver" />
        </x-slot>
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <form method="POST" action="{{ $vm->urlGuardar() }}">
        @csrf
        <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">

        <x-ui.tarjeta :titulo="$vm->tituloDelFormulario()" icono="bi-hospital" class="mb-3">
            <div class="row g-3">
                <x-ui.campo etiqueta="Nombre" for="name" requerido class="col-md-4">
                    <input type="text" name="name" id="name" class="form-control" maxlength="200"
                           placeholder="Clínica Metropolitana" value="{{ $vm->valor('name') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Dirección" for="address" requerido class="col-md-5">
                    <input type="text" name="address" id="address" class="form-control" maxlength="1000" value="{{ $vm->valor('address') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Teléfono" for="phone" class="col-md-3">
                    <input type="text" name="phone" id="phone" class="form-control" maxlength="50" value="{{ $vm->valor('phone') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Ciudad" for="city_id" requerido class="col-md-5" ayuda="El estado y el país salen de la ciudad.">
                    <select name="city_id" id="city_id" class="form-select" required>
                        <option value="">— Elige la ciudad —</option>
                        @foreach ($vm->opcionesDeCiudad() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="activo" id="activo" value="1" {{ $vm->seSigueUsando() ? 'checked' : '' }}>
                        <label class="form-check-label" for="activo">Se sigue usando</label>
                    </div>
                </div>
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
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio icono="bi-hospital" :titulo="$vacio['texto']">{{ $vacio['ayuda'] }}</x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-sedes">
                <thead>
                <tr>
                    <th>Sede</th>
                    <th>Dirección</th>
                    <th>Teléfono</th>
                    <th class="text-center">Consultorios</th>
                    <th>Estado</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila (pauta de Alexander): la ciudad sale del listado y queda en el `title` de la
                             celda (y en el formulario de arriba). --}}
                        <td class="celda-principal" title="{{ $fila['ciudad'] ? 'Ciudad: ' . $fila['ciudad'] : '' }}">
                            <span class="texto-una-linea"><strong>{{ $fila['nombre'] }}</strong></span>
                        </td>
                        <td data-etiqueta="Dirección"><span class="texto-una-linea" title="{{ $fila['direccion'] }}">{{ $fila['direccion'] }}</span></td>
                        <td data-etiqueta="Teléfono"><span class="texto-una-linea">{{ $fila['telefono'] ?? '—' }}</span></td>
                        <td data-etiqueta="Consultorios" class="text-md-center">
                            @if ($fila['consultorios'] > 0)
                                <x-ui.estado tono="neutro" icono="bi-door-closed">{{ $fila['consultorios'] }}</x-ui.estado>
                            @else
                                <span class="texto-tenue small">ninguno</span>
                            @endif
                        </td>
                        <td data-etiqueta="Estado">
                            <x-ui.estado :tono="$fila['estado']['tono']" :icono="$fila['estado']['icono']">{{ $fila['estado']['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Dos acciones: «Editar» y el interruptor de «se sigue usando», directos y en la misma línea.
                                 El menú «…» de una sola opción está prohibido (pauta de Alexander); desactivar una sede no
                                 borra nada y no pide confirmación, y el interruptor va en icono —con su `title` y su
                                 `aria-label`— porque con el rótulo la fila no entra a 1024 px. El estado se ve igual, en su
                                 pastilla. --}}
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <form method="POST" action="{{ $fila['urlActivar'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
                                    <button class="btn btn-sm {{ $fila['activar']['peligro'] ? 'btn-outline-danger' : 'btn-outline-secondary' }}"
                                            title="{{ $fila['activar']['texto'] }} la sede"
                                            aria-label="{{ $fila['activar']['texto'] }} la sede {{ $fila['nombre'] }}">
                                        <i class="bi {{ $fila['activar']['icono'] }}" aria-hidden="true"></i>
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
@endsection
