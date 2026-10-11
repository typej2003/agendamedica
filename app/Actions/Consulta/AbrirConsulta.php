<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\PacienteDeLaCita;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * **"Atender" abre la consulta** (PLAN-WEB.md §3.10, decidido el 2026-10-10; wiki
 * `04-historia-clinica.md`, "Atender abre la consulta"): la consulta **del día** del paciente de una
 * cita —la que ya existe si el paciente ya tiene una hoy, o una nueva con el `nroconsulta` siguiente—.
 *
 * Es la portación a la web de lo que el teléfono ya hace sin conexión
 * (`DoctorisimoMobile/lib/features/consulta/data/consulta_repository.dart`, `abrirDelDia(cola)`): reusa
 * la consulta de ese día o crea la siguiente de la historia. En el legado es lo que hace
 * `w_consulta_principal` al abrirse, junto con el `UPDATE cola SET atendido = 1` de su evento `open`.
 *
 * **No marca la cita como atendida**: de eso se encarga `App\Actions\Agenda\AtenderCita`, que además
 * implica confirmada y no se deshace. Son dos escrituras separadas a propósito, y quien las usa las
 * ordena: primero se abre la consulta y recién después se marca la cita (el móvil hace lo mismo,
 * `AccionesCita.atender`: "para no marcar una cita cuya consulta no se pudo abrir").
 *
 * **Sin historia no hay consulta** (confirmado con Alexander, 2026-09-23: "no se atiende sin historia").
 * Acá eso es una excepción, no un caso a resolver: llenar la historia es `AsignarHistoria` y la web lo
 * decide **antes** de llamar (manda al paciente a su ficha, WEB-3.3). El teléfono sí la llena en el
 * momento porque trabaja sin conexión y no puede pedir ayuda; la web, que está online, no duplica esa
 * decisión.
 *
 * Dos cosas del legado que se respetan y no son obvias:
 *
 *  - **La cita y la consulta se relacionan por `numhistoria` + fecha**: `cola` no tiene `nroconsulta`
 *    (wiki), así que "la consulta de hoy" se busca por la historia del paciente y el día, no por la
 *    cita.
 *  - **Re-atender no crea nada**: si el paciente ya tiene una consulta hoy, se devuelve esa. El
 *    `nueva: true` —la otra mitad de la pregunta que el legado hace al abrir, "¿re-atender o consulta
 *    nueva?"— es lo que numera la siguiente aunque ya haya una hoy.
 */
final class AbrirConsulta
{
    public function __construct(
        private CrearConsulta $crearConsulta,
        private PacienteDeLaCita $pacienteDeLaCita,
    ) {
    }

    /**
     * @param  Cola  $cita  la cita que se está atendiendo (tiene que ser del médico del contexto)
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico, para resolver su vínculo
     * @param  CarbonInterface|null  $fecha  el día de la consulta; por defecto, **hoy en el consultorio**
     * @param  bool  $nueva  `true` = "consulta nueva" (el `nroconsulta` siguiente aunque haya una hoy);
     *                       `false` = "re-atender" (la de hoy si existe)
     *
     * @throws InvalidArgumentException si la cita no dice a qué paciente de este médico pertenece, o si
     *                                  ese paciente todavía no tiene número de historia
     */
    public function abrir(
        Cola $cita,
        array $registrosMedicos,
        ?CarbonInterface $fecha = null,
        bool $nueva = false,
    ): ConsultaAbierta {
        $pacienteId = $this->pacienteDeLaCita->opcional($cita, $registrosMedicos);

        if ($pacienteId === null) {
            throw new InvalidArgumentException('Esta cita no tiene un paciente identificable.');
        }

        $historia = $this->pacienteDeLaCita->historia($pacienteId, $registrosMedicos);

        if ($historia === null) {
            throw new InvalidArgumentException(
                'Este paciente todavía no tiene número de historia: asígnaselo antes de atenderlo.'
            );
        }

        $dia = $fecha ?? FechaDelConsultorio::hoy();

        // "Re-atender" (y el caso normal) devuelven la consulta que ya está abierta hoy; con
        // `nueva: true` se saltea la búsqueda y se numera la siguiente.
        $consulta = $nueva ? null : $this->delDia($historia, $dia);

        return new ConsultaAbierta(
            $consulta ?? $this->crearConsulta->ejecutar($historia, $dia),
            $historia,
            $pacienteId,
        );
    }

    /**
     * **La consulta del día** de una historia: la de mayor `nroconsulta` de ese día, o `null`.
     *
     * Público porque hay dos caminos que la necesitan sin abrir nada: el mostrador, para saber si la
     * pregunta de "re-atender o consulta nueva" corresponde (y cuál es "la de hoy" que ofrece), y la
     * pantalla de la consulta, cuando se entra a editar una cita ya atendida y hay que resolver cuál es.
     *
     * `whereDate` y no `where('fecha', …)`: el modelo castea `fecha` a `date`, así que la columna se
     * escribe con hora (`2026-10-10 00:00:00`) y una igualdad contra `Y-m-d` no la encontraría (la
     * misma trampa que `fecha_cumple` en WEB-2.9).
     */
    public function delDia(Historia $historia, CarbonInterface $dia): ?Consulta
    {
        return Consulta::where('numhistoria', (int) $historia->numhistoria)
            ->where('reg_medico', $historia->reg_medico)
            ->whereDate('fecha', $dia->toDateString())
            ->orderByDesc('nroconsulta')
            ->first();
    }
}
