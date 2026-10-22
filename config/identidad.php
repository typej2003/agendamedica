<?php

/*
|--------------------------------------------------------------------------
| Identidad de los pacientes-cuenta (niveles 0, 1 y 2)
|--------------------------------------------------------------------------
|
| Nivel 0: cuenta propia sin validar. Nivel 1: teléfono validado por código. Nivel 2: cédula confirmada por
| el personal del consultorio. Los dos plazos viven aquí y en ningún otro lado (`App\Paciente\NivelesDeIdentidad`).
|
*/

return [
    // Meses que dura la validación de un teléfono por código; pasado el plazo hay que confirmarlo o cambiarlo.
    'telefono_validacion_meses' => 12,

    // Meses de vigencia de un bloqueo por teléfono (renovable al volver a bloquear).
    'bloqueo_telefono_meses' => 6,

    // Límites preventivos de reserva (`App\Reservas\LimitesDeReserva`). Sólo aplican a lo que el paciente hace
    // por su cuenta (portal, reserva pública, chatbot); la secretaría y el médico agendan sin este tope.
    // Máximo de citas pendientes (futuras, sin atender) por persona (cuenta o teléfono validado).
    'max_citas_pendientes' => 3,

    // Máximo de códigos de verificación que se envían por hora a un mismo teléfono.
    'max_codigos_por_hora' => 5,
];
