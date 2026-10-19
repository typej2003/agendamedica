{{-- La solapa de **un texto del examen** (WEB-6.5): la colposcopía (`tabpage_31`), la citología (`tabpage_33`),
     la histeroscopía (`tabpage_49`) o el resultado de exámenes (`tabpage_48`).

     Las cuatro son la misma pantalla —un texto con las plantillas del escritorio al lado, el aviso de cambios
     sin guardar y, si la solapa tiene, sus fotos y su botón de imprimir—, así que hay **un solo** parcial y el
     dato sale de `App\Clinica\Consulta\TextosDelExamen` (la columna de `consultas`, el `tipo` de plantillas y la
     hoja de cada una). El campo y los ids se llaman como la **clave de la solapa** (`colposcopia`, `citologia`,
     …): el aviso de cambios sin guardar y las plantillas los ata `consulta.js` desde los `data-*` del formulario
     (el aviso es el id del formulario más `-sin-guardar`). La vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
    $clave = $seccion->clave();
@endphp

<x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="{{ $clave }}-form"
          data-consulta-plantillas data-autoguardar data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        {{-- El panel de plantillas (WEB-11.2) va **arriba** del campo: en el escritorio es una columna a la
             derecha y acá, al no caber, caía debajo del texto (pedido del usuario, 2026-10-16). La
             histeroscopía no tiene plantillas en el escritorio. --}}
        @if ($seccion->tienePlantillas())
            <x-ui.plantillas :plantillas="$seccion->plantillas()" destino="#{{ $seccion->campo() }}"
                             :tipo="$seccion->tipoDePlantillas()" :nombre="mb_strtolower($seccion->titulo())" class="mb-3" />
        @endif

        <label class="etiqueta" for="{{ $seccion->campo() }}">{{ $seccion->titulo() }}</label>
        <textarea name="{{ $seccion->campo() }}" id="{{ $seccion->campo() }}" class="form-control" rows="12"
                  data-plantilla-destino placeholder="{{ $seccion->marcador() }}">{{ $seccion->valor() }}</textarea>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="{{ $clave }}-form-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            @if ($seccion->esUnDocumento() && $seccion->urlImprimir() !== '')
                {{-- «Imprimir» guarda el texto y después imprime la hoja (O-16): es un `submit` de este mismo formulario y el
                     servidor lo redirige a la hoja del documento de la solapa. --}}
                <x-ui.imprimir-guardando :url="$seccion->urlImprimir()" form="{{ $clave }}-form"
                                         documento="{{ $seccion->documento() }}" class="btn btn-outline-primary">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                </x-ui.imprimir-guardando>
            @endif
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $seccion->rotuloGuardar() }}
            </button>
        </div>
    </form>

    {{-- **La imagen del examen con sus dos modos** (O-09: la colposcopía y la histeroscopía): una sola tarjeta con el
         radiobutón entre la imagen predeterminada y el videocolposcopio, sus lienzos para marcar y —en el modo de la
         foto— las fotos del estudio con su subida. Es una tarjeta más de la solapa; el texto, sus plantillas y su
         impresión no cambian. La vista la recibe ya armada (`ImagenDelExamenVista`). --}}
    @if (($imagen ?? null) !== null)
        <div class="mt-3">
            @include('clinica.consulta._imagen-del-examen', ['seccion' => $imagen])
        </div>
    @endif

    {{-- Los exámenes informados por el laboratorio (O-22): la lista de sólo lectura de la sección *Exámenes*
         retirada, dentro de su solapa *Resultado de exámenes*, junto a la nota del médico. --}}
    @if ($seccion->informados() !== null)
        @include('clinica.consulta._examenes-informados', ['seccion' => $seccion->informados()])
    @endif
</x-ui.tarjeta>
