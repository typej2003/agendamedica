{{-- El eco Doppler perinatal (WEB-6.3) en la sección de estudio.

     Es el mismo armado que `_estudio-obstetrico` (WEB-6.2) —una tarjeta con un solo formulario y el aviso de
     cambios sin guardar—, con tres diferencias: sus cuatro bloques salen del mapa del dato
     (`EcoDoppler::grupos()`), el bloque de la **grilla de cuatro vasos** se pinta como una tabla (por eso su
     grupo trae `grilla`), y el **informe en texto libre** del escritorio (`puro_texto`, su modo «Plantillas»)
     se muestra y no se edita, como en el eco pélvico de WEB-6.1.

     Los campos sueltos usan el parcial `_estudio-campo`, que ya sabe pintar fecha, número, texto, área y
     lista; el memo grande del estudio va sin rótulo porque el DataWindow tampoco se lo pone. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-estudio-doppler">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="estudio-doppler-form"
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            @foreach ($vm->grupos() as $grupo)
                <h3 class="h6 mt-4 mb-2">
                    <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i> {{ $grupo['titulo'] }}
                </h3>

                @include('clinica.consulta._estudio-plantillas', ['grupo' => $grupo])

                @if ($grupo['grilla'])
                    {{-- La grilla de cuatro vasos: una fila por arteria y una columna por índice, con los
                         rótulos del DataWindow. `sin-tarjetas` porque una tabla de medidas apilada como
                         tarjetas no se lee; en celular se desplaza dentro de su contenedor. --}}
                    <x-ui.tabla sin-tarjetas>
                        <thead>
                            <tr>
                                <th scope="col">Vasos</th>
                                @foreach ($grupo['grilla']['ejes'] as $eje)
                                    <th scope="col">{{ $eje }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grupo['grilla']['filas'] as $fila)
                                <tr>
                                    <th scope="row">{{ $fila['etiqueta'] }}</th>
                                    @foreach ($fila['campos'] as $campo)
                                        <td>
                                            <label class="form-label visually-hidden" for="{{ $campo['columna'] }}">
                                                {{ $fila['etiqueta'] }}: {{ $campo['eje'] }}
                                            </label>
                                            <input type="text" class="form-control"
                                                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                                                   value="{{ $campo['valor'] }}">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.tabla>
                @else
                    <div class="row g-3">
                        @foreach ($grupo['campos'] as $campo)
                            @if (($campo['etiqueta'] ?? '') === '')
                                {{-- El memo grande del estudio: el DataWindow no le pone rótulo, así que va sin
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
                @endif
            @endforeach

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0"
               id="estudio-doppler-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelar
                </a>
                @if ($vm->esUnDocumento() && $vm->urlImprimir() !== '')
                    <x-ui.imprimir-con-vista :url="$vm->urlImprimir()" class="btn btn-outline-primary">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                    </x-ui.imprimir-con-vista>
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
