@extends('clinica.layout')

@section('titulo', 'Dietas')

@section('contenido')
    {{-- Dietas del médico (WEB-12.4b): `dietas`, el catálogo que alimenta la pestaña Alimentación de la consulta pediátrica. Una sola acción primaria
         (Nueva dieta); el buscador está en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Dietas" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva dieta
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador agregar="dialogo-catalogo" campo="dieta-titulo" :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Título, texto o código" etiqueta="Buscar dieta" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <div data-buscar-resultados>
    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayFiltro())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todas las dietas</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear la primera dieta
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-plantillas">
                <thead>
                <tr>
                    <th>Dieta</th>
                    <th>Calorías</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila: el código y el resumen del texto quedan en el `title` de la celda. --}}
                        <td class="celda-principal" title="Código {{ $fila['codigo'] }}{{ $fila['resumen'] ? ' · ' . $fila['resumen'] : '' }}">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <span class="texto-una-linea"><strong>{{ $fila['titulo'] }}</strong></span>
                                @if ($fila['sinTexto'])
                                    <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Sin texto: la consulta no la ofrece</x-ui.estado>
                                @endif
                            </span>
                        </td>
                        <td data-etiqueta="Calorías">{{ $fila['calorias'] ?? '—' }}</td>
                        <td class="celda-acciones text-end">
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['titulo'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                                        <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                                    @endforeach
                                    <button class="btn btn-sm btn-outline-danger" title="Quitar la dieta"
                                            aria-label="Quitar la dieta {{ $fila['titulo'] }}">
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
    </div>

    <x-ui.dialogo id="dialogo-catalogo" :titulo="$vm->tituloDelFormulario()" lateral data-catalogo-dialogo
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Título" for="dieta-titulo" error="dietas" class="col-12" :requerido="true"
                                :ayuda="'Es lo que ves en la lista de la consulta. Se guarda en mayúsculas. Hasta ' . $vm->largoTitulo() . ' caracteres.'">
                        <input type="text" name="dietas" id="dieta-titulo" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoTitulo() }}" autocomplete="off" value="{{ $vm->tituloEscrito() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Calorías" for="dieta-calorias" error="calorias" class="col-12 col-md-6"
                                ayuda="Opcional. Un número, por ejemplo 1200.">
                        <input type="text" name="calorias" id="dieta-calorias" class="form-control" inputmode="decimal"
                               maxlength="10" autocomplete="off" value="{{ $vm->caloriasEscritas() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Texto" for="dieta-texto" error="descripcion" class="col-12" :requerido="true"
                                ayuda="Se copia a la pestaña Alimentación al elegirla y ahí se termina de editar. Cambiar o quitar la dieta no cambia lo que ya escribiste.">
                        <textarea name="descripcion" id="dieta-texto" rows="10" class="form-control"
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
