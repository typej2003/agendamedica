{{-- El diálogo de la vista previa («Visualizar», WEB-11.24): común a las solicitudes, los récipes y los ecos. Muestra la hoja de impresión
     dentro de un iframe (la misma página que imprime, con `?incrustado=1` para ocultar su barra) y ofrece «Imprimir» (imprime lo que se
     ve, con la opción «Incluir imágenes» de las ecos) y «Modificar» (cierra y vuelve al formulario). Lo abre cualquier botón con
     `data-visualizar="<url de la hoja>"` (ver `<x-ui.imprimir-con-vista>`) y lo maneja `public/js/clinica/consulta-visualizar.js`. --}}
<dialog id="dialogo-visualizar" class="dialogo-visualizar" aria-labelledby="visualizar-titulo">
    <div class="dialogo-visualizar-caja">
        <div class="dialogo-visualizar-cabecera">
            <h2 class="h5 mb-0" id="visualizar-titulo" data-visualizar-titulo>Vista previa</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-visualizar-modificar>
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Modificar
                </button>
                <button type="button" class="btn btn-primary" data-visualizar-imprimir>
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </button>
            </div>
        </div>
        <iframe class="dialogo-visualizar-marco" title="Vista previa del documento" data-visualizar-marco></iframe>
    </div>
</dialog>
