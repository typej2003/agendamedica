{{-- La orden de exámenes de laboratorio (WEB-5.4): exámenes del catálogo y rutinas (un tipo completo), con su resumen clínico. Recibe `$vm`
     (OrdenVista). Una por consulta; se arma en el navegador y se guarda entera con el botón. --}}
{{-- «Pareja» (WEB-11.21): pedirle exámenes a la pareja del paciente; abre su propia orden, que se guarda aparte. --}}
@if ($vm->urlPareja() !== '')
    <p class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlPareja() }}">
            <i class="bi bi-people" aria-hidden="true"></i> Pareja
        </a>
        <span class="texto-tenue small ms-2">Pedirle exámenes a la pareja del paciente.</span>
    </p>
@endif
@include('clinica.consulta._orden-editor')
