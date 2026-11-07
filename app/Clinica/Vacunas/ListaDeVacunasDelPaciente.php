<?php

namespace App\Clinica\Vacunas;

use Carbon\Carbon;
use Closure;

/**
 * **La lista de vacunas del paciente** (O-68 y O-69; «Propia», O-83, Alexander 2026-11-06): las filas de `vacuna_paciente` del niño —lo
 * aplicado **y** lo programado— tal como las muestra la solapa *Inmunizaciones* del escritorio. Es la pantalla principal:
 * el esquema del catálogo pasó a ser un panel plegado (`CalendarioDeVacunas`).
 *
 * **Lo relevado del escritorio** (fuentes en `Docs/MICONSULTAGI-legado/fuentes-pediatria/`), que es de donde salen las
 * columnas y no de una invención:
 *
 *  - La grilla es `dw_34` de la solapa *vacunas* de `w_consulta_principal.srw` (`dataobject = "d_vacunas_consulta_dos"`,
 *    `w_consulta_principal.srw:24410`) y lee **sólo** `vacuna_paciente`, por la historia, ordenada por `codi_vacuna`,
 *    `dosis_numero` y `fecha_ejecutada` (`d_vacunas_consulta_dos.srd:27`). Ése es el orden de esta lista.
 *  - Sus columnas visibles (`d_vacunas_consulta_dos.srd:29-58`) son: **Vacuna Comercial** = `descripcion`, **Tipo de
 *    Vacuna** = `tipo_vacu` (el nombre sale de `tipo_vacuna.vacuna`, `d_vacuna_tipo_dww`), **Dosis** = `dosis`,
 *    **Lote** = `lote`, **F. a Aplicar** = **`fecha_planeada`** (`:37` y el rótulo en `:54`), **F. Aplicada** =
 *    `fecha_ejecutada` (`:38` y `:58`), **Ejec.** = `ejecutada` (casilla `S`/`N`, `:36`) y **Propia** = `aplicada_aca`
 *    (casilla `S`/`N`, `:33`; «propia» es *aplicada en este consultorio*, no `vacunas.inventa_propia`).
 *  - La **segunda línea** de la fila (`d_vacunas_consulta_dos.srd:41-50`) es: **Notas** = `nota`, **Via** = `via`,
 *    **Zona** = `zona`, **Vence** = `fecha_vence` y **Proxima**. De «Próxima» hay que decir dos cosas, porque el fuente y el
 *    cliente no coinciden, y se resuelve con lo que dijo el cliente (Alexander, 2026-11-06):
 *    - En el DataWindow el rótulo «Proxima» está atado a **`fecha_proxima_vac`** (`d_vacunas_consulta_dos.srd:48`), y el
 *      informe *INMUNIZACIONES* imprime «PROX. DOSIS» sobre esa misma columna (`d_inmunizaciones_reporte_grande.srd`).
 *    - Alexander dice que la «Próxima» de esa línea es **`fecha_proxima_dosis`**: se llena **al aplicar** y es el recordatorio
 *      de la próxima dosis de esa misma vacuna; de `fecha_proxima_vac` no está seguro de que sea esa columna.
 *    Se muestra **`fecha_proxima_dosis`** (la que escribe el diálogo de la web y la que imprime el carnet) y **no** se lee ni
 *    se escribe `fecha_proxima_vac`: queda señalado para que lo cierre quien conozca el dato.
 *  - **Aplicada** es `ejecutada = 'S'`. **La verdad de «se aplicó» es la casilla `ejecutada`, no la fecha**
 *    (Alexander, 2026-11-06): el escritorio deja las planeadas con `N` (o vacío) y les escribe en `fecha_ejecutada` el día
 *    en que cargó la fila (`w_escoger_razon_vacuna_consu.srw:1249-1250`: `fecha_plane = today()` y `fec_eje = today()`).
 *    Por eso acá la «F. aplicada» **sólo** se muestra cuando `ejecutada = 'S'`: la fecha que trae una fila planeada es la
 *    de su carga, no la de una aplicación que no ocurrió.
 *  - **«Propia»** (la casilla del extremo derecho de la grilla, O-83): el rótulo está en `d_vacunas_consulta_dos.srd:55` y el
 *    único control de esa franja es `aplicada_aca` (`:33`, casilla `S`/`N`), o sea que la columna sale de ahí. Significa
 *    **«esta dosis la aplicó este consultorio»** (Alexander, 2026-11-06): «Propia es si se aplicó por el doctor, es decir, si
 *    es falso, entonces se vacunó en otra parte». El escritorio la pone en `S` cuando aplica la dosis desde la grilla
 *    (`w_consulta_principal.srw:24097-24099`, el mismo camino que descuenta el inventario); en `N` o vacía, el paciente se
 *    vacunó en otro lado y acá sólo se registra. Por eso **no se muestra la `S`/`N`**: la fila dice «Aplicada acá» o
 *    «Aplicada en otro lado» (`procedencia`), y **sólo** cuando está aplicada — una programada todavía no se aplicó.
 *    **No** es `vacunas.inventa_propia`, que es una columna del catálogo.
 *  - **`fecha_vence`** es el vencimiento de la **ventana** de la vacuna (Alexander, 2026-11-06): «es para no recordar más,
 *    porque hay vacunas que son un requisito cuando tiene 6 meses, pero si se pasa de 9 meses ya no la aplican». Acá se
 *    muestra como dato de la fila; la ventana del catálogo (`vacunas.edad_final_meses`) la aplica el panel, que es donde se
 *    sugiere (ver `CalendarioDeVacunas`).
 *
 * **Lo que esta lista NO hace**: no calcula estados. «Atrasada / próxima / pendiente» son del calendario y viven **sólo**
 * dentro del panel del esquema; acá una fila está *aplicada* o *programada* y nada más. Ese es el pedido de Alexander:
 * que la pantalla no marque como atrasada una dosis que nunca se va a aplicar.
 *
 * Es pura: no consulta nada. Recibe las filas, el catálogo del médico y —si se puede escribir— una función que arma el
 * enlace para quitar una fila programada.
 */
