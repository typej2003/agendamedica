<?php

namespace App\Clinica\Vacunas;

use Carbon\Carbon;
use Closure;

/**
 * **La lista de vacunas del paciente** (O-68 y O-69, Alexander 2026-11-06): las filas de `vacuna_paciente` del niño —lo
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
 *  - **«Propia»** (la casilla del extremo derecho de la grilla): el rótulo está en `d_vacunas_consulta_dos.srd:55` y el único
 *    control de esa franja es `aplicada_aca` (`:33`, casilla `S`/`N`), o sea que la columna sale de ahí. **Qué significa no
 *    está relevado** (Alexander no lo sabe), así que no se muestra ni se escribe: queda señalado.
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

    private const ROTULOS = [
        self::APLICADA => 'Aplicada',
        self::PROGRAMADA => 'Programada',
    ];

    public static function rotulo(string $estado): string
    {
        return self::ROTULOS[$estado] ?? $estado;
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
