{{-- El eco obstétrico del primer trimestre (WEB-6.2) en la sección de estudio.

     Es el mismo armado que `_estudio-ginecologico` (WEB-6.1) —una tarjeta con un solo formulario y el aviso
     de cambios sin guardar—, con dos diferencias: los bloques y los campos salen del mapa del dato del
     obstétrico (`EcoObstetrico::grupos()`, seis bloques) y aquí **no hay unidades** en el informe (sus
     medidas son texto libre, con los `mm` que el escritorio escribe en la columna).

     Los campos usan el parcial `_estudio-campo`, que ya sabe pintar fecha, número, texto, área y lista. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-estudio-obstetrico">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="estudio-obstetrico-form"
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            @foreach ($vm->grupos() as $grupo)
                <h3 class="h6 mt-4 mb-2">
                    <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i> {{ $grupo['titulo'] }}
                </h3>

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
               id="estudio-obstetrico-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelar
                </a>
                @if ($vm->esUnDocumento())
                    <a class="btn btn-outline-primary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                    </a>
                @endif
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el estudio
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
