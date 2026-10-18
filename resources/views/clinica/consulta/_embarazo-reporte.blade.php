{{-- La pestaña *Reporte* del control de embarazo (WEB-6.6): lo que el escritorio imprime.

     El escritorio no muestra un informe en pantalla —esta pestaña es un botón que imprime `dw_66`—, así que
     acá se ve **el resumen de lo que la hoja lleva** (la ficha de la gesta, sus controles y su laboratorio),
     armado de los mismos mapas del dato que la hoja, y el botón que la manda al motor de documentos.

     Todo lo que se ve acá sale en la hoja; si algo no está, es porque no se cargó. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-embarazo-reporte">
    <x-ui.tarjeta titulo="{{ $vm->titulo() }}" icono="bi-file-earmark-medical">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            <a class="btn btn-primary btn-sm" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
            </a>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        @if (! $vm->hayInforme())
            <p class="texto-tenue small mb-0">
                Esta historia todavía no tiene ninguna gesta, así que no hay informe que imprimir. Cargá la
                primera en la pestaña <em>Prenatal</em>.
            </p>
        @else
        @if (count($vm->gestas()) > 1)
            <x-ui.chips class="mb-3" :opciones="$vm->gestas()" etiqueta="Gestas" />
        @endif

        @if ($vm->faltaLaFur())
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2" role="status" id="reporte-sin-fur">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Esta gesta no tiene FUR: el informe saldrá sin edad gestacional ni FPP. Completa la FUR en la pestaña <em>Prenatal</em>.</span>
            </p>
        @endif

        {{-- La ficha de la gesta, como sale en la hoja. --}}
        <h3 class="h6 mt-2 mb-2">Gesta {{ $vm->clave() }}</h3>
        <dl class="row mb-3">
            @foreach ($vm->ficha() as $dato)
                <dt class="col-6 col-md-3 texto-tenue fw-normal">{{ $dato['etiqueta'] }}</dt>
                <dd class="col-6 col-md-3 mb-1">{{ $dato['valor'] }}</dd>
            @endforeach
        </dl>

        @if ($vm->observaciones() !== null)
            <p class="mb-3"><span class="texto-tenue">Observaciones:</span> {{ $vm->observaciones() }}</p>
        @endif

        {{-- Los controles y el laboratorio: una línea por fecha, sólo con lo cargado. --}}
        @foreach ([['controles', 'Controles'], ['laboratorios', 'Laboratorio']] as [$cual, $rotulo])
            <h3 class="h6 mt-4 mb-2">{{ $rotulo }}</h3>

            @if (! $vm->hay($cual))
                <p class="texto-tenue small mb-0">Sin nada cargado en esta gesta.</p>
            @else
                <ul class="list-unstyled mb-0">
                    @foreach ($vm->filas($cual) as $fila)
                        <li class="mb-2">
                            <span class="fw-semibold">{{ \App\Support\FechaClinica::formato($fila['fecha']) }}</span>
                            @if ($fila['claves'] === [])
                                <span class="texto-tenue small">— sin datos</span>
                            @else
                                <span class="texto-tenue small">
                                    @foreach ($fila['claves'] as $clave)
                                        {{ $clave['etiqueta'] }}: {{ $clave['valor'] }}@if (! $loop->last) · @endif
                                    @endforeach
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach

        {{-- La FPP que sale en la hoja. Desde WEB-11.16 es la misma de la pantalla, así que la nota sólo
             explica la diferencia si alguna vez no lo fueran (un dato viejo). --}}
        <p class="texto-tenue small mt-4 mb-0">{{ $vm->notaDeLaFpp() }}</p>
        @endif
    </x-ui.tarjeta>
</div>
