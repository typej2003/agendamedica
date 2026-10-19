<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\DuenoDeLaHistoria;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\MedicoPaciente;

/**
 * **De quién es la consulta**: a qué paciente y a qué historia pertenece una cita.
 *
 * Las dos preguntas se resuelven juntas y en un solo lugar porque la respuesta está repartida en tres
 * columnas del esquema del legado (`Docs/Wiki/04-historia-clinica.md`, "Anomalía a no repetir"): la cita
 * se enlaza con el paciente por `cola.numhistoria` (el camino normal, contra el pivote
 * `medico_pacientes`) o por `cola.paciente_sinhistoria_id` (el alta rápida sin número, WEB-2.11), y la
 * historia aparece recién cuando el pivote tiene `numhistoria`.
 *
 * Lo usan las tres pantallas que entran a una consulta: la Action `AbrirConsulta` al abrirla, el
 * mostrador al decidir si hay que mandar a asignar la historia (y cuál es la consulta del día que se
 * ofrece re-atender) y el shell al resolver el encabezado de una consulta ya existente. Si cada una
 * hiciera su propia lectura, el mismo paciente podría resolverse distinto en dos partes del mismo flujo.
 *
 * Su hermana es `CitaDeLaConsulta`, que va en el sentido contrario (de la consulta a la cita) y la usa el
 * cierre de la consulta (WEB-4.8).
 */
final class PacienteDeLaCita
{
    public function __construct(private DuenoDeLaHistoria $duenios = new DuenoDeLaHistoria())
    {
    }

    /**
     * `pacientes.id` del paciente de la cita, o `null` si la cita no dice a quién atender: las filas de
     * `cola` del legado con `numhistoria` nulo y sin ancla no tienen a quién numerarle la historia.
     *
     * **El número se resuelve primero en el registro de la propia cita** y con la regla única de
     * `DuenoDeLaHistoria` (el dueño en `historias`): el número es único por registro, y salir a los demás
     * registros del médico con un `whereIn` devolvía el primer vínculo que coincidiera —el paciente de
     * otro registro— y «Atender» abría la historia de otra persona. Los demás registros siguen mirándose
     * **sólo si el de la cita no tiene a nadie** con ese número (una cita vieja enlazada al vínculo de
     * otro registro).
     */
    public function opcional(Cola $cita, array $registrosMedicos): ?int
    {
        if ($cita->numhistoria !== null) {
            $duenio = $this->duenios->de($cita->reg_medico, $cita->numhistoria);

            if ($duenio !== null) {
                return $duenio;
            }

            $pacienteId = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where('numhistoria', (string) (int) $cita->numhistoria)
                ->orderBy('id')
                ->value('paciente_id');

            if ($pacienteId !== null) {
                return (int) $pacienteId;
            }
        }

        return $cita->paciente_sinhistoria_id !== null ? (int) $cita->paciente_sinhistoria_id : null;
    }

    /**
     * La historia del paciente **en este consultorio**, o `null` si todavía no tiene número.
     *
     * Se busca por el **pivote del paciente** y no por `historias.paciente_id`: el pivote es el que dice
     * de quién es el paciente acá y el que `AsignarHistoria` numera. El `numhistoria` del pivote es
     * **texto** y puede venir vacío, con espacios o con basura (`S/H`): se limpia con `CamposDePaciente`
     * y, si no es numérico, no hay historia que buscar — castear a ciegas traería la historia `0`, que es
     * de otro paciente (wiki `04-historia-clinica.md`).
     *
     * **El número tiene que ser del paciente** (2026-10-19): si el vínculo dice un número que en
     * `historias` es de **otro** paciente (el importador de SQL del `/admin` dejó el vínculo antes de
     * fallar, el panel viejo escribía el número a mano), este paciente no tiene historia. Devolver la del
     * otro es lo que hacía que la ficha mostrara «H. 21» para alguien que no la tenía y que «Atender»
     * abriera la consulta de una persona distinta. Sin historia, la ficha ofrece asignarla y
     * `AsignarHistoria` ya sabe qué hacer en este caso: si el número es de otro, numera uno nuevo.
     *
     * @param  array<int,string>  $registrosMedicos  todos los `reg_medico` del médico, porque el pivote
     *                                               puede tener la historia en otro de sus registros
     */
    public function historia(int $pacienteId, array $registrosMedicos): ?Historia
    {
        $vinculo = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
            ->where('paciente_id', $pacienteId)
            ->orderBy('id')
            ->first(['numhistoria', 'reg_medico']);

        $numero = CamposDePaciente::historiaNumerica($vinculo->numhistoria ?? null);

        if ($numero === null) {
            return null;
        }

        // El registro de la fila manda: es el que la cita y el escritorio esperan encontrar.
        $regMedico = CamposDePaciente::texto($vinculo->reg_medico ?? null);

        // El legado escribe el mismo número con y sin ceros a la izquierda: el pivote puede decir
        // `01001` y la historia estar en `1001` (o al revés), y son la misma. Se buscan las dos formas o
        // la ficha diría «sin registrar» para un paciente que sí la tiene (trampa 143).
        $formas = array_values(array_unique([
            (string) CamposDePaciente::numeroDeHistoria($vinculo->numhistoria ?? null),
            (string) $numero,
        ]));

        $historia = Historia::whereIn('numhistoria', $formas)
            ->when($regMedico !== null, fn ($consulta) => $consulta->where('reg_medico', $regMedico))
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();

        // El número es de otro paciente: no es la historia de éste (ver el comentario de arriba). Las
        // filas viejas de `historias` sin `paciente_id` no dicen de quién son, así que se aceptan.
        if ($historia !== null
            && $historia->paciente_id !== null
            && (int) $historia->paciente_id !== (int) $pacienteId) {
            return null;
        }

        return $historia;
    }
}
