<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Historia;

/**
 * Lo que devolvió `AbrirConsulta`: la consulta de la cita, la historia a la que cuelga y de qué paciente
 * es.
 *
 * El `paciente_id` y la `Historia` viajan porque abrir la consulta **resolvió el vínculo del paciente**
 * para llegar a su historia (la cita lo tiene por `numhistoria` o por `paciente_sinhistoria_id`) y quien
 * llama los necesita para la pantalla: sin ellos habría que repetir la misma resolución —y podría dar
 * distinto—. El encabezado de la consulta muestra el número de historia **del pivote**, que es el
 * formato del consultorio (`00001`) y no el entero de `consultas`.
 */
final class ConsultaAbierta
{
    public function __construct(
        public readonly Consulta $consulta,
        public readonly Historia $historia,
        public readonly int $pacienteId,
    ) {
    }

    public function numhistoria(): int
    {
        return (int) $this->consulta->numhistoria;
    }

    public function nroconsulta(): int
    {
        return (int) $this->consulta->nroconsulta;
    }
}