final class ListaDeVacunasDelPaciente
{
    public const APLICADA = 'aplicada';
    public const PROGRAMADA = 'programada';

    /** La dosis la aplicó **este consultorio**: `vacuna_paciente.aplicada_aca = 'S'`. */
    public const APLICADA_ACA = 'aca';

    /** La dosis se aplicó **en otra parte** y acá sólo se registra: `aplicada_aca` en `N` o vacía. */
    public const APLICADA_EN_OTRO_LADO = 'otro-lado';

    private const ROTULOS = [
        self::APLICADA => 'Aplicada',
        self::PROGRAMADA => 'Programada',
    ];

    private const ROTULOS_DE_LA_PROCEDENCIA = [
        self::APLICADA_ACA => 'Aplicada acá',
        self::APLICADA_EN_OTRO_LADO => 'Aplicada en otro lado',
    ];

    public static function rotulo(string $estado): string
    {
        return self::ROTULOS[$estado] ?? $estado;
    }

    /** El rótulo legible de la procedencia de una fila, o `null` si no está aplicada. */
    public static function rotuloDeLaProcedencia(?string $procedencia): ?string
    {
        return $procedencia === null ? null : (self::ROTULOS_DE_LA_PROCEDENCIA[$procedencia] ?? null);
    }

    /**
     * @param  array<int,object>  $filas  las de `vacuna_paciente` del niño (aplicadas y programadas)
     * @param  array<int,object>  $vacunas  el catálogo del médico (`codi_vacuna`, `vacuna`, `tipo_vacu`), para el nombre
     *                                      cuando la fila no lo trae y para saber si la vacuna sigue en el catálogo
     * @param  array<string,string>  $tipos  `tipo_vacu` => nombre del tipo (`tipo_vacuna.vacuna`)
     * @param  Closure(int):?string|null  $urlQuitar  arma el enlace para quitar una fila programada (WEB: `null` = sin acción)
     * @return array{filas:array<int,array<string,mixed>>, resumen:array{aplicadas:int, programadas:int}}
     */
    public function de(array $filas, array $vacunas, array $tipos = [], ?Closure $urlQuitar = null): array
    {
        $catalogo = [];
        foreach ($vacunas as $vacuna) {
            $catalogo[trim((string) $vacuna->codi_vacuna)] = $vacuna;
        }

        usort($filas, fn ($a, $b) => [
            trim((string) $a->codi_vacuna),
            (int) ($a->dosis_numero ?? 0),
            (string) ($a->fecha_ejecutada ?? ''),
        ] <=> [
            trim((string) $b->codi_vacuna),
            (int) ($b->dosis_numero ?? 0),
            (string) ($b->fecha_ejecutada ?? ''),
        ]);

        $salida = [];
        $resumen = [self::APLICADA => 0, self::PROGRAMADA => 0];

        foreach ($filas as $fila) {
            $codigo = trim((string) $fila->codi_vacuna);
            $aplicada = strtoupper((string) ($fila->ejecutada ?? '')) === 'S';
            $estado = $aplicada ? self::APLICADA : self::PROGRAMADA;
            $procedencia = self::procedencia($aplicada, $fila->aplicada_aca ?? null);
            $enCatalogo = isset($catalogo[$codigo]);

            $salida[] = [
                'id' => (int) ($fila->id ?? 0),
                'codigo' => $codigo,
                'vacuna' => self::texto($fila->descripcion ?? null)
                    ?? ($enCatalogo ? self::texto($catalogo[$codigo]->vacuna ?? null) : null)
                    ?? 'Vacuna ' . $codigo,
                'tipo' => self::tipo($fila->tipo_vacu ?? null, $tipos),
                'dosis' => self::texto($fila->dosis ?? null) ?? self::rotuloDeDosis($fila->dosis_numero ?? null),
                'numero' => ($fila->dosis_numero ?? null) === null ? null : (int) $fila->dosis_numero,
                'lote' => self::texto($fila->lote ?? null),
                'planeadaIso' => self::iso($fila->fecha_planeada ?? null),
                'planeada' => self::fecha($fila->fecha_planeada ?? null),
                // La fecha de una fila no aplicada es la del día en que se cargó: no se muestra como «aplicada».
                'aplicadaIso' => $aplicada ? self::iso($fila->fecha_ejecutada ?? null) : null,
                'aplicada' => $aplicada ? self::fecha($fila->fecha_ejecutada ?? null) : null,
                'via' => self::texto($fila->via ?? null),
                'zona' => self::texto($fila->zona ?? null),
                'notas' => self::texto($fila->nota ?? null),
                'vence' => self::fecha($fila->fecha_vence ?? null),
                'proxima' => self::proxima($fila),
                'estado' => $estado,
                'rotulo' => self::rotulo($estado),
                // «Propia» (O-83): si esta dosis la aplicó este consultorio. `null` en una fila programada, porque la
                // pregunta todavía no tiene respuesta (ver el docblock de la clase).
                'procedencia' => $procedencia,
                'procedenciaRotulo' => self::rotuloDeLaProcedencia($procedencia),
                'enCatalogo' => $enCatalogo,
                'urlQuitar' => $aplicada || $urlQuitar === null ? null : $urlQuitar((int) ($fila->id ?? 0)),
            ];

            $resumen[$estado]++;
        }

        return ['filas' => $salida, 'resumen' => $resumen];
    }

