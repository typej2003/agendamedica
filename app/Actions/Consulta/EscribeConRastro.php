<?php

namespace App\Actions\Consulta;

use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que comparten las Actions de las **solicitudes de la consulta** (WEB-5.3a) para escribir una fila legada y dejar
 * su rastro en `sync_changes`: crear con su `created`, corregir **sólo lo que cambió** con el last-write-wins por columna
 * (`RegistroDeCambios::permite`) y borrar con su `deleted`.
 *
 * Es la misma mecánica que ya hacen `GuardarEvolucionDeLaConsulta` y `GuardarRecipeDeLaPareja`, escrita una vez para
 * las cuatro tablas nuevas (`constancia_obs`, `reposo_paciente`, `referencia`, `informe`) en lugar de cuatro veces.
 * Sin `$origen` no hay rastro y, por lo tanto, nada contra qué decidir: se escribe.
 *
 * El rastro importa por dos razones, y las dos se verificaron antes de escribir esto: **el móvil** baja las cuatro
 * tablas por el delta de `updated_at` y se entera de las bajas por el `deleted` de `sync_changes`
 * (`SyncAppDataController`, `eliminados`), y **el escritorio** sube sus propias ediciones fila por fila (las cuatro
 * tablas tienen clave primaria en el PowerBuilder, así que `CambiosEscritorio` las trata con `guardarGenerico` /
 * `borrarGenerico` y **no** con el reemplazo de la tabla entera: ver la trampa 51 de `TRAMPAS.md`).
 */
trait EscribeConRastro
{
    private function ahora(?CarbonInterface $cuando): Carbon
    {
        return $cuando ? Carbon::instance($cuando) : Carbon::now();
    }

    /**
     * Crea la fila y deja su `created` (con el `reg_medico` de la fila).
     *
     * @param  class-string<Model>  $modelo
     * @param  array<string,mixed>  $datos  incluye `reg_medico`
     */
    private function crearFila(string $modelo, string $tabla, array $datos, Carbon $cuando, ?string $origen): Model
    {
        $fila = $modelo::create($datos);

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion($tabla, (int) $fila->getKey(), $datos['reg_medico'] ?? null, 'created', null, null, $cuando, $origen);
        }

        return $fila;
    }

    /**
     * Escribe en la fila **sólo las columnas que cambiaron**, cada una con su last-write-wins: una columna con una
     * edición más nueva no se pisa, pero eso no impide escribir la otra.
     *
     * @param  array<string,mixed>  $nuevos  `columna => valor` ya normalizado (texto recortado, `null` si está vacío)
     * @return array{0:array<int,string>, 1:array<int,string>}  las columnas escritas y las bloqueadas
     */
    private function corregirFila(Model $fila, string $tabla, array $nuevos, Carbon $cuando, ?string $origen): array
    {
        $escritas = [];
        $bloqueadas = [];

        foreach ($nuevos as $columna => $valor) {
            if ($this->comparable($fila->getAttribute($columna)) === $this->comparable($valor)) {
                continue;
            }

            if ($origen !== null && ! RegistroDeCambios::permite($tabla, (int) $fila->getKey(), $columna, $cuando)) {
                $bloqueadas[] = $columna;
                continue;
            }

            $fila->setAttribute($columna, $valor);
            $escritas[$columna] = $valor;
        }

        if ($escritas !== []) {
            // Eloquent: cambia `updated_at`, que es lo que el delta del móvil mira.
            $fila->save();

            if ($origen !== null) {
                foreach ($escritas as $columna => $valor) {
                    RegistroDeCambios::registrar($tabla, (int) $fila->getKey(), $fila->getAttribute('reg_medico'), $columna, $this->comparable($valor), $cuando, $origen);
                }
            }
        }

        return [array_keys($escritas), $bloqueadas];
    }

    /** Borra la fila y deja su `deleted` (lo que el móvil usa para soltarla). */
    private function borrarFila(Model $fila, string $tabla, Carbon $cuando, ?string $origen): void
    {
        $id = (int) $fila->getKey();
        $reg = $fila->getAttribute('reg_medico');

        $fila->delete();

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion($tabla, $id, $reg, 'deleted', null, null, $cuando, $origen);
        }
    }

    /** Un valor como texto comparable: `null` y `''` son lo mismo, una fecha es su `AAAA-MM-DD`. */
    private function comparable($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof CarbonInterface) {
            return $valor->format('Y-m-d');
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
