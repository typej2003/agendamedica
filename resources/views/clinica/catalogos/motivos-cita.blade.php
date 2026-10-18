@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- Motivos de cita del catálogo (WEB-8.1c): las razones con las que se agenda, con su precio, su sede y la marca de varias
         citas pendientes (WEB-2.17). Una sola acción primaria (Nuevo motivo); el buscador está en su barra de herramientas; los
         demás catálogos son submenús del menú lateral. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}" data-tour="catalogos-nuevo">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo motivo
            </a>
        </x-slot>
    </x-ui.page-head>

    <x-ui.barra-herramientas>
        <x-ui.buscador :accion="$vm->urlBase()" :valor="$vm->buscar()" :limpiar="$vm->urlLimpiar()"
                       placeholder="Nombre, código o sede" etiqueta="Buscar motivo de cita" />
    </x-ui.barra-herramientas>

    <x-ui.errores />

    <x-ui.tarjeta sin-relleno data-tour="catalogos-lista">
        @if ($vm->estaVacio())
            @php $vacio = $vm->vacio(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    @if ($vm->hayBusqueda())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlLimpiar() }}">Ver todos los motivos</a>
                    @else
                        <a class="btn btn-primary" href="{{ $vm->urlNuevo() }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer motivo
                        </a>
                    @endif
                </x-slot>
            </x-ui.vacio>
        @else
            <x-ui.tabla class="tabla-catalogo-motivos">
                <thead>
                <tr>
                    <th>Motivo</th>
                    <th>Sede</th>
                    <th class="text-md-end">Precio</th>
                    <th>Citas pendientes</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        {{-- Una línea por fila (pauta de Alexander): el código sale del listado y queda en el `title` de la
                             celda y en el diálogo. Los avisos de repetido y de código compartido son estado: se quedan, como
                             pastillas en la misma línea. --}}
                        <td class="celda-principal" title="Código {{ $fila['codigo'] }}">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <span class="texto-una-linea"><strong>{{ $fila['razon'] }}</strong></span>
                                @if ($fila['repetido'])
                                    <x-ui.estado tono="aviso" icono="bi-files">{{ $fila['repetido'] }}</x-ui.estado>
                                @endif
                                @if ($fila['mismoCodigo'])
                                    <x-ui.estado tono="info" icono="bi-geo-alt">{{ $fila['mismoCodigo'] }}</x-ui.estado>
                                @endif
                            </span>
                        </td>
                        <td data-etiqueta="Sede"><span class="texto-una-linea">{{ $fila['sede'] }}</span></td>
                        <td data-etiqueta="Precio" class="text-md-end">{{ $fila['precio'] ?? '—' }}</td>
                        <td data-etiqueta="Citas pendientes">
                            <x-ui.estado :tono="$fila['marca']['tono']" :icono="$fila['marca']['icono']">{{ $fila['marca']['texto'] }}</x-ui.estado>
                        </td>
                        <td class="celda-acciones text-end">
                            {{-- Tres acciones: Editar queda a la vista y las dos secundarias van en el menú, con la marca
                                 (se cambia y se vuelve a cambiar, no borra nada) primero y **quitar al final, en rojo**. Quitar
                                 pide confirmación que nombra el motivo; si hay citas con él, el servidor no lo borra y dice
                                 cuántas. --}}
                            <x-ui.acciones-fila etiqueta="Acciones de {{ $fila['razon'] }}">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $fila['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>
                                <x-ui.menu :etiqueta="'Más acciones de ' . $fila['razon']">
                                    <form method="POST" action="{{ $fila['urlAlternar'] }}">
                                        @csrf
                                        @method('PATCH')
                                        @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                                            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                                        @endforeach
                                        <button class="menu-item" title="{{ $fila['accion']['title'] }}">
                                            <i class="bi {{ $fila['accion']['icono'] }}" aria-hidden="true"></i> {{ $fila['accion']['texto'] }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ $fila['urlQuitar'] }}" data-confirmar="{{ $fila['avisoQuitar'] }}">
                                        @csrf
                                        @method('DELETE')
                                        @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                                            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                                        @endforeach
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar del catálogo
                                        </button>
                                    </form>
                                </x-ui.menu>
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

    {{-- El alta y la edición, en un diálogo lateral: un solo diálogo para toda la página (`catalogos.js` lo abre con
         `?nuevo=1`, con `?editar=` o si la validación falló, para que el error no quede detrás de una ventana cerrada). --}}
    <x-ui.dialogo id="dialogo-catalogo" :titulo="$vm->tituloDelFormulario()" lateral data-catalogo-dialogo
                  data-abrir-al-cargar="{{ ($vm->abrirAlCargar() || $errors->any()) ? '1' : '0' }}">
        <form method="POST" action="{{ $vm->urlGuardar() }}">
            @csrf
            <input type="hidden" name="_catalogo" value="motivo-cita">
            <input type="hidden" name="id" value="{{ $vm->idEditando() }}">
            @foreach ($vm->parametrosDeVuelta() as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <div class="dialogo-cuerpo">
                @if ($vm->codigoEditando())
                    <p class="texto-tenue small mb-3">Código {{ $vm->codigoEditando() }}. El código no se cambia.</p>
                @else
                    <p class="texto-tenue small mb-3">El código se asigna solo: el que sigue en tu lista.</p>
                @endif

                <div class="row g-3">
                    <x-ui.campo etiqueta="Nombre del motivo" for="motivo-razon" error="razon" class="col-12"
                                :requerido="true" :ayuda="'Se guarda en mayúsculas. Hasta ' . $vm->largoRazon() . ' caracteres.'">
                        <input type="text" name="razon" id="motivo-razon" class="form-control" data-foco-inicial
                               maxlength="{{ $vm->largoRazon() }}" autocomplete="off" value="{{ $vm->razon() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Precio" for="motivo-precio" error="precio" class="col-12"
                                ayuda="El monto de la cita cuando no escribes otro al agendar. Déjalo vacío si el motivo no tiene precio fijo. Cambiarlo no altera las citas que ya agendaste.">
                        <input type="number" step="0.01" min="0" name="precio" id="motivo-precio" class="form-control"
                               inputmode="decimal" value="{{ $vm->precio() }}">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Sede" for="motivo-sede" error="office_id" class="col-12"
                                :ayuda="$vm->haySedes() ? 'Con «Todas las sedes» el motivo se ofrece en cualquier sede; con una sede, sólo en ella (y con el precio de ella).' : 'Todavía no tienes sedes: el motivo se ofrece en todas. Créalas en Sedes y consultorios.'">
                        <select name="office_id" id="motivo-sede" class="form-select">
                            @foreach ($vm->opcionesDeSede() as $opcion)
                                <option value="{{ $opcion['valor'] }}" {{ $vm->sedeSeleccionada() === $opcion['valor'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <div class="col-12">
                        <input type="hidden" name="admite_varias" value="0">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="admite_varias" id="motivo-varias" value="1"
                                   {{ $vm->admiteVarias() ? 'checked' : '' }}>
                            <label class="form-check-label" for="motivo-varias">Admite varias citas pendientes del mismo paciente</label>
                        </div>
                        <span class="form-text d-block">Para servicios que se agendan seguido —masajes, terapias—: con este motivo no se pregunta qué hacer con la cita anterior.</span>
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
