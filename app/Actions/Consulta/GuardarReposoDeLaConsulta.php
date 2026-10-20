<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\ReposoPaciente;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * **Guardar el reposo de una consulta** (WEB-5.3a, pestaña *Reposos*): los días, la fecha de inicio y la justificación.
 *
 * **Un reposo por consulta** (`reposo_paciente` se llavea por `nrohistoria` + `nroconsulta`): si la consulta ya tiene
 * fila —casi siempre, la plantilla que el escritorio crea al abrirla—, se **corrige en el lugar**, columna por columna
 * y sólo lo que cambió; si no, se crea. `codereposo` no tiene campo en la pantalla del escritorio (siempre `'M'`) y acá
 * tampoco: una fila nueva lo lleva en `'M'` y una existente **no se toca** (si trajera otro valor se respeta).
 *
 * ## Cuándo es un reposo de verdad (y por qué se rechaza el de 1 día sin justificación)
 *
 * El escritorio crea, al abrir cada consulta, un reposo de **1 día desde hoy sin observación** (`:3135-3142`), y esa
 * plantilla es indistinguible de un reposo de un día escrito a propósito. La decisión del usuario (2026-09-27) fue que
 * **no cuenta como documento** (`QueEsUnDocumento::reposo`: más de un día **o** una observación), y es la que usan el
 * móvil, la historia del paciente y la impresión. Para que lo que la web guarda **sea** un documento en los tres
 * lugares, esta Action **no guarda** un reposo de un solo día sin justificación y lo dice
 * (`InvalidArgumentException`); eliminar sí es siempre posible (`EliminarReposoDeLaConsulta`).
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea, `updated` por columna con el last-write-wins del
 * sync, y cambia `updated_at` (el móvil baja `reposo_paciente` por delta).
 */
final class GuardarReposoDeLaConsulta
{
    use EscribeConRastro;

    public const LARGO_MAXIMO = 20000;

    public function __construct(private ReposoDeLaConsulta $reposos)
    {
    }

    /**
     * @param  string|CarbonInterface  $desde  el primer día de reposo (`AAAA-MM-DD`)
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si los días o la fecha no sirven, o si es un reposo de un día sin justificación
     */
    public function ejecutar(
        Consulta $consulta,
        int $dias,
        $desde,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        if ($dias < 1 || $dias > ReposoDeLaConsulta::DIAS_MAXIMO) {
            throw new InvalidArgumentException('Los días de reposo van de 1 a ' . ReposoDeLaConsulta::DIAS_MAXIMO . '.');
        }

        $inicio = $this->fecha($desde);
        $observacion = TextoDeLaConsulta::limpio($observacion);

        if (! QueEsUnDocumento::reposo($dias, $observacion)) {
            throw new InvalidArgumentException(
                'Un reposo de un solo día necesita su justificación: escribe el motivo o indica más de un día. '
                . 'Sin eso el reposo no cuenta como documento.'
            );
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $dias, $inicio, $observacion, $cuando, $origen) {
            $nuevos = ['fdesde' => $inicio->toDateString(), 'numdias' => $dias, 'obser_reposo' => $observacion];
            $fila = $this->reposos->fila($consulta);

            if ($fila === null) {
                $this->crearFila(ReposoPaciente::class, 'reposo_paciente', array_merge($nuevos, [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'codereposo' => ReposoDeLaConsulta::CODIGO,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            // Una fila sin código (no debería, pero el legado trae basura) se completa con el del escritorio.
            if (TextoDeLaConsulta::limpio($fila->codereposo) === null) {
                $nuevos['codereposo'] = ReposoDeLaConsulta::CODIGO;
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'reposo_paciente', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** @param string|CarbonInterface $desde */
    private function fecha($desde): Carbon
    {
        try {
            $fecha = Carbon::parse($desde instanceof CarbonInterface ? $desde->toDateString() : (string) $desde)->startOfDay();
        } catch (Throwable $e) {
            throw new InvalidArgumentException('La fecha de inicio del reposo no es válida.');
        }

        if ($fecha->year < 1900 || $fecha->year > 2200) {
            throw new InvalidArgumentException('La fecha de inicio del reposo no es válida.');
        }

        return $fecha;
    }
}
