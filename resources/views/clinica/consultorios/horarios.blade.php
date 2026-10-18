@extends('clinica.layout')

@section('titulo', 'Horarios del consultorio')

@section('contenido')
    <x-ui.page-head titulo="Horarios" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Consultorios" />

    <x-ui.errores />

    <form method="POST" action="{{ $vm->urlGuardar() }}">
        @csrf
        <input type="hidden" name="id" value="{{ $vm->idEditando() }}">

        <x-ui.tarjeta :titulo="$vm->tituloDelFormulario()" icono="bi-clock-history" class="mb-3" data-tour="horarios-alta">
            <div class="row g-3">
                <x-ui.campo etiqueta="Día" for="dia_semana" requerido class="col-md-3">
                    <select name="dia_semana" id="dia_semana" class="form-select" required>
                        <option value="">— Elige el día —</option>
                        @foreach ($vm->opcionesDeDia() as $opcion)
                            <option value="{{ $opcion['valor'] }}" {{ $opcion['elegido'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <x-ui.campo etiqueta="Desde" for="hora_inicio" requerido class="col-md-2">
                    <input type="time" name="hora_inicio" id="hora_inicio" class="form-control" required value="{{ $vm->hora('hora_inicio') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Hasta" for="hora_fin" requerido class="col-md-2" ayuda="La hora de fin no cuenta como cita.">
                    <input type="time" name="hora_fin" id="hora_fin" class="form-control" required value="{{ $vm->hora('hora_fin') }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Cupo" for="cupo" class="col-md-2" ayuda="Vacío = sin tope.">
                    <input type="number" name="cupo" id="cupo" class="form-control" min="1" max="65535" placeholder="sin tope" value="{{ $vm->valor('cupo') }}">
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

    <x-ui.tarjeta sin-relleno data-tour="horarios-lista">
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-clock-history" titulo="Este consultorio todavía no tiene horarios cargados.">
                La agenda funciona igual, pero sin bloques no hay tope de pacientes y, por orden de llegada, la cita
                nace a la hora de apertura por defecto cuando nadie elige hora. Carga aquí los días y las horas en que atiendes.
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Día</th>
                    <th>Horario</th>
                    <th>Cupo</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal"><strong>{{ $fila['dia'] }}</strong></td>
                        <td data-etiqueta="Horario">{{ $fila['horario'] }}</td>
                        <td data-etiqueta="Cupo">
                            @if ($fila['cupo'])
                                {{ $fila['cupo'] }}
                            @else
                                <span class="texto-tenue small">sin tope</span>
                            @endif
                        </td>
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
