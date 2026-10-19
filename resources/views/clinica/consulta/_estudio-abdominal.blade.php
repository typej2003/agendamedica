{{-- El eco abdominal (WEB-6.4) en la sección de estudio.

     Es el mismo armado que `_estudio-mamario` (WEB-6.4) —una tarjeta con un solo formulario y el aviso de
     cambios sin guardar—, con cuatro bloques que salen del mapa del dato (`EcoAbdominal::grupos()`), el
     **desplegable de médicos** de `referido` (que guarda la cédula del colega; sus opciones son la libreta
     del médico y las arma `EcoAbdominalVista` con su opción vacía adelante, trampa 112), los cuatro
     desplegables con los códigos del DataWindow (`vol_res_v_p_m`, `ascitis`, `retroperitoneo` y
     `vasos_sanguineos`) y el **informe en texto libre** del escritorio (`puro_texto`, su modo «Plantillas»),
     que se muestra y no se edita.

     Los campos sueltos usan el parcial `_estudio-campo`, que ya sabe pintar fecha, texto, área y lista. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-estudio-abdominal">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="estudio-abdominal-form" novalidate
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            {{-- Cuando el eco se abre desde **Procedimientos** (gastroenterología pediátrica, 2026-10-19), el
                 guardado vuelve a esa sección y no a la del estudio. --}}
            @if (! empty($volverA))
                <input type="hidden" name="volver" value="{{ $volverA }}">
            @endif

            @foreach ($vm->grupos() as $grupo)
                <h3 class="h6 mt-4 mb-2">
                    <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i> {{ $grupo['titulo'] }}
                </h3>

                @include('clinica.consulta._estudio-plantillas', ['grupo' => $grupo])

                <div class="row g-3">
                    @foreach ($grupo['campos'] as $campo)
                        @include('clinica.consulta._estudio-campo', [
                            'campo' => $campo,
                            'columnas' => ($campo['tipo'] ?? 'texto') === 'area' ? 'col-12' : 'col-6 col-md-4',
                        ])
                    @endforeach
                </div>
            @endforeach

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0"
               id="estudio-abdominal-form-sin-guardar" hidden>
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
                    <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="estudio-abdominal-form" documento="{{ $vm->documento() }}" class="btn btn-outline-primary">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                    </x-ui.imprimir-guardando>
                @endif
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el estudio
                </button>
            </div>
        </form>
    </x-ui.tarjeta>

    {{-- El informe en texto libre del escritorio: fuera del formulario porque es de lectura. --}}
    @if ($vm->hayPuroTexto())
        <x-ui.tarjeta titulo="Informe en texto libre del escritorio" icono="bi-card-text" class="mt-3">
            <p class="texto-tenue small mb-2">
                Lo escribió el escritorio con su modo <em>Plantillas</em>. Se muestra tal como quedó y todavía
                no se edita desde acá.
            </p>
            <div class="examen-resumen">{{ $vm->puroTexto() }}</div>
        </x-ui.tarjeta>
    @endif
</div>
