@extends('clinica.layout')

@section('titulo', 'Medicamentos')

@section('contenido')
    {{-- Medicamentos del catálogo (WEB-8.1a): el vademécum del médico. Una sola acción primaria (Nuevo medicamento);
         el buscador es lo que se usa todo el tiempo y está en su barra de herramientas; los demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head titulo="Medicamentos" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo medicamento
            </a>
        </x-slot>
    </x-ui.page-head>

    @include('clinica.catalogos._novedades-iniciales')

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre comercial, principio activo o presentación" etiqueta="Buscar medicamento" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todo el vademécum</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer medicamento
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-medicamentos">
                <thead>
                <tr>
                    <th>Medicamento</th>
                    <th>Principio activo</th>
                    <th>Indicaciones por defecto</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por medicamento (pauta de Alexander): lo secundario —el código y las otras marcas— sale del
                             listado y queda en el `title` de la fila y en el diálogo de edición. Lo que es información clínica
                             (Psicotrópico) se queda, como pastilla en la misma línea. --}}
                        <td class="celda-principal" title="Código {{ $fila['codigo'] }}@if ($fila['marcas'] !== []) · también: {{ implode(', ', $fila['marcas']) }}@endif">
                            <span class="d-flex align-items-center gap-2">
                                <span class="texto-una-linea"><strong>{{ $fila['nombre'] }}</strong>@if ($fila['presentacion']) <span class="texto-tenue">/ {{ $fila['presentacion'] }}</span>@endif</span>
                                @if ($fila['psicotropico'])
                                    <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Psicotrópico</x-ui.estado>
                                @endif
                            </span>
                        </td>
                        <td data-etiqueta="Principio activo">
                            <span class="texto-una-linea" title="{{ $fila['generico'] ?? '' }}">{{ $fila['generico'] ?? '—' }}</span>
                        </td>
                        <td data-etiqueta="Indicaciones">
                            @if ($fila['uso'])
                                <span class="texto-una-linea" title="{{ $fila['uso'] }}">{{ $fila['uso'] }}</span>
                            @else
                                <span class="texto-tenue">—</span>
                            @endif
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Dos acciones: los botones directos, uno al lado del otro y alineados por `.acciones-fila`. Antes «Quitar»
                                 vivía en un menú «…» de una sola opción, que rompía la alineación de la fila (pauta de Alexander). --}}
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['nombre'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                {{-- Quitar es lo destructivo: mismo tamaño y altura, tono de peligro y confirmación que nombra lo que se
                                     quita. Si el medicamento está en un récipe o en un tratamiento el servidor no lo borra y dice dónde está. --}}
                                <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="buscar" value="{{ $vm->buscar() }}">
                                    <input type="hidden" name="page" value="{{ $vm->paginador()->currentPage() }}">
                                    <button class="btn btn-sm btn-outline-danger" title="Quitar del vademécum"
                                            aria-label="Quitar {{ $fila['nombre'] }} del vademécum">
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

    {{-- El alta y la edición, en un diálogo lateral: un solo diálogo para toda la página. «Nuevo medicamento» recarga
         con `?nuevo=1` y «Editar» con `?editar=`: el diálogo nace abierto (`catalogos.js`) con el formulario vacío o
         con el medicamento. Si la validación falló, también se abre solo para que el error no quede detrás de una
         ventana cerrada. --}}
    <x-ui.dialogo id="dialogo-medicamento" :titulo="$vm->tituloDelFormulario()" lateral
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="_catalogo" value="medicamento">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            <input type="hidden" name="buscar" value="{{ $vm->buscar() }}">
            <input type="hidden" name="page" value="{{ $vm->paginador()->currentPage() }}">

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre comercial" for="medicamento-comercial" error="nombrecomercial" class="col-12"
                                :requerido="! $vm->editando()"
                                :ayuda="'Hasta ' . $vm->largoNombre() . ' caracteres. Se guarda en mayúsculas.'">
                        <input type="text" name="nombrecomercial" id="medicamento-comercial" class="form-control"
                               maxlength="{{ $vm->largoNombre() }}" autocomplete="off" value="{{ $vm->texto('nombrecomercial') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Principio activo (genérico)" for="medicamento-generico" error="nombregenerico" class="col-12"
                                :ayuda="'Opcional. Hasta ' . $vm->largoNombre() . ' caracteres.'">
                        <input type="text" name="nombregenerico" id="medicamento-generico" class="form-control"
                               maxlength="{{ $vm->largoNombre() }}" autocomplete="off" value="{{ $vm->texto('nombregenerico') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Presentación" for="medicamento-presentacion" error="presentacion" class="col-12"
                                :ayuda="'Opcional. Por ejemplo: Tabletas 500 mg. Hasta ' . $vm->largoPresentacion() . ' caracteres.'">
                        <input type="text" name="presentacion" id="medicamento-presentacion" class="form-control"
                               maxlength="{{ $vm->largoPresentacion() }}" autocomplete="off" value="{{ $vm->texto('presentacion') }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Indicaciones por defecto" for="medicamento-uso" error="uso" class="col-12"
                                ayuda="Es el texto que aparece al agregarlo a un récipe; allí se puede cambiar sin tocar el vademécum.">
                        <textarea name="uso" id="medicamento-uso" class="form-control" rows="3"
                                  maxlength="{{ $vm->largoTexto() }}">{{ $vm->texto('uso') }}</textarea>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Dosificación" for="medicamento-dosificacion" error="dosificacion" class="col-12"
                                ayuda="Escribí SI cuando la dosis sea por kilo: el récipe la calcula con el peso del niño. Cualquier otro texto es una nota tuya y no sale en el récipe.">
                        <textarea name="dosificacion" id="medicamento-dosificacion" class="form-control" rows="2"
                                  maxlength="{{ $vm->largoTexto() }}">{{ $vm->texto('dosificacion') }}</textarea>
                    </x-ui.campo>

                    <div class="col-12">
                        <details @if ($vm->hayDosis()) open @endif>
                            <summary class="mb-2">Dosis por kilo (para el récipe de pediatría)</summary>

                            <p class="text-muted small">
                                Con la dosificación en <strong>SI</strong>, el récipe calcula la posología con el peso del
                                examen físico de la consulta y la presenta en gotas o en ml si cargás el envase.
                            </p>

                            <div class="row g-3">
                                <x-ui.campo etiqueta="Dosis (mg/kg por toma)" for="medicamento-dosis" error="dosis" class="col-6 col-md-4">
                                    <input type="number" step="0.01" min="0" name="dosis" id="medicamento-dosis"
                                           class="form-control" value="{{ $vm->texto('dosis') }}">
                                </x-ui.campo>

                                <x-ui.campo etiqueta="Cada (veces al día)" for="medicamento-cada" error="cada" class="col-6 col-md-4">
                                    <input type="number" step="0.01" min="0" name="cada" id="medicamento-cada"
                                           class="form-control" value="{{ $vm->texto('cada') }}">
                                </x-ui.campo>

                                <x-ui.campo etiqueta="Durante (días)" for="medicamento-durante" error="durante" class="col-6 col-md-4">
                                    <input type="number" step="1" min="0" name="durante" id="medicamento-durante"
                                           class="form-control" value="{{ $vm->texto('durante') }}">
                                </x-ui.campo>

                                <x-ui.campo etiqueta="Envase: mg" for="medicamento-pvc" error="pvc" class="col-6 col-md-4"
                                            ayuda="Los mg que trae la presentación (250 en «250 mg / 5 ml»).">
                                    <input type="number" step="0.01" min="0" name="pvc" id="medicamento-pvc"
                                           class="form-control" value="{{ $vm->texto('pvc') }}">
                                </x-ui.campo>

                                <x-ui.campo etiqueta="Envase: ml" for="medicamento-pvs" error="pvs" class="col-6 col-md-4"
                                            ayuda="Los ml de esa presentación (5 en «250 mg / 5 ml»).">
                                    <input type="number" step="0.01" min="0" name="pvs" id="medicamento-pvs"
                                           class="form-control" value="{{ $vm->texto('pvs') }}">
                                </x-ui.campo>

                                <x-ui.campo etiqueta="Gotas por ml" for="medicamento-concentracion" error="concentracion" class="col-6 col-md-4"
                                            ayuda="Sólo si el récipe tiene que decir gotas.">
                                    <input type="number" step="0.01" min="0" name="concentracion" id="medicamento-concentracion"
                                           class="form-control" value="{{ $vm->texto('concentracion') }}">
                                </x-ui.campo>
                            </div>
                        </details>
                    </div>

                    <div class="col-12">
                        <details @if ($vm->hayMarcas()) open @endif>
                            <summary class="mb-2">Otras marcas comerciales</summary>
                            <div class="catalogo-marcas">
                                @foreach ([1, 2, 3] as $numero)
                                    <x-ui.campo :etiqueta="'Nombre comercial ' . ($numero + 1)" :for="'medicamento-marca-' . $numero" :error="'nombrecomercial' . $numero">
                                        <input type="text" name="nombrecomercial{{ $numero }}" id="medicamento-marca-{{ $numero }}" class="form-control"
                                               maxlength="{{ $vm->largoMarca() }}" autocomplete="off" value="{{ $vm->texto('nombrecomercial' . $numero) }}">
                                    </x-ui.campo>
                                @endforeach
                            </div>
                            <span class="form-text d-block">También se encuentra buscando por estas marcas.</span>
                        </details>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sico" id="medicamento-sico" value="1"
                                   {{ $vm->esPsicotropico() ? 'checked' : '' }}>
                            <label class="form-check-label" for="medicamento-sico">Es un psicotrópico</label>
                        </div>
                    </div>
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
