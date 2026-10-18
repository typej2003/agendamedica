{{--
    **«¿Atender ahora?»** (O-05, 2026-10-17): el diálogo que la ficha muestra **cuando el paciente acaba de
    quedar atendible** —después del alta web (O-04) o de asignarle la historia—, que es el momento del flujo
    del legado: se le asigna el número y se lo pasa a la consulta.

    Es la confirmación del patrón de la agenda (`clinica/agenda/_dialogo-atender`): un solo `<dialog>` y sólo
    dos opciones. «Ahora no» es cerrar el diálogo (`data-cerrar-dialogo`, sin POST): el paciente queda en su
    ficha, que es lo que la observación pide para «después». El «sí» va a `POST /clinica/pacientes/{id}/atender`,
    donde `App\Clinica\Consulta\AtencionInmediata` abre la cita de hoy si existe o se la crea.

    La página lo abre sola al cargar (`data-abrir-al-cargar`, `public/js/clinica/atender-paciente.js`): el
    formulario acaba de redirigir a la ficha, así que la pregunta tiene que estar delante y no escondida
    detrás de un botón que el usuario no sabe que existe.

    Recibe `$vm` (App\Clinica\Pacientes\Vistas\FichaDePacienteVista) con su `urlAtender()` ya resuelta.
--}}
<x-ui.dialogo id="dialogo-atender-paciente" titulo="¿Atender ahora?" data-abrir-al-cargar="1">
    <form method="POST" action="{{ $vm->urlAtender() }}">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="mb-2">
                Paciente: <strong>{{ $vm->nombre() }}</strong> — historia H. {{ $vm->numeroDeHistoria() }}.
            </p>

            <p class="form-text mb-0">
                Se abre la consulta del día. Si el paciente ya tiene una cita hoy sin atender, se abre esa y
                queda atendida. Si no la tiene, se le crea una cita confirmada en el consultorio elegido, con
                la hora de su horario. Si hoy no es un día laborable o el consultorio no tiene horario, no se
                crea nada y se te dice por qué.
            </p>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Ahora no</button>
            <button class="btn btn-primary">Atender ahora</button>
        </div>
    </form>
</x-ui.dialogo>
