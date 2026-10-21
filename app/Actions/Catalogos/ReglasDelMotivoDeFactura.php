<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\MotivoFactura;
use InvalidArgumentException;

/**
 * **Las reglas del catálogo de conceptos facturables** (`motivo_factura`, F9), escritas una vez para el alta y la edición.
 *
 *  - `descripcion` va en mayúsculas, hasta 45 (`varchar(45)`); el mismo concepto (sin tildes ni mayúsculas) no se repite.
 *  - `monto` es obligatorio y `monto_seg` (el monto con seguro) es opcional; ninguno es negativo.
 *  - El código lo da el servidor: el mayor numérico del médico + 1, en tres dígitos. ⚠️ **No relevado del escritorio**
 *    (el `.srw` no está en el repo): es la convención de los demás catálogos. La columna `tipo` (`char(1)`) tampoco se
 *    conoce y **no se toca**.
 */
final class ReglasDelMotivoDeFactura
{
    public const LARGO_DESCRIPCION = 45;
    public const DIGITOS_DEL_CODIGO = 3;

    public static function descripcion(?string $texto): string
    {
        return ReglasDelCatalogo::descripcion((string) $texto, self::LARGO_DESCRIPCION, 'concepto');
    }

    /** La descripción comparable: sin tildes, sin mayúsculas, con los espacios colapsados. */
    public static function clave(?string $texto): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));
    }

    /** @throws InvalidArgumentException si falta, no es un número, es negativo o pasa del tope */
    public static function monto($valor): float
    {
        try {
            $monto = ReglasDelMotivoDeCita::precio($valor);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(str_replace('El precio', 'El monto', $e->getMessage()));
        }

        if ($monto === null) {
            throw new InvalidArgumentException('Escribe el monto del concepto.');
        }

        return $monto;
    }

    /** @throws InvalidArgumentException si no es un número, es negativo o pasa del tope */
    public static function montoConSeguro($valor): ?float
    {
        try {
            return ReglasDelMotivoDeCita::precio($valor);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('El monto con seguro no es válido: ' . lcfirst(str_replace('El precio', 'el valor', $e->getMessage())));
        }
    }

    /**
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @throws InvalidArgumentException si otro concepto del médico ya se llama igual
     */
    public static function noDuplicar(array $registros, string $descripcion, ?int $excepto = null): void
    {
        $clave = self::clave($descripcion);

        $otro = MotivoFactura::whereIn('reg_medico', $registros)
            ->when($excepto !== null, fn ($consulta) => $consulta->where('id', '<>', $excepto))
            ->get()
            ->first(fn (MotivoFactura $fila) => self::clave($fila->descripcion) === $clave);

        if ($otro !== null) {
            throw new InvalidArgumentException('Ya tienes «' . trim((string) $otro->descripcion) . '» en el catálogo (código '
                . trim((string) $otro->codigo) . '). Edítalo desde la lista o cambia el nombre.');
        }
    }
}
