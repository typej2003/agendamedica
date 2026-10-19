{{-- **I.Q.** (cirugía pediátrica, 2026-10-19): el último ítem del árbol de PediaReport (`tabpage_20`). Tiene la
     evaluación pre-operatoria (`tabpage_32` / `d_pre_aneste`) y las intervenciones (`tabpage_21`); el riesgo
     quirúrgico queda anunciado como pendiente. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-quirurgico">
    <x-ui.tarjeta titulo="I.Q. (cirugía pediátrica)" icono="bi-scissors">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <nav class="pestanas-en-fila mb-3" aria-label="Cirugía">
            @foreach ($seccion->pestanas() as $pestana)
                @if ($pestana['pendiente'])
                    <span class="pestana" title="Llega en la próxima entrega">
                        {{ $pestana['texto'] }} <small class="texto-tenue">(próximamente)</small>
                    </span>
                @else
                    <a class="pestana {{ $pestana['activa'] ? 'activa' : '' }}" href="{{ $pestana['url'] }}"
                       @if ($pestana['activa']) aria-current="page" @endif>
                        {{ $pestana['texto'] }}
                        @if ($pestana['cargada'])<i class="bi bi-check2-circle ms-1" title="Con datos" aria-hidden="true"></i>@endif
                    </a>
                @endif
            @endforeach
        </nav>

        <p class="text-muted small mb-3">{{ $seccion->ayuda() }}</p>

        @if ($seccion->esPestana('intervenciones'))
            {{-- Las intervenciones: una fila por intervención (`interven_consulta`). --}}
            @if ($seccion->tieneIntervenciones())
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr><th>Código</th><th>Descripción</th><th>Detalle</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($seccion->intervenciones() as $intervencion)
                                <tr>
                                    <td><code>{{ $intervencion['codigo'] }}</code></td>
                                    <td>{{ $intervencion['descripcion'] }}</td>
                                    <td class="texto-tenue">{{ $intervencion['detalle'] }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ $intervencion['urlQuitar'] }}"
                                              onsubmit="return confirm('¿Quitar esta intervención?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Quitar">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted">Esta consulta todavía no tiene intervenciones cargadas.</p>
            @endif

            <form method="POST" action="{{ $seccion->urlAgregarIntervencion() }}" id="intervencion-form">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-2">
                        <label class="form-label" for="intervencion-codigo">Código</label>
                        <input type="text" class="form-control text-uppercase" maxlength="3" required
                               id="intervencion-codigo" name="codigo" placeholder="A01">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="intervencion-descripcion">Descripción</label>
                        <input type="text" class="form-control" maxlength="100" required
                               id="intervencion-descripcion" name="descripcion">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="intervencion-detalle">Detalle</label>
                        <input type="text" class="form-control" id="intervencion-detalle" name="detalle">
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar
                        </button>
                    </div>
                </div>
                <p class="form-text mb-0">Si el código ya existe en esta consulta, se corrigen su descripción y su detalle.</p>
            </form>
        @else
            @if ($seccion->esEmergencia())
                <p class="alert alert-warning py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Esta evaluación está marcada como <strong>emergencia</strong>.</span>
                </p>
            @endif

            <form method="POST" action="{{ $seccion->urlGuardar() }}" id="quirurgico-form"
                  data-consulta-quirurgico data-aviso="{{ $seccion->avisoSinGuardar() }}">
                @csrf

                <div class="row g-3">
                    @foreach ($seccion->campos() as $campo)
                        <div class="{{ $campo['clase'] === 'parrafo' ? 'col-12' : 'col-12 col-md-4' }}">
                            <label class="form-label" for="quirurgico-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>

                            @if ($campo['clase'] === 'parrafo')
                                <textarea class="form-control" rows="3" id="quirurgico-{{ $campo['clave'] }}"
                                          name="{{ $campo['clave'] }}">{{ $campo['valor'] }}</textarea>
                            @elseif ($campo['clase'] === 'si_no')
                                <select class="form-select" id="quirurgico-{{ $campo['clave'] }}" name="{{ $campo['clave'] }}">
                                    <option value="">—</option>
                                    <option value="S" @if ($campo['valor'] === 'S') selected @endif>Sí</option>
                                    <option value="N" @if ($campo['valor'] === 'N') selected @endif>No</option>
                                </select>
                            @else
                                <input type="text" class="form-control" maxlength="1" style="max-width: 6rem"
                                       id="quirurgico-{{ $campo['clave'] }}" name="{{ $campo['clave'] }}"
                                       value="{{ $campo['valor'] }}">
                            @endif

                            @if (! empty($campo['ayuda']))
                                <p class="form-text mb-0">{{ $campo['ayuda'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3" id="quirurgico-form-sin-guardar" hidden>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Hay datos sin guardar.</span>
                </p>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar la evaluación
                    </button>
                </div>
            </form>
        @endif
    </x-ui.tarjeta>
</div>
