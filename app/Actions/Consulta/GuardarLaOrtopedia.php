<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrtopedia;
use App\Models\Consulta;
use App\Models\Ortopedia;
use App\Sync\RegistroDeCambios;
use Illuminate\Support\Carbon;

/**
 * **Guardar la plantilla ortopédica** (Traumatología, 2026-11-07): una fila por consulta, **sólo lo que cambió**, con el
 * rastro por columna en `sync_changes`.
 *
 * Las casillas del mapa (`tipo = 'marca'`) se guardan como **`1`/`0`**, que es lo que escribe el escritorio
 * (`IF ls_bot="1"` en `w_consulta_principal.srw`); el resto es texto recortado a su ancho.
 */
final class GuardarLaOrtopedia
{
    /**
     * @param  array<string,?string>  $datos  clave del mapa => valor del formulario
     * @return array{escribio:bool, fila:?Ortopedia}
     */
    public function ejecutar(Consulta $consulta, array $datos, ?string $origen = 'web'): array
    {
        $fila = Ortopedia::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->first();

        $nueva = $fila === null;
        $fila ??= new Ortopedia([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
        ]);

        $cambiadas = [];

        foreach (MapaDeLaOrtopedia::campos() as $campo) {
            if (! array_key_exists($campo['clave'], $datos)) {
                continue;
            }

            $valor = $this->normalizar($campo, $datos[$campo['clave']]);

            if (($fila->{$campo['columna']} ?? null) === $valor) {
                continue;
            }

            $fila->{$campo['columna']} = $valor;
            $cambiadas[] = $campo['columna'];
        }

        // Sin nada cargado no se crea la fila vacía (la regla del escritorio y de las secciones de la web): todas las
        // casillas en `0` y los textos vacíos es una plantilla en blanco, no un dato.
        if ($nueva && $this->vacia($fila)) {
            return ['escribio' => false, 'fila' => null];
        }

        if ($nueva || $cambiadas !== []) {
            $fila->save();
        }

        if ($origen !== null && ($nueva || $cambiadas !== [])) {
            $id = (int) $fila->getKey();

            if ($nueva) {
                RegistroDeCambios::registrarOperacion('ortopedia', $id, $consulta->reg_medico, 'created', null, null, Carbon::now(), $origen);
            } else {
                foreach ($cambiadas as $columna) {
                    RegistroDeCambios::registrarOperacion('ortopedia', $id, $consulta->reg_medico, 'updated', $columna, $fila->{$columna}, Carbon::now(), $origen);
                }
            }
        }

        return ['escribio' => $nueva || $cambiadas !== [], 'fila' => $fila];
    }

    /** ¿La fila quedó en blanco? (casillas en `0` y textos vacíos). */
    private function vacia(Ortopedia $fila): bool
    {
        foreach (MapaDeLaOrtopedia::columnas() as $columna) {
            $valor = trim((string) $fila->{$columna});

            if ($valor !== '' && $valor !== '0' && $valor !== '1') {
                return false;
            }

            if ($valor === '1') {
                return false;
            }
        }

        return true;
    }

    private function normalizar(array $campo, ?string $valor): ?string    {
        if ($campo['tipo'] === 'marca') {
            return in_array($valor, ['1', 'S', 's', 'on'], true) ? '1' : '0';
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, (int) ($campo['max'] ?? 65000));
    }
}
