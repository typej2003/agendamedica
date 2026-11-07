{{-- **Inmunizaciones** de la consulta pediátrica (ítem 7 del árbol de PediaReport, O-63 de Alexander; O-68/O-69): las vacunas
     del niño **dentro** de la consulta, con la barra y el menú de la consulta.

     Antes el ítem **sacaba** de la consulta: `ConsultaController::destinoDeLaSeccionPediatrica()` redirigía a la pantalla del
     módulo (`/clinica/pacientes/{paciente}/vacunas`) y el médico perdía la atención en curso. Ahora la pantalla del módulo y
     esta sección son **la misma**: los parciales `clinica.vacunas._lista` (las filas del paciente y «Agregar una vacuna») y
     `clinica.vacunas._calendario` (el esquema del catálogo, plegado) con el ViewModel `CalendarioDeVacunasVista`, que trae las
     URLs ya resueltas. Registrar, programar o quitar una dosis vuelve acá (`VacunasDelPacienteController`), y el carnet
     imprimible sigue a mano.

     El `$seccion` es el `CalendarioDeVacunasVista` que arma `ConsultaController::inmunizaciones()`. --}}
<div class="consulta-seccion" id="consulta-inmunizaciones">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h2 class="card-titulo mb-0">
            <i class="bi bi-shield-plus" aria-hidden="true"></i> Inmunizaciones
        </h2>

        @if ($seccion->urlCarnet() && ! $seccion->sinCatalogo())
            {{-- El carnet (WEB-7.4.4c): la misma hoja que ofrece el módulo del niño, sin salir de la consulta. --}}
            <x-ui.imprimir-con-vista :url="$seccion->urlCarnet()" titulo="Carnet de vacunación">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el carnet
            </x-ui.imprimir-con-vista>
        @endif
    </div>

    @include('clinica.vacunas._lista', ['vm' => $seccion])
    @include('clinica.vacunas._calendario', ['vm' => $seccion])

    @if ($seccion->puedeEscribir())
        @include('clinica.vacunas._dialogo-registrar', ['vm' => $seccion])
    @endif
</div>
