{{-- La evaluación física de la consulta (WEB-4.3, ítem 4 del panel del legado): el examen general
     —antropometría, signos y examen por aparatos— y el ginecológico, con **guardado explícito**.

     Todo lo editable va en **un solo formulario**: son veinte columnas repartidas en dos tablas
     (`consultas` y `examen_fisico_nuevo`) y el médico aprieta "Guardar" una vez. El aviso de "cambios sin
     guardar" y el bloqueo del navegador al salir los pone `consulta.js` desde los `data-*`: la vista no
     lleva JS ni estilos.

     Los resúmenes narrativos (`total_f` general y `total_g` ginecológico) los escribe el botón *Actualizar*
     del escritorio y acá se **muestran sin editarlos**: regenerarlos borraría texto clínico del escritorio
     y el ginecológico incluye colposcopia y citología. Por eso van **fuera** del formulario: no son un dato
     que la web mande.

     Es la solapa *Evaluación física* del contenedor (`_evaluacion`): recibe `$seccion` con su ViewModel.
     La colposcopía se carga en su propia solapa (`_evaluacion-colposcopia`, WEB-6.5). --}}
@php
    $estado = $seccion->estado();
@endphp

<x-ui.tarjeta titulo="Examen físico" icono="bi-heart-pulse">
    <x-slot name="acciones">
        <span class="texto-tenue small me-2">{{ $seccion->cargados() }} de {{ $seccion->totalCampos() }} campos con dato</span>
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="evaluacion-form"
          data-consulta-evaluacion data-autoguardar data-url-resumen="{{ $seccion->urlResumen() }}" data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf
        <input type="hidden" name="pestana" value="fisico">

        {{-- El panel de plantillas del resumen (`RF`) va **arriba** de todo: en el escritorio es una columna a la
             derecha que arranca arriba y acá vivía dentro del bloque del resumen, al final de la página
             (pedido del usuario, 2026-10-16). --}}
        <x-ui.plantillas :plantillas="$seccion->plantillasFisico()" destino="#total_f" tipo="RF"
                         nombre="resumen físico" class="mb-3" />

        {{-- 1. Antropometría, signos y tensión: las siete columnas `double` de `consultas` (peso, talla,
             FC, FR y las tres circunferencias) más `examen_fisico_nuevo.tension`. --}}
        <h3 class="paso-titulo mb-2">Antropometría y signos</h3>
        <div class="row g-3 mb-3">
            @foreach ($seccion->antropometria() as $campo)
                @include('clinica.consulta._evaluacion-campo', ['campo' => $campo, 'columnas' => 'col-6 col-md-4'])
            @endforeach

            {{-- El IMC: derivado (peso / talla²), como en el escritorio (`gffimc.srf`). No se pide ni se
                 guarda en ninguna columna; `consulta.js` lo recalcula al escribir peso o talla. --}}
            <x-ui.campo etiqueta="IMC" for="evaluacion-imc" class="col-6 col-md-4"
                        ayuda="Calculado del peso y la talla. No se guarda.">
                <output class="form-control-plaintext fw-semibold" id="evaluacion-imc"
                        data-evaluacion-imc>{{ $seccion->imc() }}</output>
            </x-ui.campo>
        </div>

        {{-- 2. Examen por aparatos: las ocho columnas de `examen_fisico_nuevo` de la solapa "Examen
             Físico" del escritorio. --}}
        <h3 class="paso-titulo mb-2">Examen por aparatos</h3>
        <div class="row g-3 mb-3">
            @foreach ($seccion->general() as $campo)
                @include('clinica.consulta._evaluacion-campo', ['campo' => $campo, 'columnas' => 'col-12 col-md-6'])
            @endforeach
        </div>

        {{-- El resumen narrativo (WEB-11.8): lo arma «Actualizar» con los datos no vacíos de arriba, es **editable** y se guarda con la sección.
             Si ya hay un texto distinto del que se armaría, «Actualizar» pide confirmación antes de reemplazarlo. --}}
        <div class="row g-3 mb-3" data-resumen="fisico">
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="etiqueta mb-0" for="total_f">Resumen del examen físico</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-resumen-actualizar="fisico">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Actualizar
                    </button>
                </div>
                <textarea name="total_f" id="total_f" class="form-control" rows="5"
                          placeholder="Aprieta «Actualizar» para armar el resumen con los datos de arriba, o escríbelo.">{{ $seccion->totalFisico() }}</textarea>
            </div>
        </div>

        {{-- El aviso de "hay cambios sin guardar": `consulta.js` lo muestra cuando algún control del
             formulario cambió respecto de lo guardado. --}}
        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="evaluacion-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            {{-- La única acción primaria de la solapa: guardar lo cargado. --}}
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el examen físico
            </button>
        </div>
    </form>
</x-ui.tarjeta>
