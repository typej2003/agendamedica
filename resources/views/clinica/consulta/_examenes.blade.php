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
    $estadoImagenes = $imagenes->estado();
    $vacioImagenes = $imagenes->sinImagenes();
    $avisoEscritorio = $imagenes->avisoDelEscritorio();
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

    {{-- 2) Las imágenes: la lista y la subida. --}}
    <x-ui.tarjeta titulo="Imágenes de los estudios" icono="bi-images" class="mt-3">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estadoImagenes['tono']" :icono="$estadoImagenes['icono']"
                         :title="$estadoImagenes['titulo']">{{ $estadoImagenes['texto'] }}</x-ui.estado>
        </x-slot>

        @if ($avisoEscritorio)
            <p class="alert alert-info py-2 d-flex align-items-start gap-2" id="examenes-aviso-escritorio">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>{{ $avisoEscritorio }}</span>
            </p>
        @endif

        @if ($imagenes->hay())
            <ul class="list-unstyled mb-3" id="examenes-imagenes">
                @foreach ($imagenes->imagenes() as $imagen)
                    <li class="examen-imagen d-flex flex-wrap align-items-start gap-3">
                        <span class="examen-imagen-icono" aria-hidden="true"><i class="bi bi-image"></i></span>
                        <div class="flex-grow-1">
                            <p class="mb-1">
                                {{ $imagen['descripcion'] ?: 'Sin descripción' }}
                            </p>
                            <p class="texto-tenue small mb-0">
                                {{ $imagen['nombre'] ?: 'Imagen sin nombre' }} · {{ $imagen['fecha'] }} ·
                                {{ $imagen['origen'] }}
                            </p>
                            <p class="texto-tenue small mb-0">{{ $imagen['detalle'] }}</p>
                        </div>
                        <div class="acciones-fila">
                            @if ($imagen['puedeVerse'] && $imagen['esDescarga'])
                                <a class="btn btn-outline-secondary btn-sm" href="{{ $imagen['url'] }}" download
                                   aria-label="Descargar la imagen {{ $imagen['nombre'] ?: $imagen['id'] }}">
                                    <i class="bi bi-download" aria-hidden="true"></i> Descargar
                                </a>
                            @elseif ($imagen['puedeVerse'])
                                <a class="btn btn-outline-secondary btn-sm" href="{{ $imagen['url'] }}"
                                   target="_blank" rel="noopener"
                                   aria-label="Ver la imagen {{ $imagen['nombre'] ?: $imagen['id'] }}">
                                    <i class="bi bi-eye" aria-hidden="true"></i> Ver
                                </a>
                            @endif
                            <form method="POST" action="{{ $imagen['urlQuitar'] }}"
                                  data-confirmar="¿Quitar esta imagen de la consulta? Se borra el archivo del servidor y no se puede deshacer.">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <x-ui.vacio :icono="$vacioImagenes['icono']" :titulo="$vacioImagenes['titulo']">{{ $vacioImagenes['texto'] }}</x-ui.vacio>
        @endif

        {{-- La subida. `multipart/form-data` es obligatorio para que el archivo viaje; el nombre del
             archivo lo pone el servidor (el del cliente no se usa). --}}
        <form method="POST" action="{{ $imagenes->urlSubir() }}" enctype="multipart/form-data"
              id="examenes-subida" data-consulta-archivo data-aviso="{{ $imagenes->avisoSinGuardar() }}">
            @csrf

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <x-ui.campo etiqueta="Imagen" for="imagen-archivo" error="imagen" requerido
                                :ayuda="$imagenes->ayudaArchivo()">
                        <input type="file" name="imagen" id="imagen-archivo" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp">
                    </x-ui.campo>
                </div>
                <div class="col-12 col-md-6">
                    <x-ui.campo etiqueta="Descripción" for="imagen-descripcion" error="descripcion"
                                :ayuda="$imagenes->ayudaDescripcion()">
                        <input type="text" name="descripcion" id="imagen-descripcion" class="form-control"
                               maxlength="200" placeholder="Colposcopia: ectopia leve"
                               value="{{ $imagenes->descripcionElegida(old('descripcion', '')) }}">
                    </x-ui.campo>
                </div>
            </div>

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="examenes-sin-subir" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Elegiste una imagen y todavía no la subiste.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button class="btn btn-primary">
                    <i class="bi bi-upload" aria-hidden="true"></i> Subir la imagen
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
