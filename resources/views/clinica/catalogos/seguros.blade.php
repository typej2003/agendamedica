@extends('clinica.layout')

@section('titulo', 'Seguros / empresas')

@section('contenido')
    {{-- Seguros / empresas del consultorio (O-62): el catálogo `seg_emp` del médico. Es lo que ofrece el desplegable
         «Seguro / empresa» del alta del paciente; antes sólo lo cargaba el panel de administración. Una sola acción
         primaria (Nuevo seguro); el buscador va en la barra de herramientas y los demás catálogos son submenús del
         menú lateral. --}}
    <x-ui.page-head titulo="Seguros / empresas" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo seguro
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre, RIF o código" etiqueta="Buscar seguro o empresa" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayFiltro())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todos los seguros</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer seguro
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-seguros">
                <thead>
                <tr>
                    <th>Seguro / empresa</th>
                    <th>RIF</th>
                    <th>Contacto</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila (pauta de Alexander): el código y la dirección quedan en el `title` y en el
                             diálogo de edición. --}}
                        <td class="celda-principal" title="{{ $fila['title'] }}">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <span class="texto-una-linea"><strong>{{ $fila['nombre'] }}</strong></span>
                                @if ($fila['esPersonal'])
                                    <x-ui.estado tono="info" icono="bi-person">Es el del alta</x-ui.estado>
                                @endif
                            </span>
                        </td>
                        <td data-etiqueta="RIF">{{ $fila['rif'] ?? '—' }}</td>
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
                                            aria-label="Quitar {{ $fila['nombre'] }} del catálogo">
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
            <input type="hidden" name="_catalogo" value="seguro">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">
                        Código {{ $vm->codigoEditando() }}. No se cambia: es con el que el paciente guarda su seguro.
                        @if ($vm->esPersonal())
                            Es el <strong>Personal</strong>: el que el alta del paciente elige por defecto.
                        @endif
                    </p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre del seguro o la empresa" for="seguro-nombre" error="nombre" class="col-12"
                                :requerido="true"
                                :ayuda="'Se guarda en mayúsculas. Hasta ' . $vm->largoNombre() . ' caracteres.'">
                        <input type="text" name="nombre" id="seguro-nombre" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoNombre() }}" autocomplete="off" value="{{ $vm->texto('nombre') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="RIF" for="seguro-rif" error="rif" class="col-sm-6"
                                :ayuda="'Opcional. Hasta ' . $vm->largoRif() . ' caracteres.'">
                        <input type="text" name="rif" id="seguro-rif" class="form-control"
                               maxlength="{{ $vm->largoRif() }}" autocomplete="off" value="{{ $vm->texto('rif') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Teléfono" for="seguro-telefono" error="telef" class="col-sm-6">
                        <input type="text" name="telef" id="seguro-telefono" class="form-control"
                               maxlength="{{ $vm->largoTelefono() }}" autocomplete="off" value="{{ $vm->texto('telef') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Dirección" for="seguro-direccion" error="direccion" class="col-12">
                        <textarea name="direccion" id="seguro-direccion" class="form-control" rows="2"
                                  maxlength="{{ $vm->largoDireccion() }}">{{ $vm->texto('direccion') }}</textarea>
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
