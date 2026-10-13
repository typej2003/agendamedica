<?php

namespace App\Actions\Configuracion;

use App\Clinica\Archivos\ImagenesDelMembrete;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * **Guardar el membrete del médico** (`evolucion`): la especialidad, el RIF, la ciudad, las líneas de pie, las
 * plantillas de mensaje y el **logo** (WEB-8.2).
 *
 * Es **la** regla de escritura de esa configuración, y la usan las dos superficies sin repetirla (PLAN-WEB.md, R3): el API
 * del móvil (`ConfiguracionMedicoController`, "Diseño de reportes") y la pantalla "Formato de impresión" de la web.
 *
 * ## Lo que hace
 *
 *  - Escribe **sólo** las columnas que vienen en `$campos` (actualización parcial: lo que no viene queda como estaba) y,
 *    de ellas, sólo las que **cambian** (`''` y `null` son lo mismo).
 *  - Guarda bajo el **`reg_medico` principal** del médico, una fila por médico, igual que el móvil. La fila nueva lleva
 *    `clave = 0`: es `NOT NULL` en el esquema legado y un dato del reporte viejo que acá no se usa.
 *  - El **logo** va al disco `public` (`logos/…`, la misma ruta del API) y su ruta se escribe en `evolucion.logo`. Al
 *    reemplazarlo o quitarlo se borra el archivo anterior **si nadie más lo usa**; si la fila no queda, se borra el que
 *    se acababa de subir (no quedan huérfanos).
 *
 * ## El rastro para el sync (`$origen`)
 *
 * `evolucion` es una tabla legada que el escritorio también sube. Con `$origen = 'web'` cada columna escrita deja su
 * `updated` en `sync_changes` (y una fila nueva su `created`) y se respeta el **last-write-wins por columna**: si ya hay
 * un cambio igual o más nuevo sobre esa columna, no se pisa y se informa en `bloqueadas`. Con `$origen = null` —el API
 * del móvil, que nunca dejó rastro de esta tabla— no hay rastro ni guard, **igual que antes**.
 */
final class GuardarMembreteDelMedico
{
    /**
     * @param  array<string,mixed>  $campos  `columna => valor` de los textos a escribir (las de `LimitesDelMembrete`)
     *                                       y, sólo para el API, `reg_medico` (corregir el número que se imprime)
     * @param  bool  $quitarLogo  quitar el logo actual (sólo la web; el API no lo expone). Si viene `$logo`, gana el archivo
     *
     * @throws InvalidArgumentException  si el médico no tiene un registro principal bajo el cual guardar
     */
    public function ejecutar(
        Medico $medico,
        array $campos,
        ?UploadedFile $logo = null,
        bool $quitarLogo = false,
        ?CarbonInterface $cuando = null,
        ?string $origen = null,
    ): ResultadoDelMembrete {
        $registro = $medico->regMedicoPrincipal();

        if ($registro === null || trim($registro) === '') {
            throw new InvalidArgumentException('Este médico no tiene un número de registro principal bajo el cual guardar el membrete.');
        }

        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        $evolucion = Evolucion::where('reg_medico', $registro)->orderBy('id')->first();
        $existia = $evolucion !== null;
        $evolucion ??= new Evolucion(['reg_medico' => $registro]);

        foreach (array_keys(LimitesDelMembrete::POR_COLUMNA) as $columna) {
            if (array_key_exists($columna, $campos)) {
                $this->poner($evolucion, $columna, $campos[$columna]);
            }
        }

        if (array_key_exists('reg_medico', $campos)) {
            // Permite corregir el número de registro médico que se imprime, sin cambiar la clave con la que esta fila se
            // busca (esa sigue siendo la del médico): un valor vacío vuelve al del registro principal.
            $evolucion->reg_medico = $campos['reg_medico'] ?? $registro;
        }

        $logoViejo = $evolucion->logo;
        $logoNuevo = null;

        if ($logo !== null) {
            $logoNuevo = ImagenesDelMembrete::guardar($logo, ImagenesDelMembrete::CARPETA_LOGO);
            $evolucion->logo = $logoNuevo;
        } elseif ($quitarLogo && trim((string) $logoViejo) !== '') {
            $evolucion->logo = null;
        }

        // `clave` es NOT NULL en el esquema legado y no tiene default: una fila nueva sin ese dato del PowerBuilder
        // (la contraseña de acceso al reporte viejo, que acá no se usa) rompería el insert.
        if (! $existia && $evolucion->clave === null) {
            $evolucion->clave = 0;
        }

        $bloqueadas = [];

        if ($existia && $origen !== null) {
            foreach (array_keys($evolucion->getDirty()) as $columna) {
                if (! RegistroDeCambios::permite('evolucion', (int) $evolucion->id, $columna, $cuando)) {
                    $evolucion->setAttribute($columna, $evolucion->getOriginal($columna));
                    $bloqueadas[] = $columna;
                }
            }

            if (in_array('logo', $bloqueadas, true)) {
                ImagenesDelMembrete::descartar($logoNuevo);
                $logoNuevo = null;
            }
        }

        // En una fila nueva `reg_medico` y `clave` son la llave y el relleno del esquema, no un cambio del médico.
        $escritas = array_values(array_diff(
            array_keys($evolucion->getDirty()),
            ['updated_at', 'created_at'],
            $existia ? [] : ['reg_medico', 'clave'],
        ));

        try {
            DB::transaction(function () use ($evolucion, $escritas, $existia, $cuando, $origen) {
                $evolucion->save();

                if ($origen === null) {
                    return;
                }

                if (! $existia) {
                    RegistroDeCambios::registrarOperacion('evolucion', (int) $evolucion->id, $evolucion->reg_medico, 'created', null, null, $cuando, $origen);
                }

                foreach ($escritas as $columna) {
                    RegistroDeCambios::registrar('evolucion', (int) $evolucion->id, $evolucion->reg_medico, $columna, $evolucion->getAttribute($columna), $cuando, $origen);
                }
            });
        } catch (Throwable $e) {
            // La fila no quedó: el archivo que se acababa de subir no lo referencia nadie.
            ImagenesDelMembrete::descartar($logoNuevo);

            throw $e;
        }

        if ($evolucion->logo !== $logoViejo) {
            ImagenesDelMembrete::borrarSiNadieLoUsa($logoViejo);
        }

        return new ResultadoDelMembrete($evolucion, $escritas, $bloqueadas);
    }

    /** Pone el valor si cambia: `null` y `''` son el mismo estado (sin texto) y no cuentan como cambio. */
    private function poner(Evolucion $evolucion, string $columna, mixed $valor): void
    {
        $nuevo = is_string($valor) ? trim($valor) : $valor;
        $nuevo = $nuevo === '' ? null : $nuevo;

        $actual = $evolucion->getAttribute($columna);
        $actual = is_string($actual) && trim($actual) === '' ? null : $actual;

        if ($actual === $nuevo) {
            return;
        }

        $evolucion->setAttribute($columna, $nuevo);
    }
}
