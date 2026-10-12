{{-- La vista previa (WEB-8.2): la misma hoja que imprime el récipe (WEB-5.2), con un paciente y unos medicamentos de ejemplo y el
     formato GUARDADO del médico. Es un <iframe> a la ruta de la hoja de prueba: el CSS y los componentes de la impresión no se
     duplican. Al guardar una sección la página se vuelve a cargar y la vista previa con ella. --}}
<div class="formato-previa">
    <x-ui.tarjeta titulo="Vista previa" icono="bi-eye">
        <x-slot name="acciones">
            <x-ui.estado tono="info" icono="bi-floppy">Muestra lo guardado</x-ui.estado>
        </x-slot>

        <iframe class="formato-previa-marco" id="formato-previa" src="{{ $vm->urlVistaPrevia() }}"
                title="Vista previa de la hoja con datos de ejemplo"></iframe>

        <p class="form-text mb-0 mt-2">
            El paciente y los medicamentos son de ejemplo. Los bloques sin texto —sin especialidad, RIF o pie— no se imprimen.
            Los cambios se ven aquí cuando guardas cada sección.
        </p>
    </x-ui.tarjeta>
</div>
