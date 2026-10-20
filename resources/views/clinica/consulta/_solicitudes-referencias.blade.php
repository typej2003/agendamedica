{{-- Las referencias (WEB-5.3a) como Motivos de consulta (O-29): a la izquierda los médicos a los que se refiere al
     paciente en esta consulta, cada uno con su motivo —que se guarda con su botón— y a la derecha **el catálogo de colegas
     siempre a la vista**, con su buscador y un «Referir» por fila que crea la referencia **al instante**.

     Recibe `$vm` (ReferenciasVista). Varias por consulta, una por médico. El alta del colega que falta sigue siendo online
     y su diálogo vive acá. --}}
@php $estado = $vm->estado(); @endphp

<div id="solicitudes-referencias" data-alta-abierta="{{ $vm->abrirAlta() ? '1' : '0' }}">

    <x-ui.dos-listas titulo-izquierda="Referencias de esta consulta" titulo-derecha="Tu catálogo de médicos" :resumen="$estado['texto']">
        <x-slot name="izquierda">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            </div>

            {{-- Una línea de ayuda como máximo (§10.1). --}}
            <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

            @if ($vm->estaVacia())
                @php $vacio = $vm->sinReferencias(); @endphp
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            @else
                <div class="recipe-lista">
                    @foreach ($vm->referencias() as $referencia)
                        <article class="recipe-bloque" id="referencia-{{ $referencia['cedula'] }}">
                            <header class="recipe-cabecera">
                                <div class="recipe-cabecera-texto">
                                    <h3 class="recipe-titulo">{{ $referencia['nombre'] }}</h3>
                                    <p class="texto-tenue small mb-0">
                                        @if ($referencia['conocido'])
                                            {{ collect([$referencia['especialidad'], $referencia['clinica'], $referencia['telefono']])->filter()->implode(' · ') ?: 'Sin más datos en el catálogo' }}
                                        @else
                                            Este médico ya no está en tu catálogo.
                                        @endif
                                    </p>
                                </div>
                                <div class="recipe-acciones">
                                    {{-- «Imprimir» guarda el motivo y después imprime la referencia (O-16). --}}
                                    <x-ui.imprimir-guardando :url="$referencia['urlImprimir']" form="referencia-form-{{ $referencia['cedula'] }}"
                                                             documento="referencia-{{ $referencia['cedula'] }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                                    </x-ui.imprimir-guardando>
                                    <form method="POST" action="{{ $referencia['urlEliminar'] }}" data-confirmar="{{ $referencia['avisoEliminar'] }}" data-solicitud-salir>
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                                        </button>
                                    </form>
                                </div>
                            </header>

                            <form method="POST" action="{{ $referencia['urlGuardar'] }}" id="referencia-form-{{ $referencia['cedula'] }}"
                                  data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
                                @csrf
                                <input type="hidden" name="cedula" value="{{ $referencia['cedula'] }}">
                                <x-ui.campo etiqueta="Motivo de la referencia" :for="'referencia-motivo-' . $referencia['cedula']" error="motivo" class="mb-2">
                                    <textarea name="motivo" id="referencia-motivo-{{ $referencia['cedula'] }}" class="form-control" rows="4">{{ $referencia['motivo'] }}</textarea>
                                </x-ui.campo>
                                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-2" data-solicitud-aviso hidden>
                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                    <span>Hay cambios sin guardar.</span>
                                </p>
                                <div class="d-flex justify-content-end">
                                    <button class="btn btn-sm btn-primary">
                                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el motivo
                                    </button>
                                </div>
                            </form>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-slot>

        <x-slot name="derecha">
            {{-- El filtro del catálogo: un `GET` a esta misma pestaña. --}}
            <form method="GET" action="{{ $vm->urlDelFiltro() }}" class="dos-listas-buscar" role="search" data-buscar-auto data-buscar-filas=".dos-listas-fila-texto">
                <input type="hidden" name="seccion" value="solicitudes">
                <input type="hidden" name="solicitud" value="referencias">
                <label class="visually-hidden" for="referencia-texto">Buscar un médico en tu catálogo</label>
                <input type="search" id="referencia-texto" name="buscar" value="{{ $vm->buscar() }}"
                       class="form-control form-control-sm" autocomplete="off" placeholder="Nombre, especialidad o clínica…">
                <button class="btn btn-sm btn-outline-secondary" data-buscar-enviar><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                <a class="btn btn-sm btn-link" href="{{ $vm->urlSeccion() }}" data-buscar-limpiar @if ($vm->buscar() === '') hidden @endif>Limpiar</a>
                @if ($vm->puedeCrearMedico())
                    <button type="button" class="btn btn-sm btn-outline-primary" data-buscar-agregar hidden
                            data-agregar-dialogo="dialogo-medico" data-prefill-campo="medico-apellidos">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar «<span data-buscar-agregar-texto></span>»
                    </button>
                @endif
            </form>

            <div data-buscar-resultados>
            @if ($vm->hayResultados())
                <ul class="dos-listas-catalogo">
                    @foreach ($vm->catalogo() as $medico)
                        <li class="dos-listas-fila" @unless ($medico['yaEsta']) data-pasar @endunless>
                            <span class="dos-listas-fila-texto">
                                {{ $medico['nombre'] }}
                                @if ($medico['detalle'] !== '')
                                    <span class="texto-tenue small d-block">{{ $medico['detalle'] }}</span>
                                @endif
                            </span>
                            @if ($medico['yaEsta'])
                                <span class="texto-tenue small"><i class="bi bi-check2-circle" aria-hidden="true"></i> ya está referido</span>
                            @else
                                <form method="POST" action="{{ $medico['urlAgregar'] }}">
                                    @csrf
                                    <input type="hidden" name="cedula" value="{{ $medico['cedula'] }}">
                                    <button class="btn btn-sm btn-outline-primary" data-pasar-boton
                                            title="Referir al paciente" aria-label="Referir al paciente">
                                        <i class="bi bi-person-up" aria-hidden="true"></i>
                                        <span class="dos-listas-boton-texto">Referir</span>
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($vm->paginador())
                    <div class="mt-2">{{ $vm->paginador()->links() }}</div>
                @endif
            @endif

            {{-- La ayuda del catálogo: qué escribe el «Referir» y qué queda para el botón (es lo que cambió en O-29). --}}
            <p class="texto-tenue small mt-2 mb-2">{{ $vm->ayudaDelCatalogo() }}</p>
            </div>

            @if ($vm->puedeCrearMedico())
                <button type="button" class="btn btn-outline-secondary btn-sm" id="referencia-nuevo-boton">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar un médico que no está
                </button>
                <p class="form-text mb-0 mt-1"><i class="bi bi-wifi" aria-hidden="true"></i> Agregar un médico necesita conexión: no se guarda sin ella.</p>
            @endif
        </x-slot>
    </x-ui.dos-listas>

    @if ($vm->puedeCrearMedico())
        <x-ui.dialogo id="dialogo-medico" titulo="Agregar un médico a tu catálogo" lateral>
            <form method="POST" action="{{ $vm->urlMedicoNuevo() }}">
                @csrf
                <div class="dialogo-cuerpo">
                    <x-ui.campo etiqueta="Apellidos" for="medico-apellidos" error="apellidos" class="mb-3">
                        <input type="text" name="apellidos" id="medico-apellidos" class="form-control" maxlength="30" value="{{ old('apellidos') }}" autocomplete="off">
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Nombres" for="medico-nombres" error="nombres" class="mb-3">
                        <input type="text" name="nombres" id="medico-nombres" class="form-control" maxlength="30" value="{{ old('nombres') }}" autocomplete="off">
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Especialidad" for="medico-especialidad" error="codeespecial" requerido class="mb-3">
                        <select name="codeespecial" id="medico-especialidad" class="form-select">
                            <option value="">Elige…</option>
                            @foreach ($vm->especialidades() as $codigo => $nombre)
                                <option value="{{ $codigo }}" {{ old('codeespecial') === (string) $codigo ? 'selected' : '' }}>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Clínica" for="medico-clinica" error="clinica" class="mb-3">
                        <input type="text" name="clinica" id="medico-clinica" class="form-control" maxlength="60" value="{{ old('clinica') }}">
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Dirección" for="medico-direccion" error="direccion" class="mb-3">
                        <textarea name="direccion" id="medico-direccion" class="form-control" rows="2">{{ old('direccion') }}</textarea>
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Teléfono" for="medico-telefono" error="telefono" class="mb-3">
                        <input type="text" name="telefono" id="medico-telefono" class="form-control" maxlength="108" value="{{ old('telefono') }}">
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Ciudad" for="medico-ciudad" error="ciudad" class="mb-3">
                        <input type="text" name="ciudad" id="medico-ciudad" class="form-control" maxlength="30" value="{{ old('ciudad') }}">
                    </x-ui.campo>
                    <p class="form-text mb-0">Se agrega a tu catálogo y se refiere al paciente a él. Los nombres se guardan en mayúsculas.</p>
                </div>
                <div class="dialogo-pie">
                    <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                    <button class="btn btn-primary">Agregar y referir</button>
                </div>
            </form>
        </x-ui.dialogo>
    @endif
</div>
