<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\RiesgoQuirurgico;
use App\Models\Consulta;
use App\Models\Riesgo;
use App\Models\RiesgoPaciente;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar el riesgo quirúrgico** de una consulta (cirugía pediátrica, 2026-10-19).
 *
 * Hace lo mismo que el escritorio, en este orden:
 *
 *  1. **sincroniza lo marcado** en `riesgo_paciente` (que es del **paciente**, no de la consulta): lo que llegó y no
 *     estaba se da de alta con el `descripcion`/`sub_tipo`/`punto` del catálogo `riesgo`; lo que estaba y ya no
 *     llegó se da de baja. Cada alta y cada baja dejan su rastro (`created`/`deleted`, `source = web`);
 *  2. escribe el **resumen** de la consulta en `riesgo_paciente_opera` —`puntos` (la suma de lo marcado), `clase`,
 *     `conclusion` e `intervención`—, **sólo lo que cambió** y con un rastro `updated` por columna.
 */
final class GuardarRiesgoQuirurgico
{
    public function __construct(private RiesgoQuirurgico $lectura)
    {
    }

    /**
     * @param  array<int,string>  $marcadas  las descripciones del catálogo que quedaron marcadas
     * @param  array<string,mixed>  $resumen  los campos del resumen (`clase`, `conclusion`, `nombre_intervencion`)
     * @return array{marcadas:int, columnas:int}
     */
    public function ejecutar(
        Consulta $consulta,
        array $marcadas,
        array $resumen = [],
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): array {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $historia = (int) $consulta->numhistoria;

        $catalogo = Riesgo::get()->keyBy('descripcion');
        $marcadas = array_values(array_unique(array_filter(array_map('trim', $marcadas))));
        $antes = $this->lectura->descripcionesMarcadas($consulta);

        $altas = 0;
        $bajas = 0;

        foreach (array_diff($marcadas, $antes) as $descripcion) {
            $factor = $catalogo->get($descripcion);

            if ($factor === null) {
                continue;
            }

            $fila = RiesgoPaciente::create([
                'reg_medico'  => $consulta->reg_medico,
                'historia'    => $historia,
                'descripcion' => (string) $factor->descripcion,
                'sub_tipo'    => $factor->sub_tipo,
                'punto'       => (int) $factor->puntos,
            ]);

            RegistroDeCambios::registrarOperacion(
                RiesgoQuirurgico::TABLA_PACIENTE,
                (int) $fila->id,
                $consulta->reg_medico,
                'created',
                null,
                null,
                $cuando,
                $origen,
            );

            $altas++;
        }

        foreach (array_diff($antes, $marcadas) as $descripcion) {
            $filas = RiesgoPaciente::where('historia', $historia)->where('descripcion', $descripcion)->get();

            foreach ($filas as $fila) {
                $id = (int) $fila->id;
                $fila->delete();

                RegistroDeCambios::registrarOperacion(
                    RiesgoQuirurgico::TABLA_PACIENTE,
                    $id,
                    $consulta->reg_medico,
                    'deleted',
                    'descripcion',
                    $descripcion,
                    $cuando,
                    $origen,
                );

                $bajas++;
            }
        }

        // El resumen: los puntos son los de lo marcado (no lo que llegue del formulario).
        $columnas = $this->guardarResumen($consulta, $resumen, $cuando, $origen);

        return ['marcadas' => $altas + $bajas, 'columnas' => $columnas];
    }

    /** @param array<string,mixed> $resumen */
    private function guardarResumen(Consulta $consulta, array $resumen, Carbon $cuando, string $origen): int
    {
        $fila = $this->lectura->resumen($consulta);
        $puntos = $this->lectura->puntos($consulta);

        if ($fila === null && $puntos === 0 && array_filter(array_map('trim', array_map('strval', $resumen))) === []) {
            // Sin factores marcados ni datos del resumen no hay nada que guardar (no se crean filas vacías).
            return 0;
        }

        $cambios = [];
        foreach (RiesgoQuirurgico::camposDelResumen() as $clave => $campo) {
            $valor = $this->normalizar($resumen[$clave] ?? null);
            $antes = $fila === null ? null : $this->normalizar($fila->{$campo['columna']});

            if ($valor === $antes) {
                continue;
            }

            if (! RegistroDeCambios::permite(RiesgoQuirurgico::TABLA_RESUMEN, (int) ($fila->id ?? 0), $campo['columna'], $cuando)) {
                continue;
            }

            $cambios[$campo['columna']] = $valor;
        }

        if ($fila === null) {
            $fila = $this->lectura->resumenParaEscribir($consulta);
            $fila->puntos = $puntos;
            foreach ($cambios as $columna => $valor) {
                $fila->{$columna} = $valor;
            }
            $fila->save();

            RegistroDeCambios::registrarOperacion(
                RiesgoQuirurgico::TABLA_RESUMEN,
                (int) $fila->id,
                $consulta->reg_medico,
                'created',
                null,
                null,
                $cuando,
                $origen,
            );

            return 1;
        }

        if ((int) $fila->puntos !== $puntos) {
            $cambios['puntos'] = $puntos;
        }

        if ($cambios === []) {
            return 0;
        }

        foreach ($cambios as $columna => $valor) {
            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($cambios as $columna => $valor) {
            RegistroDeCambios::registrar(
                RiesgoQuirurgico::TABLA_RESUMEN,
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

    private function normalizar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
