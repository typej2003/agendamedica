<?php

namespace App\Actions\Embarazo;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Clinica\Embarazo\Gesta;
use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Models\PreNatalObservacion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar una gesta del control prenatal** (WEB-6.6, la pestaña *Prenatal* del ítem 6 del panel).
 *
 * La gesta es una fila de `pre_natal_observaciones` con la clave del DataWindow de la pestaña:
 * **`historia` + `gesta_clave`** (`d_pre_natal_observa.srd`). Se corrige **sólo lo que cambió**, columna por
 * columna, con el rastro en `sync_changes` (`source = web`) y el last-write-wins del sync — el trait
 * `EscribeConRastro`, el mismo de las solicitudes y del eco—. Si la gesta no existe, se crea con el número
 * que le toca (`GestasDeLaHistoria::siguienteClave`: la última de esa historia más uno): es lo que hace el
 * botón *Nueva Gesta* del escritorio.
 *
 * **Una gesta necesita su FUR.** Es la única condición que la web agrega a lo que el DataWindow permite
 * (ahí ningún campo es obligatorio), y no es un capricho: sin FUR no hay **edad gestacional** ni **FPP**
 * —las dos cuentas que esta pestaña muestra— ni nada que el embarazo aporte. El escritorio tampoco deja el
 * control prenatal sin FUR: la pide cada vez que entra a la pestaña (`w_consulta_principal.srw:21391-21405`).
 *
 * **Qué no toca.** Las columnas `partos`, `cesarea`, `abortos`, `otros`, `final`, `multiple` y `cantidad`
 * están en la tabla pero este DataWindow no las edita: quedan como estén (las escribe el escritorio desde
 * otras pantallas). Tampoco la clave: el número de la gesta lo asigna el servidor, no el formulario.
 *
 * Deja su rastro con el **`source = web`**; `pre_natal_observaciones` se sube **por fila** desde el
 * escritorio (tiene clave primaria en el PowerBuilder), así que no necesita ninguna protección especial.
 */
final class GuardarGestaDelEmbarazo
{
    use EscribeConRastro;

    /** La tabla del control prenatal. */
    public const TABLA = GestasDeLaHistoria::TABLA;

    /** Las siete columnas que edita la pestaña *Prenatal*, con los rótulos del DataWindow. */
    public const COLUMNAS = ['fur', 'menarquia', 'gestas', 'parto', 'tipiaje', 'tipiaje_cony', 'observaciones'];

    /**
     * Las columnas de la tabla que esta pestaña **no** toca (las escribe el escritorio en otras pantallas).
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_SE_TOCAN = ['partos', 'cesarea', 'abortos', 'otros', 'final', 'multiple', 'cantidad'];

    public function __construct(private GestasDeLaHistoria $gestas)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  las columnas del formulario (las de `COLUMNAS`)
     * @param  int|null  $clave  la gesta que se corrige; `null` crea la siguiente (*Nueva Gesta*)
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si la gesta no trae FUR
     */
    public function ejecutar(
        string $regMedico,
        int $numhistoria,
        array $valores,
        ?int $clave = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $nuevos = [];
        foreach (self::COLUMNAS as $columna) {
            $nuevos[$columna] = Gesta::texto($valores[$columna] ?? null);
        }

        $nuevos['gestas'] = $this->entero($nuevos['gestas']);

        if ($nuevos['fur'] === null) {
            throw new InvalidArgumentException('Cada gesta necesita su FUR: sin ella no hay edad gestacional ni FPP.');
        }

        $cuando = $this->ahora($cuando);
        $clave ??= $this->gestas->siguienteClave($regMedico, $numhistoria);

        return DB::transaction(function () use ($regMedico, $numhistoria, $clave, $nuevos, $cuando, $origen) {
            $fila = $this->gestas->fila($regMedico, $numhistoria, $clave);

            if ($fila === null) {
                $this->crearFila(PreNatalObservacion::class, self::TABLA, array_merge($nuevos, [
                    'reg_medico' => $regMedico,
                    'historia' => $numhistoria,
                    'gesta_clave' => $clave,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            $fila = PreNatalObservacion::find($fila->id);
            [$escritas, $bloqueadas] = $this->corregirFila($fila, self::TABLA, $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** El número de gestas del campo *Gestas*: un número, no texto; lo que no sea número queda en `null`. */
    private function entero(?string $valor): ?string
    {
        return $valor === null || ! is_numeric($valor) ? null : (string) (int) $valor;
    }
}
