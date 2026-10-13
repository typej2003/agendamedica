<?php

namespace App\Clinica\Consulta;

/**
 * **Los valores de un estudio ecográfico de una consulta**, ya leídos de su tabla (WEB-6.1).
 *
 * Es un objeto de valores sin estado y sin base de datos: la lectura la hace el mapa del estudio
 * (`App\Clinica\Consulta\EcoGinecologico`) y esto es lo que viaja al ViewModel y a la Action. Existe
 * porque el estudio es **una fila con clave del legado** y las tres capas (lectura, formulario y
 * escritura) tienen que mirar el mismo valor por columna, con la misma normalización de los "vacíos".
 *
 * A diferencia de la evaluación física, que reparte sus columnas en dos tablas, acá todo vive en una:
 * por eso el objeto es un envoltorio de un solo mapa de columnas.
 */
final class EstudioEcografico
{
    /** @param array<string,mixed> $valores las columnas de la fila (clave => valor crudo) */
    public function __construct(private array $valores = [])
    {
    }

    /**
     * El valor de una columna, normalizado: `null` cuando el legado guardó "nada".
     *
     * Se aplica la misma vara que en la evaluación física (trampa 29): `NULL`, la cadena vacía **y** el
     * literal `none` que el escritorio dejó escrito en varias tablas son el mismo "sin dato", para no
     * mostrar `none` como si fuera un hallazgo.
     */
    public function valor(string $columna): ?string
    {
        return self::limpiar($this->valores[$columna] ?? null);
    }

    /**
     * Una columna tal como quedó en la fila, **sin** normalizar.
     *
     * Lo usa lo que se muestra y no se edita (hoy `puro_texto`, el informe en texto libre del escritorio)
     * y lo que necesita el número crudo, no su texto.
     */
    public function crudo(string $columna)
    {
        return $this->valores[$columna] ?? null;
    }

    /** El número de una columna, o `null` si no hay un número positivo. */
    public function numero(string $columna): ?float
    {
        $valor = $this->valor($columna);

        return is_numeric($valor) ? (float) $valor : null;
    }

    /** Todas las columnas de la fila, normalizadas: es lo que la Action compara contra lo que llega. */
    public function valores(): array
    {
        $salida = [];
        foreach (array_keys($this->valores) as $columna) {
            $salida[$columna] = $this->valor($columna);
        }

        return $salida;
    }

    /** ¿La fila trae algún dato de los que la web edita? */
    public function tieneAlgoDe(array $columnas): bool
    {
        foreach ($columnas as $columna) {
            if ($this->valor($columna) !== null) {
                return true;
            }
        }

        return false;
    }

    /** La fecha del estudio (`AAAA-MM-DD`), tal como está en la columna. */
    public function fecha(): ?string
    {
        return $this->valor('fecha');
    }

    /** La fecha de la última regla (`AAAA-MM-DD`), tal como está en la columna. */
    public function fur(): ?string
    {
        return $this->valor('fur');
    }

    /** El mismo "sin dato" en todas partes: `null`, `''` y el literal `none` del escritorio. */
    public static function limpiar($crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        if (is_float($crudo) || is_int($crudo)) {
            // Un número no se recorta ni se convierte a "0": el 0 de `gesta` es un valor del legado.
            return (string) $crudo;
        }

        $texto = trim((string) $crudo);

        return ($texto === '' || strcasecmp($texto, 'none') === 0) ? null : $texto;
    }
}
