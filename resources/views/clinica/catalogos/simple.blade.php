@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- Un catálogo de «código + texto» (WEB-8.1b): diagnósticos, motivos de consulta y tipos de examen. Una sola acción
         primaria (Nuevo); el buscador es lo que se usa todo el tiempo y está en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $vm->textoNuevo() }}
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       :placeholder="$vm->placeholder()" :etiqueta="$vm->etiquetaDeBuscar()" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todo el catálogo</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $vm->textoNuevo() }}
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>{{ $vm->etiquetaDelTexto() }}</th>
                    <th>Código</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <strong>{{ $fila['nombre'] }}</strong>
                            @if ($fila['repetido'])
                                <x-ui.estado tono="aviso" icono="bi-files" class="mt-1">{{ $fila['repetido'] }}</x-ui.estado>
                            @endif
                        </td>
                        <td data-etiqueta="Código" class="texto-tenue">{{ $fila['codigo'] }}</td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                {{-- Quitar es lo destructivo y va aparte, en el menú, con confirmación que nombra lo que se
                                     quita. Si está en uso el servidor no lo borra y dice dónde. --}}
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                                        <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                                    @endforeach
                                    <x-ui.menu :etiqueta="'Más acciones de ' . $fila['nombre']">
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar del catálogo
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
            @if ($vm->haySobrantes())
                {{ $vm->paginador()->links() }}
            @endif
        </div>
    @endunless

    {{-- El alta y la edición, en un diálogo lateral: un solo diálogo para toda la página (`catalogos.js` lo abre con
         `?nuevo=1`, con `?editar=` o si la validación falló, para que el error no quede detrás de una ventana cerrada). --}}
    <x-ui.dialogo id="dialogo-catalogo" :titulo="$vm->tituloDelFormulario()" lateral data-catalogo-dialogo
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="_catalogo" value="{{ $vm->titulo() }}">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo :etiqueta="$vm->etiquetaDelTexto()" for="catalogo-descripcion" error="descripcion" class="col-12"
                                :requerido="true" :ayuda="$vm->ayudaDelTexto()">
                        <input type="text" name="descripcion" id="catalogo-descripcion" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largo() }}" autocomplete="off" value="{{ $vm->texto() }}">
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
