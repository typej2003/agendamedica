{{-- El ecocardiograma fetal (WEB-6.3) en la sección de estudio.

     Es el mismo armado que `_estudio-doppler` (WEB-6.3) y `_estudio-obstetrico` (WEB-6.2) —una tarjeta con un
     solo formulario y el aviso de cambios sin guardar—, con dos diferencias: sus **seis bloques** salen del
     mapa del dato (`EcoCardiogramaFetal::grupos()`) y arriba del formulario van la **EG** y la **FPP**, que el
     escritorio calcula de la FUR (sus `compute`) y por eso no son campos.

     Los dos memos del estudio (`texto_inicio` y `anatomi_datos`) van **sin rótulo visible** —el DataWindow no
     se lo pone, porque el bloque ya los titula— y el escritorio los **precarga** al abrir una consulta sin
     fila: el valor por defecto lo pone la lectura (`EcoCardiogramaFetal::deParaLaPantalla`). Los demás campos
     usan el parcial `_estudio-campo`, que ya sabe pintar fecha, número, texto, área y lista. --}}
@php
    $estado = $vm->estado();
    $eg = $vm->edadGestacional();
    $fpp = $vm->fpp();
@endphp

<div class="consulta-seccion" id="consulta-estudio-fetal">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="estudio-fetal-form"
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            @if ($eg !== null || $fpp !== null)
                {{-- La EG y la FPP no son columnas: el escritorio las muestra con un `compute` de la FUR. --}}
                <p class="texto-tenue small mb-3">
                    Calculadas con la FUR:
                    @if ($eg !== null)
                        <strong>EG</strong> {{ $eg }}
                    @endif
                    @if ($fpp !== null)
                        · <strong>FPP</strong> {{ $fpp }}
                    @endif
                </p>
            @endif

            @foreach ($vm->grupos() as $grupo)
                <h3 class="h6 mt-4 mb-2">
                    <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i> {{ $grupo['titulo'] }}
                </h3>

                <div class="row g-3">
                    @foreach ($grupo['campos'] as $campo)
                        @if (($campo['etiqueta'] ?? '') === '')
                            {{-- Los dos memos del estudio: el DataWindow no les pone rótulo, así que van sin
                                 etiqueta visible (con su nombre accesible y su ayuda). --}}
                            <div class="col-12">
                                <textarea class="form-control" rows="6" id="{{ $campo['columna'] }}"
                                          name="{{ $campo['columna'] }}"
                                          aria-label="{{ $campo['ayuda'] }}">{{ $campo['valor'] }}</textarea>
                                <span class="form-text d-block">{{ $campo['ayuda'] }}</span>
                            </div>
                        @else
                            @include('clinica.consulta._estudio-campo', [
                                'campo' => $campo,
                                'columnas' => ($campo['tipo'] ?? 'texto') === 'area' ? 'col-12' : 'col-6 col-md-4',
                            ])
                        @endif
                    @endforeach
                </div>
            @endforeach

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0"
               id="estudio-fetal-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelar
                </a>
                {{-- O-16/O-20: el botón se ofrece siempre que la sección tenga hoja: «Imprimir» **guarda lo escrito y
                     después imprime**, así un estudio recién cargado se imprime sin apretar «Guardar» primero. Si no hay
                     nada que imprimir, la hoja lo dice («Guarda los datos del estudio para poder imprimir»). --}}
                @if ($vm->urlImprimir() !== '')
                    <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="estudio-fetal-form" documento="{{ $vm->documento() }}" class="btn btn-outline-primary">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                    </x-ui.imprimir-guardando>
                @endif
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el estudio
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
