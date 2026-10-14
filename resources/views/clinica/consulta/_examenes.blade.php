{{-- Los exámenes y las imágenes de la consulta (WEB-4.7, ítem 7 del panel del legado).

     Son dos tarjetas porque son dos cosas distintas:

       1. **Los exámenes de laboratorio de la consulta** (`examen_paciente`, la pestaña "Exámenes de
          laboratorio" del escritorio, `w_consulta_principal.srw:16235-16246`): **sólo lectura**. Los carga
          el escritorio desde sus solicitudes —el ABM es F5/WEB-5.4— y acá se leen para tenerlos a la vista,
          con su nombre resuelto por catálogo, su tipo y su resultado (`examen_obs.observacion` es la
          observación del conjunto, no el resultado de una fila).
       2. **Las imágenes de los estudios** (`imagen_consulta`, la misma tabla que el escritorio llena con
          la ruta del archivo de la PC, `:10744`): la lista de las que ya están y el formulario para subir
          una nueva. El archivo va al **disco privado** del API y se abre por una ruta autorizada que
          comprueba que la historia es de este médico (ver `App\Clinica\Archivos\ArchivosClinicos`).

     La vista no lleva lógica, estilos ni JS: el aviso de "elegiste una imagen y no la subiste" lo pone
     `consulta.js` desde los `data-*` de este formulario. --}}
@php
    $estado = $seccion->estado();
    $vacio = $seccion->sinExamenes();
@endphp

<div class="consulta-seccion" id="consulta-examenes">
    {{-- 1) Los exámenes que tiene pedidos la consulta. --}}
    <x-ui.tarjeta titulo="Exámenes de esta consulta" icono="bi-droplet">
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

    {{-- 2) Las imágenes: la lista y la subida. Es el parcial compartido con las solapas del examen
         (WEB-6.5): acá son **todas** las de la consulta y por eso pide descripción. --}}
    @include('clinica.consulta._imagenes', [
        'imagenes' => $imagenes,
        'titulo' => 'Imágenes de los estudios',
        'prefijo' => 'examenes',
    ])
</div>
