@extends('clinica.layout')

@section('titulo', 'Plantillas de texto')

@section('contenido')
    {{-- Plantillas de texto (WEB-8.4): `formato_print` del médico —evolución, informe médico, dietas…—. Una sola acción primaria (Nueva
         plantilla); el buscador y el filtro por tipo están en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Plantillas de texto" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva plantilla
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Título, texto o código" etiqueta="Buscar plantilla">
            <select name="filtro" class="form-select catalogo-filtro" aria-label="Filtrar por tipo de plantilla" data-autoenviar>
                @foreach ($vm->opcionesDelFiltro() as $opcion)
                    <option value="{{ $opcion['valor'] }}" {{ $vm->tipoActivo() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                @endforeach
            </select>
        </x-ui.buscador>
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno>
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayFiltro())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todas las plantillas</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear la primera plantilla
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Plantilla</th>
                    <th>Tipo</th>
                    <th>Código</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <strong>{{ $fila['titulo'] }}</strong>
                            @if ($fila['sinTexto'])
                                <x-ui.estado tono="aviso" icono="bi-exclamation-triangle" class="mt-1">Sin texto: la consulta no la ofrece</x-ui.estado>
                            @else
                                <span class="plantilla-resumen texto-tenue">{{ $fila['resumen'] }}</span>
                            @endif
                        </td>
                        <td data-etiqueta="Tipo">
                            <x-ui.estado :tono="$fila['tipoEnLaWeb'] ? 'info' : 'neutro'" :icono="$fila['tipoEnLaWeb'] ? 'bi-check2-circle' : 'bi-file-earmark-text'">{{ $fila['tipo'] }}</x-ui.estado>
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
                                    <x-ui.menu :etiqueta="'Más acciones de ' . $fila['titulo']">
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar la plantilla
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
            <input type="hidden" name="_catalogo" value="plantilla-texto">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Tipo" for="plantilla-tipo" error="tipo" class="col-12" :requerido="true"
                                ayuda="Dice dónde se ofrece: la evolución, el informe médico o las dietas y recomendaciones de la consulta.">
                        <select name="tipo" id="plantilla-tipo" class="form-select" data-foco-inicial>
                            <option value="">Elige el tipo</option>
                            @foreach ($vm->gruposDeTipo() as $grupo)
                                <optgroup label="{{ $grupo['grupo'] }}">
                                    @foreach ($grupo['opciones'] as $opcion)
                                        <option value="{{ $opcion['valor'] }}" {{ $vm->tipoSeleccionado() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Título" for="plantilla-titulo" error="titulo" class="col-12" :requerido="true"
                                :ayuda="'Es lo que ves en la lista de la consulta. Se guarda en mayúsculas. Hasta ' . $vm->largoTitulo() . ' caracteres.'">
                        <input type="text" name="titulo" id="plantilla-titulo" class="form-control"
                               maxlength="{{ $vm->largoTitulo() }}" autocomplete="off" value="{{ $vm->tituloEscrito() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Texto" for="plantilla-texto" error="descripcion" class="col-12" :requerido="true"
                                ayuda="Se copia a la consulta al elegirla y ahí se termina de editar. Cambiar o quitar la plantilla no cambia lo que ya escribiste.">
                        <textarea name="descripcion" id="plantilla-texto" rows="10" class="form-control"
                                  maxlength="{{ $vm->largoTexto() }}">{{ $vm->textoEscrito() }}</textarea>
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
