<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\Medico;
use App\Models\MotivoCita;
use App\Models\Office;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un motivo de cita** (WEB-8.1c, el *Nuevo* de `w_agregar_motivo_cita`).
 *
 * Se guardan la **razón** (`tipo_atencion`, en mayúsculas), el **precio** (el monto que toma la cita cuando no se escribe
 * otro), la **sede** (`office_id`, o ninguna = todas) y la marca de **varias citas pendientes** (WEB-2.17). El **código lo
 * da el servidor** (`ReglasDelMotivoDeCita::siguienteCodigo`): el mayor numérico del catálogo del médico + 1 en dos dígitos.
 * Todo se acota al `reg_medico` del contexto: nunca se crea un motivo sin él ni se toca el de otro médico.
 *
 * ## No duplicar
 *
 * El mismo código puede estar **una vez por sede** (más una sin sede): así lo cargó la sincronización, y es lo que permite un
 * precio distinto por sede. Lo que no se crea es **otro igual dentro de la misma sede**: la misma razón (sin tildes ni
 * mayúsculas) con la misma sede —o los dos sin sede— se rechaza diciendo cuál es el que ya existe.
 *
 * ## Dos altas a la vez
 *
 * El correlativo se mide **dentro de la transacción y con las filas del médico bloqueadas** (`lockForUpdate`): la segunda
 * alta espera a la primera y toma el número que sigue. Es el mismo patrón de `AsignarHistoria`.
 *
 * ## Sync
 *
 * Deja un `created` en `sync_changes` (`source = web`). En el escritorio `motivo_cita` no tiene clave primaria (sólo un índice
 * único), así que su registro de cambios es de sentencia y **sube la tabla entera**: ese `created` es lo que
 * `CambiosEscritorio::reemplazarTabla` respeta para no borrar la fila (`sin_clave_con_altas_del_app`, trampa 69).
 */
final class CrearMotivoDeCita
{
    use EscribeConRastro;

    /**
     * @param  array{razon?:?string, precio?:mixed, office_id?:mixed, admite_varias?:mixed}  $datos
     *
     * @throws InvalidArgumentException si falta la razón, es muy larga, el precio no es válido, la sede no es del médico o
     *                                  ya hay un motivo igual en esa sede
     */
    public function ejecutar(
        Medico $medico,
        string $regMedico,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): MotivoCita {
        $razon = ReglasDelMotivoDeCita::razon($datos['razon'] ?? null);
        $precio = ReglasDelMotivoDeCita::precio($datos['precio'] ?? null);
        $officeId = $this->sede($medico, $regMedico, $datos['office_id'] ?? null);
        $admite = filter_var($datos['admite_varias'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $razon, $precio, $officeId, $admite, $cuando, $origen) {
            // Las filas del médico bajo candado: el correlativo y la comparación miran lo mismo que verá la otra alta.
            $catalogo = MotivoCita::where('reg_medico', $regMedico)->lockForUpdate()->get();

            $igual = $catalogo->first(
                fn (MotivoCita $fila) => $fila->office_id === $officeId
                    && ReglasDelMotivoDeCita::clave($fila->tipo_atencion) === ReglasDelMotivoDeCita::clave($razon)
            );

            if ($igual !== null) {
                throw new InvalidArgumentException(self::mensajeDeDuplicado($igual, $officeId === null));
            }

            return $this->crearFila(MotivoCita::class, 'motivo_cita', [
                'reg_medico' => $regMedico,
                'codigo' => ReglasDelMotivoDeCita::siguienteCodigo($catalogo->pluck('codigo')),
                'tipo_atencion' => $razon,
                'precio' => $precio,
                'office_id' => $officeId,
                'admite_varias_citas_pendientes' => $admite ? true : null,
            ], $cuando, $origen);
        });
    }

    /**
     * La sede del motivo: una del médico, o ninguna (`null` = todas las sedes).
     *
     * @param  mixed  $valor
     *
     * @throws InvalidArgumentException si no es una sede del médico
     */
    public static function sede(Medico $medico, string $regMedico, $valor): ?int
    {
        if ($valor === null || $valor === '' || $valor === 'todas') {
            return null;
        }

        if (! is_numeric($valor) || ! Office::delMedico($regMedico, $medico->id)->whereKey((int) $valor)->exists()) {
            throw new InvalidArgumentException('Elige una sede de la lista.');
        }

        return (int) $valor;
    }

    /** El aviso de «ya existe uno igual», con su código para que el médico lo encuentre en la lista. */
    public static function mensajeDeDuplicado(MotivoCita $existente, bool $sinSede): string
    {
        return 'Ya tienes «' . trim((string) $existente->tipo_atencion) . '» ' . ($sinSede ? 'para todas las sedes' : 'en esa sede')
            . ' (código ' . trim((string) $existente->codigo) . '). Edita ese o escribe otro nombre.';
    }
}
