@extends('clinica.layout')

@section('titulo', 'Conceptos de factura')

@section('contenido')
    {{-- Conceptos de la pre-factura (F9): `motivo_factura` con su monto y su monto con seguro. Una sola acción primaria (Nuevo
         concepto); el buscador va en la barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Conceptos de factura" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo concepto
            </a>
        </x-slot>
    </x-ui.page-head>

    @include('clinica.catalogos._novedades-iniciales')

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre o código" etiqueta="Buscar concepto" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayFiltro())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todos los conceptos</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer concepto
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-motivos-factura">
                <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="text-md-end">Monto</th>
                    <th class="text-md-end">Con seguro</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal" title="Código {{ $fila['codigo'] }}">
                            <span class="texto-una-linea"><strong>{{ $fila['nombre'] }}</strong></span>
                        </td>
                        <td data-etiqueta="Monto" class="text-md-end">{{ $fila['monto'] }}</td>
                        <td data-etiqueta="Con seguro" class="text-md-end">{{ $fila['montoSeg'] ?? '—' }}</td>
                        <td class="celda-acciones text-end">
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
            <input type="hidden" name="_catalogo" value="motivo-factura">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre del concepto" for="factura-nombre" error="descripcion" class="col-12"
                                :requerido="true" :ayuda="'Se guarda en mayúsculas. Hasta ' . $vm->largoNombre() . ' caracteres.'">
                        <input type="text" name="descripcion" id="factura-nombre" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoNombre() }}" autocomplete="off" value="{{ $vm->nombre() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Monto" for="factura-monto" error="monto" class="col-12" :requerido="true"
                                ayuda="Es el monto que se propone al agregar el concepto a una pre-factura; allí se puede cambiar. Cambiarlo no altera las pre-facturas ya cargadas.">
                        <input type="number" step="0.01" min="0" name="monto" id="factura-monto" class="form-control"
                               inputmode="decimal" value="{{ $vm->monto() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Monto con seguro" for="factura-monto-seg" error="monto_seg" class="col-12"
                                ayuda="Opcional. Se muestra como referencia junto al monto en la pre-factura.">
                        <input type="number" step="0.01" min="0" name="monto_seg" id="factura-monto-seg" class="form-control"
                               inputmode="decimal" value="{{ $vm->montoConSeguro() }}">
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
