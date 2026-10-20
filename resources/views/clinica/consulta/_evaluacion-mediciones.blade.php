{{-- Las mediciones de la consulta (WEB-7.4.3a): la solapa *Mediciones* de la evaluación física de pediatría (`tabpage_29` de
     `w_consulta_principal`). Se guardan solas, como el resto de la consulta (`data-autoguardar`, ver `consulta-barra.js`).

     El IMC y el «AC» se calculan en pantalla con las fórmulas del escritorio (`consulta-mediciones.js`, que replica a
     `MedidasAntropometricas`) y no se piden: el servidor guarda el IMC y recalcula todo al volver a mostrarlo. Dónde cae cada medida
     respecto a las curvas sale de la referencia del consultorio; si no hay, se dice por qué. Sin estilos ni JS inline. --}}
<x-ui.tarjeta titulo="Mediciones" icono="bi-rulers">
    <x-slot name="acciones">
        @if ($seccion->edad())
            <span class="texto-tenue small me-2">Edad en esta consulta: {{ $seccion->edad() }}</span>
        @endif
        @if ($seccion->urlCrecimiento())
            <a class="btn btn-outline-primary" href="{{ $seccion->urlCrecimiento() }}">
                <i class="bi bi-graph-up" aria-hidden="true"></i> Ver el crecimiento
            </a>
        @endif
    </x-slot>

    <p class="texto-tenue small mb-3">
        Peso en kilogramos y talla y perímetros en centímetros. Todas son opcionales; lo que escribas se guarda solo.
    </p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="mediciones-form"
          data-autoguardar data-consulta-mediciones data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3 mb-3">
            @foreach ($seccion->campos() as $campo)
                <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'medicion-' . $campo['columna']" class="col-6 col-md-4"
                            :error="$campo['columna']">
                    <div class="examen-unidad">
                        <input type="text" inputmode="decimal" class="form-control" autocomplete="off"
                               id="medicion-{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                               value="{{ $campo['valor'] }}" data-medicion="{{ $campo['columna'] }}">
                        <span>{{ $campo['unidad'] }}</span>
                    </div>
                    @if ($campo['posicion'])
                        <span class="form-text d-block {{ $campo['fuera'] ? 'text-danger' : '' }}">{{ $campo['posicion'] }}</span>
                    @endif
                </x-ui.campo>
            @endforeach
        </div>

        <h3 class="paso-titulo mb-2">Calculados</h3>
        <div class="row g-3 mb-3">
            <x-ui.campo etiqueta="IMC" for="medicion-imc" class="col-6 col-md-4"
                        ayuda="Peso entre talla al cuadrado. Se guarda con las mediciones.">
                <output class="form-control-plaintext fw-semibold" id="medicion-imc" data-medicion-imc>{{ $seccion->imc() }}</output>
                @if ($seccion->posicionDelImc())
                    <span class="form-text d-block">{{ $seccion->posicionDelImc() }}</span>
                @endif
            </x-ui.campo>
            <x-ui.campo etiqueta="AC" for="medicion-ac" class="col-6 col-md-4"
                        info="Superficie corporal calculada. No se guarda.">
                <output class="form-control-plaintext fw-semibold" id="medicion-ac" data-medicion-ac>{{ $seccion->ac() }}</output>
            </x-ui.campo>
        </div>

        @unless ($seccion->comparado())
            <x-ui.estado tono="aviso" icono="bi-info-circle" class="mb-3">{{ $seccion->avisoDeReferencia() }}</x-ui.estado>
        @endunless

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar las mediciones
            </button>
        </div>
    </form>
</x-ui.tarjeta>
