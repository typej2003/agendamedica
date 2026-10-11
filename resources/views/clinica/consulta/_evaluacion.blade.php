{{-- La evaluación física de la consulta (WEB-4.3, ítem 4 del panel del legado): el examen general
     —antropometría, signos y examen por aparatos— y el ginecológico, con **guardado explícito**.

     Todo lo editable va en **un solo formulario**: son veinte columnas repartidas en dos tablas
     (`consultas` y `examen_fisico_nuevo`) y el médico aprieta "Guardar" una vez. El aviso de "cambios sin
     guardar" y el bloqueo del navegador al salir los pone `consulta.js` desde los `data-*`: la vista no
     lleva JS ni estilos.

     Los resúmenes narrativos (`total_f` general y `total_g` ginecológico) los escribe el botón *Actualizar*
     del escritorio y acá se **muestran sin editarlos**: regenerarlos borraría texto clínico del escritorio
     y el ginecológico incluye colposcopia y citología, que son de F6. Por eso van **fuera** del formulario:
     no son un dato que la web mande. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-evaluacion">
    <x-ui.tarjeta titulo="Evaluación física" icono="bi-heart-pulse">
        <x-slot name="acciones">
            <span class="texto-tenue small me-2">{{ $seccion->cargados() }} de {{ $seccion->totalCampos() }} campos con dato</span>
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="evaluacion-form"
              data-consulta-evaluacion data-aviso="{{ $seccion->avisoSinGuardar() }}">
            @csrf

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

            {{-- 3. Examen ginecológico: las cuatro columnas de la solapa homónima. La colposcopia y la
                 citología son de F6 y no se editan acá (TODO-WEB.md WEB-4.3, PLAN-WEB.md §3.16). --}}
            <h3 class="paso-titulo mb-2">Examen ginecológico</h3>
            <div class="row g-3 mb-2">
                @foreach ($seccion->ginecologico() as $campo)
                    @include('clinica.consulta._evaluacion-campo', ['campo' => $campo, 'columnas' => 'col-12 col-md-6'])
                @endforeach
            </div>

            <p class="texto-tenue small mb-0">
                La colposcopia y la citología no se cargan acá: tienen su propio informe y llegan con F6.
            </p>

            {{-- El aviso de "hay cambios sin guardar": `consulta.js` lo muestra cuando algún control del
                 formulario cambió respecto de lo guardado. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="evaluacion-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                {{-- La única acción primaria de la sección: guardar lo cargado. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la evaluación física
                </button>
            </div>
        </form>
    </x-ui.tarjeta>

    {{-- Los resúmenes narrativos del escritorio, fuera del formulario: son de lectura. --}}
    @if ($seccion->hayResumenes())
        <x-ui.tarjeta titulo="Resúmenes del escritorio" icono="bi-card-text" class="mt-3">
            <p class="texto-tenue small mb-2">
                Los arma el botón <em>Actualizar</em> del examen físico del escritorio. Se muestran tal como
                quedaron y no se regeneran desde acá.
            </p>

            @if ($seccion->resumenGeneral())
                <div class="examen-resumen">
                    <span class="examen-resumen-titulo">Examen físico general</span>
                    {{ $seccion->resumenGeneral() }}
                </div>
            @endif

            @if ($seccion->resumenGinecologico())
                <div class="examen-resumen">
                    <span class="examen-resumen-titulo">Examen ginecológico</span>
                    {{ $seccion->resumenGinecologico() }}
                </div>
            @endif
        </x-ui.tarjeta>
    @endif
</div>
