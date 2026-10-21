{{-- Las plantillas del **formato** del estudio ecográfico (O-17, revisión de Alexander del 2026-10-17).

     No son las plantillas de texto de siempre (esas copian un texto al campo del informe y siguen igual): acá
     la plantilla guarda los **campos descriptivos** del estudio —las opciones del útero y los ovarios, los
     hallazgos, los anexos, las conclusiones— para reusarlos en la próxima paciente. Lo decide
     `PlantillasDelFormato`:

       - **no** guarda las medidas numéricas ni las fechas (son de cada paciente);
       - al aplicarla **sólo completa los campos vacíos**: nunca pisa lo que el médico ya escribió, así que se
         puede aplicar y seguir escribiendo;
       - si el estudio ya tiene dato en esos campos, el panel lo dice («no se pisó nada»).

     El panel va **arriba del formulario** y desde acá se lo alcanza por su `action`: el formulario de cada
     estudio tiene su propio id y la sección no los conoce. La vista no lleva JS: el cajón lo abre y lo cierra
     `shell.js` (`data-abrir-dialogo` / `data-cerrar-dialogo`) y lo suyo lo hace
     `public/js/clinica/plantillas-formato.js`. --}}
@if (($formato ?? null) !== null)
    @php
        $idFormato = 'plantillas-formato-' . $formato['tipo'];
        $cuentaFormato = count($formato['plantillas']);
    @endphp

    <x-ui.dialogo :id="$idFormato" titulo="Plantillas del formato del estudio" lateral>
        <div class="plantillas-cajon" data-formato-cajon data-boton="{{ $idFormato }}-disparador">

            <p class="small mb-1">{{ $formato['queHace'] }}</p>
            <p class="texto-tenue small mb-2">
                Toca los campos de {{ \Illuminate\Support\Str::ucfirst(implode(', ', $formato['bloques'])) }}.
                No guarda ni cambia las medidas ni las fechas.
            </p>

            <label class="visually-hidden" for="{{ $idFormato }}-buscar">Buscar plantilla del formato</label>
            <input type="search" id="{{ $idFormato }}-buscar" class="form-control form-control-sm mb-2"
                   placeholder="Buscar:" autocomplete="off" data-formato-buscar>

            <ul class="plantillas-lista" data-formato-lista>
                @foreach ($formato['plantillas'] as $plantilla)
                    <li data-formato-fila>
                        <button type="button" class="plantillas-item" data-formato-titulo="{{ $plantilla['titulo'] }}"
                                data-formato-valores='@json($plantilla['valores'])'
                                title="Aplicar «{{ $plantilla['titulo'] }}» a los campos vacíos">
                            <i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i>
                            <span>{{ $plantilla['titulo'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
            <p class="texto-tenue small mb-2" data-formato-vacio @if ($cuentaFormato) hidden @endif>
                <span data-formato-sin-plantillas>Todavía no tienes plantillas del formato de este estudio.</span>
                <span data-formato-sin-resultados hidden>Ninguna plantilla coincide con la búsqueda.</span>
            </p>

            <p class="small" data-formato-mensaje role="status" hidden></p>

            <details class="plantillas-guardar" data-formato-guardar>
                <summary class="btn btn-outline-secondary btn-sm"><i class="bi bi-save" aria-hidden="true"></i> Guardar como plantilla</summary>
                <div class="mt-2">
                    <label class="etiqueta" for="{{ $idFormato }}-titulo-plantilla">Título de la plantilla</label>
                    <input type="text" id="{{ $idFormato }}-titulo-plantilla" class="form-control form-control-sm mb-2"
                           maxlength="200" data-formato-titulo-plantilla>
                    <button type="button" class="btn btn-primary btn-sm" data-formato-confirmar>Guardar</button>
                    <p class="small mt-2 mb-0" data-formato-guardado role="status" hidden></p>
                </div>
            </details>

            <div class="dialogo-pie">
                <button type="button" class="btn btn-outline-secondary ms-auto" data-cerrar-dialogo>Cerrar</button>
            </div>
        </div>
    </x-ui.dialogo>
@endif
