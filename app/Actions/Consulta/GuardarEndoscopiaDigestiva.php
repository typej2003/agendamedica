<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EndoscopiaDigestiva;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * **Guardar una endoscopía digestiva** de la consulta pediátrica (gastroenterología, 2026-10-19).
 *
 * Una fila por consulta en `endosc_diges_super` o `endosc_diges_infer`, con la clave del DataWindow del escritorio
 * (`historia` + `consulta`). Las reglas son las de las otras escrituras de la consulta:
 *
 *  - **sólo lo que cambió**, por columna: reescribir todo dejaría rastros por columnas que el médico no tocó y le
 *    ganaría a ediciones reales del escritorio;
 *  - **rastro por columna** en `sync_changes` (`source = web`) y last-write-wins del sync;
 *  - **no crea filas vacías**.
 *
 * Las columnas de las imágenes del escritorio (`ima_primera` … `obs_ima_tercera`) no se tocan: guardan rutas de la
 * PC del consultorio.
 */
final class GuardarEndoscopiaDigestiva
{
    public function __construct(private EndoscopiaDigestiva $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  los campos del tipo (claves de `EndoscopiaDigestiva::campos()`)
     * @return int  cuántas columnas se escribieron
     */
    public function ejecutar(
        Consulta $consulta,
        string $tipo,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): int {
        if (! EndoscopiaDigestiva::existe($tipo)) {
            return 0;
        }

        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $tabla = EndoscopiaDigestiva::tabla($tipo);
        $fila = $this->lectura->fila($consulta, $tipo);
        $antes = $this->lectura->de($consulta, $tipo);

        $cambios = [];

        foreach (EndoscopiaDigestiva::campos($tipo) as $clave => $campo) {
            $nuevo = $this->normalizar($valores[$clave] ?? null);

            if (($antes[$clave] ?? null) === $nuevo) {
                continue;
            }

            if (! RegistroDeCambios::permite($tabla, (int) ($fila->id ?? 0), $campo['columna'], $cuando)) {
                continue;
            }

            $cambios[$campo['columna']] = $nuevo;
        }

        if ($cambios === []) {
            return 0;
        }

        $fila = $fila ?? $this->lectura->filaParaEscribir($consulta, $tipo);

        foreach ($cambios as $columna => $valor) {
            if ($columna === 'fecha') {
                $fila->fecha = $valor;

                continue;
            }

            // `estado` es `char(1)` en el esquema: se guarda el primer carácter y no el texto entero.
            if (str_ends_with($columna, '_estado') && $valor !== null) {
                $valor = mb_substr($valor, 0, 1);
            }

            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($cambios as $columna => $valor) {
            RegistroDeCambios::registrar(
                $tabla,
                (int) $fila->id,
                $consulta->reg_medico,
                $columna,
                $fila->{$columna},
                $cuando,
                $origen,
            );
        }

        return count($cambios);
    }

    /** El `""` del formulario y el `null` de la columna son el mismo estado. */
    private function normalizar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
