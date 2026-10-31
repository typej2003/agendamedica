<?php

namespace App\Services\IA;

/**
 * Lee la lista de modelos de OpenRouter de una variable del `.env`, con dos formatos aceptados:
 *
 *   - JSON:  `["google/gemma-4-26b-a4b-it","google/gemma-4-31b-it"]`
 *   - CSV:   `google/gemma-4-26b-a4b-it, google/gemma-4-31b-it`
 *
 * El modelo principal se antepone siempre y se eliminan duplicados y vacíos. Así el `.env` puede decir sólo
 * los respaldos y el principal sigue viviendo en `OPENROUTER_MODEL` / `OPENROUTER_MODELO_OCR`.
 */
final class ModelosDeOpenRouter
{
    /**
     * @param  mixed  $valor  el valor crudo de config (string, array o null)
     * @return list<string>
     */
    public static function lista(mixed $valor, ?string $principal = null): array
    {
        $modelos = [];

        if (is_array($valor)) {
            $modelos = $valor;
        } else {
            $texto = trim((string) $valor);

            if ($texto !== '') {
                $json = json_decode($texto, true);
                $modelos = is_array($json) ? $json : explode(',', $texto);
            }
        }

        if ($principal !== null && trim($principal) !== '') {
            array_unshift($modelos, $principal);
        }

        $limpios = [];
        foreach ($modelos as $modelo) {
            $modelo = trim((string) $modelo);
            if ($modelo !== '' && ! in_array($modelo, $limpios, true)) {
                $limpios[] = $modelo;
            }
        }

        return $limpios;
    }
}
