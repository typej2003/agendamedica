{{-- Las referencias (WEB-5.3a): los médicos a los que se refiere al paciente en esta consulta, cada uno con su motivo; el
     buscador del catálogo `doctores` y el alta del colega que falta. Recibe `$vm` (ReferenciasVista). Varias por consulta, una por
     médico. El motivo de cada una se guarda con su botón; el buscador y el alta son online. --}}
@php $estado = $vm->estado(); @endphp

<div id="solicitudes-referencias" data-url-buscar="{{ $vm->urlBuscar() }}" data-url-agregar="{{ $vm->urlAgregar() }}"
     data-alta-abierta="{{ $vm->abrirAlta() ? '1' : '0' }}">

    <x-ui.tarjeta titulo="Referir a un médico" icono="bi-search" class="mb-3">
        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <div class="position-relative" id="referencias-busqueda">
            <label class="form-label" for="referencia-texto">Busca en tu catálogo de médicos</label>
            <div class="buscador-campo buscador-vivo">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="referencia-texto" class="form-control form-control-sm" autocomplete="off"
                       placeholder="Nombre, especialidad o clínica…" aria-label="Buscar un médico del catálogo" aria-controls="referencia-resultados">
            </div>

            <template id="referencia-plantilla">
                <div class="resultado-paciente resultado-motivo">
                    <span class="resultado-paciente-datos"></span>
                    <form method="POST" action="{{ $vm->urlAgregar() }}">
                        @csrf
                        <input type="hidden" name="cedula" value="">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-person-up" aria-hidden="true"></i> Referir
                        </button>
                    </form>
                </div>
            </template>

            <div class="list-group resultados-flotantes" id="referencia-resultados" hidden></div>
        </div>

        <p class="form-text mt-2 mb-0" id="referencia-ayuda">Escribe para buscar. Se muestran hasta 20 médicos.</p>

        @if ($vm->puedeCrearMedico())
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="referencia-nuevo-boton">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar un médico que no está
            </button>
            <p class="form-text mb-0 mt-1"><i class="bi bi-wifi" aria-hidden="true"></i> Agregar un médico necesita conexión: no se guarda sin ella.</p>
        @endif
    </x-ui.tarjeta>

    <x-ui.tarjeta titulo="Referencias de esta consulta" icono="bi-person-up">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

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
    </x-ui.tarjeta>

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
