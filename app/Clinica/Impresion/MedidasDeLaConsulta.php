<?php

namespace App\Clinica\Impresion;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Consulta;

/**
 * **Las medidas del paciente que la mitad de *Indicaciones* del récipe agrega en pediatría** (2026-10-20): el peso, la
 * talla, la circunferencia cefálica y la tensión arterial, tal como los imprime el escritorio:
 *
 * ```
 * PESO: 20 Kg   TALLA: 100 Cm   C. CEFÁLICA: 48.5 Cm   T.A.: 120/80
 * ```
 *
 * **Sólo salen las medidas que existen y son mayores que cero**: el legado guarda `0` en las columnas que nadie cargó
 * (una talla de `0` no es una talla), así que un `0` se trata como vacío. La línea entera aparece si hay al menos una
 * medida; si no hay ninguna, no se imprime nada.
 *
 * Las medidas son de la **consulta** (`consultas.peso`, `talla`, `circcefalica` y las tres tensiones), no del
 * paciente: son las que se tomaron ese día. La tensión es texto y se imprime **tal cual** (`120/80`), tomando la
 * primera que tenga valor —`tasentado`, `taacostado`, `tapie`, en ese orden—.
 *
 * Es una clase **sin estado** (recibe la consulta ya leída) y no decide si se imprimen: eso lo hace el récipe, que
 * sólo se las pide a la hoja de pediatría.
 */
final class MedidasDeLaConsulta
{
    /** La unidad de cada medida, como la escribe el escritorio. */
    private const UNIDADES = ['peso' => 'Kg', 'talla' => 'Cm', 'circcefalica' => 'Cm'];

    /** El rótulo de cada medida, tal cual el récipe del escritorio. */
    private const ROTULOS = ['peso' => 'PESO:', 'talla' => 'TALLA:', 'circcefalica' => 'C. CEFÁLICA:'];

    /** Las columnas de la tensión arterial, en el orden en que se toma la primera que tenga valor. */
    private const TENSIONES = ['tasentado', 'taacostado', 'tapie'];

    /**
     * Las medidas que existen, cada una con su rótulo y su unidad (`[['etiqueta' => 'PESO:', 'valor' => '20 Kg'], …]`).
     *
     * @return array<int,array{etiqueta:string, valor:string}>
     */
    public static function de(Consulta $consulta): array
    {
        $medidas = [];

        foreach (self::UNIDADES as $columna => $unidad) {
            $numero = self::numero($consulta->{$columna});

            if ($numero !== null) {
                $medidas[] = ['etiqueta' => self::ROTULOS[$columna], 'valor' => $numero . ' ' . $unidad];
            }
        }

        $tension = self::tension($consulta);

        if ($tension !== null) {
            $medidas[] = ['etiqueta' => 'T.A.:', 'valor' => $tension];
        }

        return $medidas;
    }

    /**
     * La línea de las medidas como texto, con sus rótulos: «PESO: 20 Kg   TALLA: 100 Cm   …». La usan la hoja (para
     * saber cuánto alto ocupa: `MedidasDeLaMitad`) y la vista, que la pinta con los rótulos en negrita. `null` si no
     * hay ninguna medida.
     *
     * @param  array<int,array{etiqueta:string, valor:string}>  $medidas
     */
    public static function linea(array $medidas): ?string
    {
        if ($medidas === []) {
            return null;
        }

        // Tres espacios entre medida y medida, para que el texto se lea: la separación fina la pone el CSS
        // (`.recipe-dato-linea`) y la descuenta `MedidasDeLaMitad` al medir el alto.
        return implode('   ', array_map(fn (array $medida) => $medida['etiqueta'] . ' ' . $medida['valor'], $medidas));
    }

    /**
     * El número de una medida sin ceros de más (`20`, `48.5`), o `null` si no hay o no es mayor que cero. El `0` del
     * legado no es una medida.
     *
     * @param  mixed  $valor
     */
    private static function numero($valor): ?string
    {
        if (! is_numeric($valor) || (float) $valor <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');
    }

    /** La primera tensión arterial que tenga valor, tal cual está escrita (`120/80`), o `null` si no hay ninguna. */
    private static function tension(Consulta $consulta): ?string
    {
        foreach (self::TENSIONES as $columna) {
            $texto = CamposDePaciente::texto($consulta->{$columna});

            if ($texto !== null) {
                return (string) preg_replace('/\s+/u', ' ', $texto);
            }
        }

        return null;
    }
}