    /** El nombre del tipo de vacuna (`tipo_vacuna`) o `null` si el consultorio no lo tiene cargado. */
    private static function tipo(mixed $codigo, array $tipos): ?string
    {
        $codigo = strtoupper(trim((string) $codigo));

        return $codigo === '' ? null : ($tipos[$codigo] ?? null);
    }

    /**
     * La «Próxima» de la segunda línea: `fecha_proxima_dosis`, el recordatorio de la próxima dosis de la misma vacuna que se
     * llena al aplicar (Alexander, 2026-11-06). **No** se usa `fecha_proxima_vac`, que es a la que el DataWindow del
     * escritorio ata el rótulo «Proxima» pero cuya semántica el cliente no confirma: ver el docblock de la clase.
     */
    private static function proxima(object $fila): ?string
    {
        return self::fecha($fila->fecha_proxima_dosis ?? null);
    }

    /**
     * De dónde salió la dosis **aplicada** (O-83, Alexander 2026-11-06): «Propia es si se aplicó por el doctor, es decir, si
     * es falso, entonces se vacunó en otra parte». `vacuna_paciente.aplicada_aca` vale `S` cuando la puso este consultorio y
     * queda en `N` o vacía cuando el paciente se vacunó en otro lado. Una fila **programada** no tiene procedencia: la
     * casilla del escritorio no dice nada de una dosis que todavía no se aplicó.
     */
    private static function procedencia(bool $aplicada, mixed $aplicadaAca): ?string
    {
        if (! $aplicada) {
            return null;
        }

        return strtoupper(trim((string) $aplicadaAca)) === 'S' ? self::APLICADA_ACA : self::APLICADA_EN_OTRO_LADO;
    }

    private static function rotuloDeDosis(mixed $numero): string
    {
        return $numero === null || trim((string) $numero) === '' ? '—' : (int) $numero . 'ª dosis';
    }

    private static function iso(mixed $valor): ?string
    {
        return self::texto($valor) === null ? null : Carbon::parse($valor)->toDateString();
    }

    private static function fecha(mixed $valor): ?string
    {
        return self::texto($valor) === null ? null : Carbon::parse($valor)->format(\App\Support\Regionalizacion::formatoActual());
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
