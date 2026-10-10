@extends('clinica.layout')

@section('titulo', 'Sedes')

@section('contenido')
    <x-ui.page-head titulo="Sedes"
                    subtitulo="Los lugares donde atienden los médicos del consultorio. Aquí se elige la sede al agendar y al crear un consultorio."
                    :volver="$vm->urlVolver()" volver-texto="Volver">
        <x-slot name="acciones">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué sedes ver" />
        </x-slot>
    </x-ui.page-head>

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
            <x-ui.tabla>
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
                        <td class="celda-principal">
                            <strong>{{ $fila['nombre'] }}</strong>
                            <span class="texto-tenue small d-block">{{ $fila['ciudad'] ?? '—' }}</span>
                        </td>
                        <td data-etiqueta="Dirección">{{ $fila['direccion'] }}</td>
                        <td data-etiqueta="Teléfono">{{ $fila['telefono'] ?? '—' }}</td>
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
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <x-ui.menu>
                                    <form method="POST" action="{{ $fila['urlActivar'] }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
                                        <button class="menu-item {{ $fila['activar']['peligro'] ? 'menu-item-peligro' : '' }}">
                                            <i class="bi {{ $fila['activar']['icono'] }}" aria-hidden="true"></i> {{ $fila['activar']['texto'] }}
                                        </button>
                                    </form>
                                </x-ui.menu>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>
        @endif
    </x-ui.tarjeta>
@endsection
