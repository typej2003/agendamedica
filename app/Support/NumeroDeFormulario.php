<?php

namespace App\Support;

/**
 * **Los números de un formulario: una sola regla para leerlos y para guardarlos** (O-08).
 *
 * En la base, una medida (la talla, el peso, una circunferencia) es una columna `double` y se guarda con
 * **punto decimal** (`1.62`). En la pantalla, en cambio, el médico **teclea con coma** —`1,62`, como se
 * escribe en español y como lo muestra el escritorio— y la máscara (`public/js/clinica/mascara-numero.js`)
 * pone la coma sola. Es la misma diferencia que `FechaDeFormulario` resuelve para las fechas: la máscara es
 * de la pantalla, el dato guardado conserva el formato de la base.
 *
 * Esta clase es el **único lugar** donde esa diferencia se resuelve:
 *
 *  - {@see aBase()} lee lo que llega (`1,62` o `1.62`) y devuelve el número listo para la columna, o `null`
 *    si no es un número (no se inventa nada: `1,6,2` no es `1.62`).
 *  - {@see normalizar()} hace eso mismo sobre el arreglo de un `POST`, **antes de validar**: así
 *    `nullable|numeric` acepta lo que el médico ve y la Action recibe el formato de la base. Lo que no se
 *    puede leer **queda como vino**, para que la validación lo rechace y el médico vea el mensaje en vez de
 *    que el dato se guarde «corregido» a otra cosa.
 *
 * Lo usan la evaluación física (talla, peso, circunferencias, frecuencias) y el «Actualizar» del resumen,
 * que arma su texto con los mismos valores del formulario. La tensión **no** pasa por acá: es texto
 * (`120/80`) y su formato es el de la pantalla.
 */
final class NumeroDeFormulario
{
    /**
     * El número que se guarda en la columna (`1.62`, `70.5`, `80`), o `null` si viene vacío o no es un
     * número.
     *
     * Acepta la coma decimal (la de la pantalla) y el punto (el de la base, el de las plantillas y el del
     * sync), con espacios alrededor y sin signo: estas columnas son medidas y cantidades, nunca negativas.
     */
    public static function aBase($valor): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        // `1,62`, `1.62`, `162` — un solo separador decimal, dígitos a los dos lados.
        if (! preg_match('/^\d+(?:[.,]\d+)?$/', $texto)) {
            return null;
        }

        return str_replace(',', '.', $texto);
    }

    /**
     * Pasa al formato de la base las columnas numéricas de un `POST`, antes de validarlo.
     *
     * Lo que no se puede leer **queda como vino**: la validación `numeric` lo rechaza y el mensaje dice cuál
     * es el campo. Convertirlo «a la fuerza» sería inventar una medida clínica.
     *
     * @param  array<string,mixed>  $datos  los datos del formulario
     * @param  array<int,string>  $columnas  las columnas numéricas que trae ese formulario
     * @return array<string,mixed>
     */
    public static function normalizar(array $datos, array $columnas): array
    {
        foreach ($columnas as $columna) {
            if (! array_key_exists($columna, $datos)) {
                continue;
            }

            // Vacía es «sin dato», como en el resto de los vacíos del escritorio.
            if (is_string($datos[$columna]) && trim($datos[$columna]) === '') {
                $datos[$columna] = null;
                continue;
            }

            $base = self::aBase($datos[$columna]);

            if ($base !== null) {
                $datos[$columna] = $base;
            }
        }

        return $datos;
    }
}
