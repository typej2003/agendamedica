<?php

namespace App\Clinica\Impresion;

use App\Clinica\Crecimiento\EdadEnTexto;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Paciente;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Throwable;

/**
 * **Los datos del paciente tal como salen en la cabecera de un documento impreso** (WEB-5.2, reusado por WEB-5.3a):
 * nombre, cédula con su nacionalidad, edad **a la fecha del documento** y dirección.
 *
 * Es una sola regla para todos los documentos (récipes, constancias, reposos, referencias, informes): si cada uno
 * armara su cabecera, el mismo paciente saldría con la edad o la cédula distintas según el papel.
 *
 *  - **Cédula**: `V-12345678`; `null` si el paciente no tiene (el `S.C.` del legado no cuenta como cédula).
 *  - **Edad**: los años cumplidos **a la fecha del documento**, no a la de hoy —un documento reimpreso años después sigue
 *    diciendo la edad que el paciente tenía—; `null` si la fecha de nacimiento no sirve o es posterior al documento.
 *
 * ## La cabecera de pediatría (2026-10-20)
 *
 * En los impresos de **pediatría** la hoja lleva además lo que imprime el escritorio en su récipe: el **sexo**, el
 * **representante**, el **número de historia** y la **edad compacta** («21A 10M 16D»). Eso lo arma
 * `datosPediatricos()`, que devuelve los mismos datos de siempre **más** esos cuatro; `datos()` se queda como estaba y
 * es lo que usan las demás especialidades, así que su hoja no cambia. Quién decide «es pediatría» es el controlador
 * (`AlcanceDeLaEspecialidad`), no esta clase: acá sólo se arman los datos.
 *
 * Es una clase **sin estado y sin base de datos** (recibe el modelo ya leído); el nombre del representante lo lee
 * `RepresentanteParaLaHoja` y llega acá ya resuelto.
 */
final class PacienteImpreso
{
    /**
     * @return array{nombre:string, cedula:?string, edad:?int, direccion:?string}
     */
    public static function datos(Paciente $paciente, string $fecha): array
    {
        return [
            'nombre' => self::nombre($paciente),
            'cedula' => self::cedula($paciente),
            'edad' => self::edadA($paciente->fnacimiento, $fecha),
            'direccion' => CamposDePaciente::texto($paciente->direccion),
        ];
    }

    /**
     * **La cabecera de un impreso de pediatría**: la de siempre más lo que imprime el escritorio —`sexo`,
     * `representante`, `historia` y `edadCompacta`—, cada uno `null` si el dato no está (el rótulo no se imprime y
     * no queda un «Rep.:» colgando).
     *
     * @param  mixed  $numeroDeHistoria  el número de la consulta; se imprime con cinco dígitos (`00025`) si es numérico
     * @return array{nombre:string, cedula:?string, edad:?int, direccion:?string, sexo:?string, representante:?string, historia:?string, edadCompacta:?string}
     */
    public static function datosPediatricos(Paciente $paciente, string $fecha, $numeroDeHistoria, ?string $representante): array
    {
        return self::datos($paciente, $fecha) + [
            'sexo' => self::sexo($paciente),
            'representante' => CamposDePaciente::texto($representante),
            'historia' => self::historia($numeroDeHistoria),
            'edadCompacta' => EdadEnTexto::compacta(FechaClinica::paraInput($paciente->fnacimiento), $fecha),
        ];
    }

    /**
     * La línea que agrega la cabecera de pediatría, como texto y con sus rótulos: «SEXO: F   Rep.: ELDRIS MENDOZA
     *   Historia: 00025», sin los datos que no hay. La usan la hoja (para saber cuánto alto ocupa: `MedidasDeLaMitad`)
     * y la vista, que la pinta con los rótulos en negrita.
     *
     * `null` si no hay ninguno de los tres (una cabecera sin nada que agregar).
     */
    public static function lineaPediatrica(array $persona): ?string
    {
        $partes = [];

        foreach (['SEXO' => $persona['sexo'] ?? null, 'Rep.' => $persona['representante'] ?? null, 'Historia' => $persona['historia'] ?? null] as $rotulo => $valor) {
            $texto = CamposDePaciente::texto($valor);

            if ($texto !== null) {
                $partes[] = $rotulo . ': ' . $texto;
            }
        }

        // Tres espacios entre dato y dato, para que el texto se lea: la separación fina la pone el CSS
        // (`.recipe-dato-linea`) y la descuenta `MedidasDeLaMitad` al medir el alto.
        return $partes === [] ? null : implode('   ', $partes);
    }

    /** `Nombres Apellidos`, como lo arman los documentos del legado (`nombres + ' ' + apellidos`). */
    public static function nombre(Paciente $paciente): string
    {
        return trim(trim((string) $paciente->nombres) . ' ' . trim((string) $paciente->apellidos));
    }

    /** `V-12345678`: la cédula con su nacionalidad, o `null` si el paciente no tiene. */
    public static function cedula(Paciente $paciente): ?string
    {
        $cedula = CamposDePaciente::cedula($paciente->cedula);

        if ($cedula === null) {
            return null;
        }

        return (CamposDePaciente::texto($paciente->nac) ?? 'V') . '-' . $cedula;
    }

    /**
     * El **sexo** como lo imprime el escritorio: `F` o `M`. `null` si la ficha no lo tiene (o tiene otra cosa: no se
     * inventa una sigla).
     */
    public static function sexo(Paciente $paciente): ?string
    {
        $sexo = mb_strtoupper((string) CamposDePaciente::texto($paciente->sexo));

        return in_array($sexo, ['F', 'M'], true) ? $sexo : null;
    }

    /**
     * El **número de historia** con los cinco dígitos del escritorio (`25` → `00025`), como el listado de pacientes. Un
     * número que no es numérico se imprime tal cual (es un dato del legado y no se toca); `null` si no hay número.
     */
    public static function historia($numero): ?string
    {
        $texto = CamposDePaciente::texto($numero);

        if ($texto === null) {
            return null;
        }

        return ctype_digit($texto) ? str_pad($texto, 5, '0', STR_PAD_LEFT) : $texto;
    }

    /** Los años cumplidos a la fecha del documento; `null` si la fecha de nacimiento no sirve o es posterior. */
    public static function edadA($fnacimiento, string $fecha): ?int
    {
        if (FechaClinica::paraInput($fnacimiento) === '') {
            return null;
        }

        try {
            $nacimiento = Carbon::parse(FechaClinica::paraInput($fnacimiento));
            $al = Carbon::parse($fecha);
        } catch (Throwable $e) {
            return null;
        }

        return $nacimiento->greaterThan($al) ? null : $nacimiento->diffInYears($al);
    }
}
