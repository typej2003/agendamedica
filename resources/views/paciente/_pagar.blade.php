{{-- El botón «Pagar» de una cita en «Mis citas» (punto 4 del cobro de citas): sólo si la cita debe algo
     y no es de un día pasado. Abre la ruta del paciente, que genera o reusa el link y manda a `/pagar/{token}`. --}}
@if ($cita->puedePagar())
    <div class="paciente-cita-acciones">
        <a href="{{ route('paciente.citas.pagar', $cita->id()) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-credit-card" aria-hidden="true"></i> Pagar
        </a>
    </div>
@endif
