<?php

namespace App\Actions\Embarazo;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Models\PreNatalObservacion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **«Nueva gesta»** (WEB-11.15): crea la gesta siguiente de la historia. Como la ventana `w_agregar_nueva_gesta` del escritorio, **sólo
 * pregunta cuántos bebés son** (Alexander, 2026-10-15): la FUR y el resto se completan después en la pestaña *Prenatal*.
 *
 * Reglas:
 *
 *  - **No se solapan embarazos**: no se crea una gesta nueva mientras la anterior no tenga marcado «Final de embarazo»
 *    (`GestasDeLaHistoria::motivoParaNoCrear`, la misma regla que usa el guardado). La primera gesta de la historia siempre se puede.
 *  - **Un solo bebé** deja `multiple = N`; **dos o más** dejan `multiple = S` y `cantidad = n`. Entre 1 y 6.
 *  - El campo *Gestas* arranca con el número de la gesta (estar en la 3 quiere decir que hubo 3 embarazos), igual que el escritorio.
 *
 * El rastro del sync es el de `GuardarGestaDelEmbarazo` (`created` de la fila con `source = web`).
 */
final class CrearGestaDelEmbarazo
{
    use EscribeConRastro;

    /** El tope de bebés de un embarazo múltiple que la pantalla ofrece. */
    public const MAXIMO_DE_BEBES = 6;

    public function __construct(private GestasDeLaHistoria $gestas)
    {
    }

    /**
     * @return int el número (`gesta_clave`) de la gesta creada
     *
     * @throws InvalidArgumentException si la gesta anterior no está cerrada o la cantidad de bebés no es válida
     */
    public function ejecutar(
        string $regMedico,
        int $numhistoria,
        int $bebes = 1,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): int {
        if ($bebes < 1 || $bebes > self::MAXIMO_DE_BEBES) {
            throw new InvalidArgumentException('La cantidad de bebés va de 1 a ' . self::MAXIMO_DE_BEBES . '.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $numhistoria, $bebes, $cuando, $origen) {
            $motivo = $this->gestas->motivoParaNoCrear($regMedico, $numhistoria);

            if ($motivo !== null) {
                throw new InvalidArgumentException($motivo);
            }

            $clave = $this->gestas->siguienteClave($regMedico, $numhistoria);

            $this->crearFila(PreNatalObservacion::class, GestasDeLaHistoria::TABLA, [
                'reg_medico' => $regMedico,
                'historia' => $numhistoria,
                'gesta_clave' => $clave,
                'gestas' => (string) $clave,
                'multiple' => $bebes > 1 ? 'S' : 'N',
                'cantidad' => $bebes > 1 ? $bebes : null,
                'final' => 'N',
            ], $cuando, $origen);

            return $clave;
        });
    }
}
