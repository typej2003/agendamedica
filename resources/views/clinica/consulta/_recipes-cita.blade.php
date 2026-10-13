{{-- La franja de la próxima cita de la sección de récipes (WEB-5.2): lo que saldrá al pie de la hoja y el atajo
     para hacer la cita. Recibe `$seccion` (SeccionDeRecipesVista o SeccionDeRecipeDeLaParejaVista). Sin la
     impresión activa no se muestra. --}}
@if ($seccion->puedeImprimir())
    <div class="recipe-proxima-cita-franja" id="recipe-proxima-cita">
        <p class="recipe-proxima-cita-texto mb-0">
            <i class="bi {{ $seccion->tieneProximaCita() ? 'bi-calendar-check' : 'bi-calendar-x' }} texto-tenue" aria-hidden="true"></i>
            <span>{{ $seccion->textoDeLaProximaCita() }}</span>
        </p>

        <a class="btn btn-sm btn-outline-secondary" href="{{ $seccion->urlHacerCita() }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Hacer cita
        </a>
    </div>
@endif
