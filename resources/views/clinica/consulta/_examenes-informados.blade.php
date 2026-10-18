{{-- **Los exámenes informados por el laboratorio** (O-22, 2026-10-17): la lista de sólo lectura que vivía en
     la sección *Exámenes* (ítem 7 del panel, retirada). Ahora se lee dentro de la solapa **Resultado de
     exámenes**, junto a la nota que escribe el médico.

     Los carga el escritorio desde sus solicitudes —el ABM es *Solicitudes → Laboratorio* (WEB-5.4)— y acá se
     leen para tenerlos a la vista, con su nombre resuelto por catálogo, su tipo y su resultado
     (`examen_obs.observacion` es la observación del conjunto, no el resultado de una fila).

     Recibe `$seccion` (el ViewModel `SeccionDeExamenesVista`) y no lleva lógica, estilos ni JS. --}}
@php
    $estado = $seccion->estado();
    $vacio = $seccion->sinExamenes();
@endphp

<x-ui.tarjeta titulo="Exámenes informados por el laboratorio" icono="bi-droplet" class="mt-3">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        @if ($seccion->urlLaboratorio())
            <a class="btn btn-sm btn-outline-secondary" href="{{ $seccion->urlLaboratorio() }}">
                <i class="bi bi-pencil" aria-hidden="true"></i> Pedir o corregir
            </a>
        @endif
    </x-slot>

    @if ($seccion->hayObservacion())
        <x-ui.dato etiqueta="Observación de los exámenes" :valor="$seccion->observacion()" />
    @endif

    @if ($seccion->hay())
        <x-ui.tabla>
            <thead>
                <tr>
                    <th scope="col">Examen</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Resultado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seccion->examenes() as $examen)
                    <tr>
                        <td class="celda-principal" data-etiqueta="Examen">
                            {{ $examen['nombre'] }}
                            <span class="texto-tenue small d-block">Código {{ $examen['codigo'] }}</span>
                            @if ($examen['sinNombre'])
                                <span class="texto-tenue small d-block">{{ $examen['sinNombre'] }}</span>
                            @endif
                        </td>
                        <td data-etiqueta="Tipo">{{ $examen['tipo'] ?: '—' }}</td>
                        <td data-etiqueta="Resultado">{{ $examen['resultado'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.tabla>
    @else
        <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
    @endif
</x-ui.tarjeta>
