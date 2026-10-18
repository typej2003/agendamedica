@extends('clinica.layout')

@section('titulo', 'Exámenes')

@section('contenido')
    {{-- Exámenes del catálogo (WEB-8.1b): los exámenes y estudios del médico con su tipo. Una sola acción primaria (Nuevo
         examen); el buscador y el filtro por tipo son lo que se usa todo el tiempo y están en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Exámenes" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo examen
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre, código o tipo" etiqueta="Buscar examen">
            <select name="tipo" class="form-select catalogo-filtro" aria-label="Filtrar por tipo de examen">
                @foreach ($vm->opcionesDelFiltro() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $vm->tipoActivo() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.buscador>
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayFiltro())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todos los exámenes</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer examen
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Examen</th>
                    <th>Tipo</th>
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
                        <td data-etiqueta="Tipo">
                            @if ($fila['tipo'])
                                {{ $fila['tipo'] }}
                            @else
                                <span class="texto-tenue">Sin tipo</span>
                            @endif
                        </td>
                        <td data-etiqueta="Código" class="texto-tenue">{{ $fila['codigo'] }}</td>
                        <td class="celda-acciones text-end">
                            <div class="acciones-fila">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
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

    <x-ui.dialogo id="dialogo-catalogo" :titulo="$vm->tituloDelFormulario()" lateral data-catalogo-dialogo
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="_catalogo" value="examen">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre del examen" for="examen-nombre" error="examen" class="col-12"
                                :requerido="true" :ayuda="'Se guarda en mayúsculas. Hasta ' . $vm->largoNombre() . ' caracteres.'">
                        <input type="text" name="examen" id="examen-nombre" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoNombre() }}" autocomplete="off" value="{{ $vm->nombre() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Tipo" for="examen-tipo" error="codetipo" class="col-12"
                                :ayuda="$vm->hayTipos() ? 'Si lo cambias, el examen cambia de tipo también en las consultas que ya lo tienen. El tipo 002 es el de los estudios de imágenes.' : 'Todavía no tienes tipos de examen: créalos desde el catálogo Tipos de examen.'">
                        <select name="codetipo" id="examen-tipo" class="form-select">
                            @foreach ($vm->opcionesDeTipo() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $vm->tipoSeleccionado() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
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
