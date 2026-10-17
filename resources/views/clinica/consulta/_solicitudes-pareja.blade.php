{{-- La orden de laboratorio de la pareja (WEB-11.21, el diálogo «Examen de la Pareja» del escritorio, rediseñado): se abre con el botón «Pareja» del
     laboratorio y es la misma pantalla de la orden con la C.I. y el nombre de la pareja arriba. Se guarda y, al volver, se encuentra como se dejó.
     Recibe `$vm` (OrdenVista::pareja). --}}
<p class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlVolver() }}">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al laboratorio del paciente
    </a>
</p>
@include('clinica.consulta._orden-editor')
