<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\IndicadoresIncidentes;
use App\Models\Consulta;
use App\Models\ExamenPacienteConsulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar los indicadores incidentes de una consulta** (nefrología pediátrica, 2026-10-19).
 *
 * Escribe **las dos cosas** que escribe el escritorio:
 *
 *  1. los **números** en `examenes_paciente_consulta`, la fila de la consulta (`hhistoria` + `hconsulta`), que es
 *     la que el escritorio lee al abrir (`w_consulta_principal.srw:2924-2960` y `:25651`);
 *  2. el **texto** compuesto en `consultas.resultadoexamencomp`, que es lo que imprimen el resumen médico y la
 *     evaluación pre-operatoria.
 *
 * Reglas, las mismas de las otras escrituras de la consulta:
 *
 *  - **sólo lo que cambió** (por columna): un guardado que reescribe las 34 columnas dejaría 34 rastros por
 *    columnas que el médico no tocó y le ganaría a ediciones reales del escritorio;
 *  - **rastro por columna** en `sync_changes` (`source = web`) y last-write-wins del sync;
 *  - **no crea filas vacías**: sin ningún valor y sin fila previa, no escribe nada.
 */
final class GuardarIndicadoresIncidentes
{
    public function __construct(private IndicadoresIncidentes $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  los campos del panel (claves de `IndicadoresIncidentes::claves()`, más `fecha`)
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro
     * @return array{campos:int, texto:bool}  cuántas columnas se escribieron y si cambió el resumen
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): array {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        // La fila se busca pero **no se crea todavía**: sin ningún valor no tiene que quedar una fila vacía.
        $fila = $this->lectura->fila($consulta);
        $antes = $this->lectura->de($consulta);

        $cambios = [];

        foreach (IndicadoresIncidentes::campos() as $clave => $campo) {
            $nuevo = $this->numero($valores[$clave] ?? null);

            if (($antes[$clave] ?? null) === $nuevo) {
                continue;
            }

            if (! RegistroDeCambios::permite(IndicadoresIncidentes::TABLA, (int) ($fila->id ?? 0), $campo['columna'], $cuando)) {
                continue;
            }

            $cambios[$campo['columna']] = $nuevo;
        }

        $fecha = $this->fecha($valores['fecha'] ?? null, $antes['fecha'] ?? null);
        if ($fecha !== null && $fecha !== ($antes['fecha'] ?? null)
            && RegistroDeCambios::permite(IndicadoresIncidentes::TABLA, (int) ($fila->id ?? 0), 'fecha', $cuando)) {
            $cambios['fecha'] = $fecha;
        }

        if ($cambios !== []) {
            $fila = $fila ?? $this->lectura->filaParaEscribir($consulta);

            foreach ($cambios as $columna => $valor) {
                // El formulario manda texto: se guarda el número (o `null`, nunca un 0 silencioso).
                $fila->{$columna} = $columna === 'fecha' ? $valor : ($valor === null ? null : (float) $valor);
            }
            $fila->save();

            foreach ($cambios as $columna => $valor) {
                RegistroDeCambios::registrar(
                    IndicadoresIncidentes::TABLA,
                    (int) $fila->id,
                    $consulta->reg_medico,
                    $columna,
                    $fila->{$columna},
                    $cuando,
                    $origen,
                );
            }
        }

        // El resumen compuesto, con los valores que quedaron guardados (no con los que llegaron).
        $texto = IndicadoresIncidentes::componer($this->lectura->de($consulta));
        $texto = $texto === '' ? null : $texto;
        $textoCambio = false;

        if ($texto !== IndicadoresIncidentes::textoGuardado($consulta)
            && RegistroDeCambios::permite('consultas', (int) $consulta->id, IndicadoresIncidentes::COLUMNA_TEXTO, $cuando)) {
            $consulta->{IndicadoresIncidentes::COLUMNA_TEXTO} = $texto;
            $consulta->save();

            RegistroDeCambios::registrar(
                'consultas',
                (int) $consulta->id,
                $consulta->reg_medico,
                IndicadoresIncidentes::COLUMNA_TEXTO,
                $texto,
                $cuando,
                $origen,
            );

            $textoCambio = true;
        }

        return ['campos' => count($cambios), 'texto' => $textoCambio];
    }

    /** El número del formulario como texto normalizado (`1,5` → `1.5`), o `null`. */
    private function numero($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim(str_replace(',', '.', (string) $valor));

        return $texto === '' ? null : $texto;
    }

    /** La fecha del panel (`Y-m-d`), o `null` si no vino o no cambió. */
    private function fecha($valor, ?string $antes): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '' || $texto === $antes) {
            return null;
        }

        return Carbon::parse($texto)->toDateString();
    }
}
