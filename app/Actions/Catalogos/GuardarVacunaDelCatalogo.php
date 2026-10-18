<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Clinica\Vacunas\ConvencionDeVacunas;
use App\Models\Vacuna;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * **Alta y edición de una vacuna del catálogo del médico** (WEB-7.4.4e): la tabla `vacunas` con su `reg_medico`. Cada médico tiene su propio catálogo
 * (decisión de Alexander, 2026-10-17); las filas sin dueño son el catálogo **inicial** y esta Action **nunca las toca**.
 *
 *  - **El código lo da el servidor** (el mayor numérico del catálogo del médico + 1, en dos dígitos) y **no se edita**: las dosis aplicadas a los niños
 *    lo llevan. La alta se mide dentro de una transacción con las filas del médico bloqueadas.
 *  - **El nombre va en mayúsculas** (como el legado) y no se repite en el catálogo del médico (sin tildes ni mayúsculas).
 *  - `fija` es `O` (Obligatoria) o `R` (Recomendada): la convención provisional de `ConvencionDeVacunas`. El sexo, `A`, `M` o `F`.
 *  - Edita **sólo lo que cambió**, con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins.
 *
 * @see CopiarVacunasIniciales  la otra forma de llenar el catálogo del médico
 */
final class GuardarVacunaDelCatalogo
{
    use EscribeConRastro;

    public const LARGO_NOMBRE = 100;
    public const LARGO_COBERTURA = 200;

    /**
     * @param  array{vacuna?:?string, edad_inicial_meses?:mixed, fija?:?string, sexo?:?string, cobertura?:?string}  $datos
     * @param  int|null  $id  la vacuna que se edita (`null` = alta)
     *
     * @throws InvalidArgumentException con el mensaje que ve el médico
     */
    public function ejecutar(string $regMedico, array $datos, ?int $id = null, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $nombre = mb_strtoupper(trim((string) ($datos['vacuna'] ?? '')));

        if ($nombre === '') {
            throw new InvalidArgumentException('Escribe el nombre de la vacuna.');
        }
        if (mb_strlen($nombre) > self::LARGO_NOMBRE) {
            throw new InvalidArgumentException('El nombre de la vacuna no puede pasar de ' . self::LARGO_NOMBRE . ' caracteres.');
        }

        $fija = strtoupper(trim((string) ($datos['fija'] ?? '')));
        if (! array_key_exists($fija, ConvencionDeVacunas::FIJA)) {
            throw new InvalidArgumentException('Elige si la vacuna es obligatoria o recomendada.');
        }

        $sexo = strtoupper(trim((string) ($datos['sexo'] ?? ''))) ?: 'A';
        if (! array_key_exists($sexo, ConvencionDeVacunas::SEXO)) {
            throw new InvalidArgumentException('El sexo elegido no es válido.');
        }

        $edad = $datos['edad_inicial_meses'] ?? null;
        if ($edad === null || trim((string) $edad) === '') {
            $edad = null;
        } elseif (! preg_match('/^\d{1,4}$/', trim((string) $edad)) || (int) $edad > 1200) {
            throw new InvalidArgumentException('La edad inicial va en meses, con un número entero (hasta 1200).');
        } else {
            $edad = (int) $edad;
        }

        $cobertura = trim((string) ($datos['cobertura'] ?? ''));
        if (mb_strlen($cobertura) > self::LARGO_COBERTURA) {
            throw new InvalidArgumentException('La cobertura no puede pasar de ' . self::LARGO_COBERTURA . ' caracteres.');
        }

        $nuevos = [
            'vacuna' => $nombre,
            'edad_inicial_meses' => $edad,
            'fija' => $fija,
            'sexo' => $sexo,
            'cobertura' => $cobertura === '' ? null : $cobertura,
        ];
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $id, $nuevos, $cuando, $origen) {
            $catalogo = Vacuna::where('reg_medico', $regMedico)->lockForUpdate()->get();

            $repetida = $catalogo->first(fn (Vacuna $v) => (int) $v->id !== (int) $id && self::clave($v->vacuna) === self::clave($nuevos['vacuna']));
            if ($repetida !== null) {
                throw new InvalidArgumentException('Ya tienes una vacuna llamada «' . trim((string) $repetida->vacuna) . '» (código ' . trim((string) $repetida->codi_vacuna) . ').');
            }

            if ($id === null) {
                $this->crearFila(Vacuna::class, 'vacunas', $nuevos + [
                    'reg_medico' => $regMedico,
                    'codi_vacuna' => self::siguienteCodigo($catalogo->pluck('codi_vacuna')->all()),
                ], $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            $fila = $catalogo->firstWhere('id', $id);
            if ($fila === null) {
                throw new InvalidArgumentException('Esa vacuna no está en tu catálogo.');
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'vacunas', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** El mayor código numérico del catálogo + 1, en dos dígitos (`05` → `06`); los que no son números no cuentan. */
    public static function siguienteCodigo(array $codigos): string
    {
        $mayor = 0;
        foreach ($codigos as $codigo) {
            if (ctype_digit(trim((string) $codigo))) {
                $mayor = max($mayor, (int) $codigo);
            }
        }

        return str_pad((string) ($mayor + 1), 2, '0', STR_PAD_LEFT);
    }

    /** El nombre sin tildes ni mayúsculas, para compararlo. */
    private static function clave(?string $nombre): string
    {
        return mb_strtolower(Str::ascii(trim((string) $nombre)));
    }
}
