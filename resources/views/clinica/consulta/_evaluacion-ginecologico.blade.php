{{-- El examen ginecológico (WEB-11.9): chip propio, como la solapa del escritorio. Las cuatro cajas, el resumen
     editable con «Actualizar» (WEB-11.8), el panel de plantillas `EG` y, abajo, el historial de **sólo lectura**
     con el resumen de cada consulta anterior. Se guarda por el mismo formulario/ruta que el examen físico: el
     servidor sólo toca las columnas que llegan. --}}
@php
    $estado = $seccion->estado();
@endphp

<x-ui.tarjeta titulo="Examen ginecológico" icono="bi-gender-female">
    <x-slot name="acciones">
        <x-ui.plantillas :plantillas="$seccion->plantillasGinecologico()" destino="#total_g" tipo="EG"
                         nombre="examen ginecológico" />
        <span class="texto-tenue small me-2">{{ $seccion->cargados() }} de {{ $seccion->totalCampos() }} campos con dato</span>
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="evaluacion-form"
          data-consulta-evaluacion data-autoguardar data-url-resumen="{{ $seccion->urlResumen() }}" data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf
        <input type="hidden" name="pestana" value="ginecologico">

        <h3 class="paso-titulo mb-2">Hallazgos</h3>
        <div class="row g-3 mb-3">
            @foreach ($seccion->ginecologico() as $campo)
                @include('clinica.consulta._evaluacion-campo', ['campo' => $campo, 'columnas' => 'col-12 col-md-6'])
            @endforeach
        </div>

        <div class="row g-3 mb-3" data-resumen="ginecologico">
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="etiqueta mb-0" for="total_g">Resumen del examen ginecológico</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-resumen-actualizar="ginecologico">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Actualizar
                    </button>
                </div>
                <textarea name="total_g" id="total_g" class="form-control" rows="5"
                          placeholder="Aprieta «Actualizar» para armar el resumen con los datos de arriba, o escríbelo.">{{ $seccion->totalGinecologico() }}</textarea>
            </div>
        </div>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="evaluacion-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el examen ginecológico
            </button>
        </div>
    </form>

    {{-- El historial (G3): sólo lectura, lo que se escribió en las consultas anteriores. --}}
    <h3 class="paso-titulo mt-4 mb-2">Resúmenes de consultas anteriores</h3>
    @forelse ($seccion->historial() as $anterior)
        <div class="border rounded p-2 mb-2" data-resumen-anterior>
            <div class="texto-tenue small mb-1">
                Consulta {{ $anterior['numero'] }}@if ($anterior['fecha']) · {{ $anterior['fecha'] }}@endif
            </div>
            <div class="text-break resumen-anterior-texto">{{ $anterior['texto'] }}</div>
        </div>
    @empty
        <p class="texto-tenue mb-0">No hay resúmenes ginecológicos de consultas anteriores.</p>
    @endforelse
</x-ui.tarjeta>
