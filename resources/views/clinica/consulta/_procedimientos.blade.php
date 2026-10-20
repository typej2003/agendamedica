{{-- **Procedimientos** (gastroenterología pediátrica, 2026-10-19): el ítem 8 del árbol de PediaReport
     (`tabpage_34`), con una pestaña por endoscopía digestiva. Los campos salen del mapa `EndoscopiaDigestiva`; cada uno
     se pinta por su `clase` (`fecha`, `texto`, `parrafo`, `opcion`). El aviso de cambios sin guardar lo pone
     `consulta.js` a partir de `data-aviso`. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-procedimientos">
    <x-ui.tarjeta titulo="Procedimientos" icono="bi-camera-reels">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <nav class="pestanas-en-fila mb-3" aria-label="Endoscopías">
            @foreach ($seccion->pestanas() as $pestana)
                <a class="pestana {{ $pestana['activa'] ? 'activa' : '' }}" href="{{ $pestana['url'] }}"
                   @if ($pestana['activa']) aria-current="page" @endif>
                    {{ $pestana['texto'] }}
                    @if ($pestana['cargada'])<i class="bi bi-check2-circle ms-1" title="Con datos" aria-hidden="true"></i>@endif
                </a>
            @endforeach
        </nav>

        @if ($seccion->esEcoAbdominal())
            {{-- La tercera pestaña del escritorio: el ítem «Procedimientos» también abre el eco abdominal. Se reusa
                 la pantalla del estudio con su `volver` a esta sección. --}}
            @include('clinica.consulta._estudio-abdominal', ['vm' => $seccion->eco(), 'volverA' => 'procedimientos'])
        @else

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="procedimientos-form"
              data-consulta-procedimientos data-aviso="{{ $seccion->avisoSinGuardar() }}">
            @csrf

            <div class="row g-3">
                @foreach ($seccion->campos() as $campo)
                    <div class="{{ in_array($campo['clase'], ['parrafo'], true) ? 'col-12' : 'col-12 col-md-6' }}">
                        <label class="form-label" for="procedimiento-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>

                        @if ($campo['clase'] === 'fecha')
                            <input type="date" class="form-control" id="procedimiento-{{ $campo['clave'] }}"
                                   name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}">
                        @elseif ($campo['clase'] === 'parrafo')
                            <textarea class="form-control" rows="4" id="procedimiento-{{ $campo['clave'] }}"
                                      name="{{ $campo['clave'] }}">{{ $campo['valor'] }}</textarea>
                        @elseif ($campo['clase'] === 'opcion')
                            <input type="text" class="form-control" maxlength="1" style="max-width: 6rem"
                                   id="procedimiento-{{ $campo['clave'] }}" name="{{ $campo['clave'] }}"
                                   value="{{ $campo['valor'] }}">
                        @else
                            <input type="text" class="form-control" id="procedimiento-{{ $campo['clave'] }}"
                                   name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}">
                        @endif

                        @if (! empty($campo['ayuda']))
                            <p class="form-text mb-0">{{ $campo['ayuda'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3" id="procedimientos-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay datos sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar
                </button>
            </div>
        </form>
        @endif
    </x-ui.tarjeta>
</div>
