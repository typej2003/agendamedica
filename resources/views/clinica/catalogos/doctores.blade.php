@extends('clinica.layout')

@section('titulo', 'Médicos referidos')

@section('contenido')
    {{-- Médicos referidos del catálogo (WEB-8.1b): la libreta de colegas del médico (`doctores`). Una sola acción primaria
         (Nuevo médico); el buscador es lo que se usa todo el tiempo y están en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Médicos referidos" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo médico
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre, clínica, ciudad o especialidad" etiqueta="Buscar médico" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todos los médicos</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer médico
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-doctores">
                <thead>
                <tr>
                    <th>Médico</th>
                    <th>Especialidad</th>
                    <th>Contacto</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila (pauta de Alexander): la cédula (el código) y el detalle del contacto salen del
                             listado y quedan en el `title` de la celda y en el diálogo de edición; el aviso de repetidos se
                             queda como pastilla. --}}
                        <td class="celda-principal" title="{{ $fila['title'] }}">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <span class="texto-una-linea"><strong>{{ $fila['nombre'] }}</strong></span>
                                @if ($fila['repetido'])
                                    <x-ui.estado tono="aviso" icono="bi-files">{{ $fila['repetido'] }}</x-ui.estado>
                                @endif
                            </span>
                        </td>
                        <td data-etiqueta="Especialidad"><span class="texto-una-linea">{{ $fila['especialidad'] ?? '—' }}</span></td>
                        <td data-etiqueta="Contacto">
                            @if ($fila['contacto'])
                                <span class="texto-una-linea" title="{{ $fila['title'] }}">{{ $fila['contacto'] }}</span>
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Dos acciones directas, en la misma línea; el menú «…» de una sola opción está prohibido
                                 (pauta de Alexander). --}}
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                                        <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                                    @endforeach
                                    <button class="btn btn-sm btn-outline-danger" title="Quitar del catálogo"
                                            aria-label="Quitar a {{ $fila['nombre'] }} del catálogo">
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
            @if ($vm->haySobrantes())
                {{ $vm->paginador()->links() }}
            @endif
        </div>
    @endunless

    <x-ui.dialogo id="dialogo-catalogo" :titulo="$vm->tituloDelFormulario()" lateral data-catalogo-dialogo
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="_catalogo" value="doctor">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. Es con lo que se enlazan las referencias y no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Apellidos" for="doctor-apellidos" error="apellidos" class="col-12"
                                :ayuda="'Se guardan en mayúsculas. Hasta ' . $vm->largoApellidos() . ' caracteres. Escribe al menos el apellido o el nombre.'">
                        <input type="text" name="apellidos" id="doctor-apellidos" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoApellidos() }}" autocomplete="off" value="{{ $vm->texto('apellidos') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Nombres" for="doctor-nombres" error="nombres" class="col-12"
                                :ayuda="'Se guardan en mayúsculas. Hasta ' . $vm->largoNombres() . ' caracteres.'">
                        <input type="text" name="nombres" id="doctor-nombres" class="form-control"
                               maxlength="{{ $vm->largoNombres() }}" autocomplete="off" value="{{ $vm->texto('nombres') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Especialidad" for="doctor-especialidad" error="codeespecial" class="col-12"
                                :requerido="true"
                                :ayuda="$vm->hayEspecialidades() ? null : 'Todavía no hay especialidades en tu catálogo.'">
                        <select name="codeespecial" id="doctor-especialidad" class="form-select">
                            <option value="">Elige una especialidad</option>
                            @foreach ($vm->opcionesDeEspecialidad() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $vm->especialidadSeleccionada() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Clínica" for="doctor-clinica" error="clinica" class="col-12">
                        <input type="text" name="clinica" id="doctor-clinica" class="form-control"
                               maxlength="{{ $vm->largoClinica() }}" autocomplete="off" value="{{ $vm->texto('clinica') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Ciudad" for="doctor-ciudad" error="ciudad" class="col-sm-6">
                        <input type="text" name="ciudad" id="doctor-ciudad" class="form-control"
                               maxlength="{{ $vm->largoCiudad() }}" autocomplete="off" value="{{ $vm->texto('ciudad') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Teléfono" for="doctor-telefono" error="telefono" class="col-sm-6">
                        <input type="text" name="telefono" id="doctor-telefono" class="form-control"
                               maxlength="{{ $vm->largoTelefono() }}" autocomplete="off" value="{{ $vm->texto('telefono') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Dirección" for="doctor-direccion" error="direccion" class="col-12">
                        <textarea name="direccion" id="doctor-direccion" class="form-control" rows="2"
                                  maxlength="{{ $vm->largoDireccion() }}">{{ $vm->texto('direccion') }}</textarea>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Nota" for="doctor-nota" error="nota" class="col-12"
                                ayuda="Opcional. Es para ti; no sale en la referencia.">
                        <textarea name="nota" id="doctor-nota" class="form-control" rows="3"
                                  maxlength="{{ $vm->largoNota() }}">{{ $vm->texto('nota') }}</textarea>
                    </x-ui.campo>
                </div>
            </div>

            <div class="dialogo-pie">
                @if ($vm->editando())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlCancelar() }}">Cancelar</a>
                @else
                    <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                @endif
                <button class="btn btn-primary">{{ $vm->botonGuardar() }}</button>
            </div>
        </form>
    </x-ui.dialogo>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/catalogos.js') }}"></script>
@endsection
