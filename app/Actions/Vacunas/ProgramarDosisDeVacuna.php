<?php

namespace App\Actions\Vacunas;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\FechaDelConsultorio;
use App\Models\VacunaPaciente;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Programar una dosis que todavía no se aplicó** (O-68/O-69, Alexander 2026-11-06): crea la fila **planeada** de
 * `vacuna_paciente` que antes sólo dejaba el escritorio, para que la web pueda cargar las vacunas «una por una» como la
 * solapa *Inmunizaciones* de PediaReport, sin inventar un esquema completo.
 *
 * **Lo relevado del escritorio** —las columnas y los valores salen de ahí, no de una suposición:
 *
 *  - El botón *Buscar* de la solapa abre `w_escoger_razon_vacuna_consu.srw` («Escoja la vacuna y dosis», `:1180`) con el
 *    desplegable de la dosis (`ddlb_1`, `:256`: *Recién Nacido · 1era. Dosis · 2da. Dosis · 3era. Dosis · REFUERZO*) y la
 *    lista del catálogo (`dw_35` = `d_vacunas_consulta`, o sea la tabla `vacunas` del médico). *Aceptar* (`pb_1`,
 *    `:1200-1289`) inserta **una** fila: `historia`, `codi_vacuna`, `dosis` = el texto elegido, `fecha_ejecutada` = **hoy**,
 *    `fecha_planeada` = **hoy** (`:1249-1250`), `ejecutada` = `N`, `descripcion` = el nombre comercial, `dosis_numero`,
 *    `edad_aplicada`, `prioridad`, `lote`, `tipo_vacu`, `aplicada_aca` (`:1254-1280`).
 *  - La otra vía, *Buscar* (`pb_55`, `w_consulta_principal.srw:24164-24383`), es la que armaba **todo el esquema** de una vez
 *    (`for` sobre el catálogo y sobre `vacunas_dosis`, `:24209-24226`, con `fecha_planeada` = nacimiento + meses,
 *    `:24238-24305`) y es la que Alexander ya no quiere: marca atrasadas dosis que nunca se van a aplicar.
 *  - `vacuna_paciente` tiene clave en el escritorio (`historia` + `codi_vacuna` + `dosis_numero`, índice único `primarykey`
 *    en `fuentes-pediatria/miconsultat/f_agregar_datos.srf:1179`) y se sube **por fila**, no por tabla entera: por eso una
 *    fila programada acá se sincroniza igual que una aplicada. `fecha_ejecutada` es **NOT NULL** en el esquema del API
 *    (`2026_10_13_100000_create_tablas_pediatria.php:299`) y el escritorio la llena con el día de la carga: es lo que se
 *    hace acá (`FechaDelConsultorio::hoy()`), y `RegistrarDosisDeVacuna` la reescribe con la fecha real cuando la dosis se
 *    aplica (el escritorio hace lo mismo con «Pasar fecha», `w_consulta_principal.srw:23967`).
 *  - **La verdad de «se aplicó» es la casilla `ejecutada` (`S`/`N`), no la fecha** (Alexander, 2026-11-06): el escritorio
 *    escribe la fecha del día en `fecha_ejecutada` cuando agrega la fila y **no la usa** para saber si la dosis se aplicó.
 *    Es contraintuitivo y es justo lo que haría escribir la regla mal, así que va dicho acá: una fila con `ejecutada = 'N'`
 *    está **programada** aunque traiga una `fecha_ejecutada`. Nada de centinelas ni de `0000-00-00`.
 *
 * Las reglas:
 *
 *  - **La vacuna tiene que ser del catálogo del médico**; la descripción, la prioridad y el tipo se copian de ahí.
 *  - **La dosis tiene que estar identificada** (`dosis_numero` ≥ 1): es la clave de la fila en el escritorio.
 *  - **Una dosis ya no se programa dos veces**: si ya hay una fila de esa dosis —programada o aplicada— se rechaza con un
 *    mensaje que dice cuál es el caso. Nunca se duplica (el esquema del catálogo propone, el médico agrega).
 *  - **La fecha a aplicar** no puede ser anterior al nacimiento; sí puede ser pasada (una dosis atrasada que se programa
 *    para registrarla después) o futura.
 *  - Deja el rastro `created` en `sync_changes` con `source = web`, como el resto de las Actions de la web.
 *
 * Una fila programada **no baja** al escritorio (su sync es sólo de subida): se anota en `PENDIENTES-POWERBUILDER.md`.
 */
final class ProgramarDosisDeVacuna
{
    use DosisDeVacuna;
    use EscribeConRastro;

    /**
     * @param  array<string,mixed>  $datos  `fecha_planeada` (obligatoria) y, opcional, `nota`
     * @param  string|null  $nacimiento  `AAAA-MM-DD` del niño, para no programar antes de nacer
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     * @return object  `fila` (el modelo) y `creada`
     *
     * @throws InvalidArgumentException
     */
    public function ejecutar(
        string $regMedico,
        int $historia,
        string $codigo,
        ?int $dosisNumero,
        ?string $rotulo,
        array $datos,
        ?string $nacimiento = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): object {
        $vacuna = self::vacunaDelCatalogo($regMedico, $codigo);

        if ($dosisNumero === null || $dosisNumero < 1) {
            throw new InvalidArgumentException('Elige la dosis que vas a programar.');
        }

        $planeada = self::fecha($datos['fecha_planeada'] ?? null, 'La fecha a aplicar');
        if ($planeada === null) {
            throw new InvalidArgumentException('Escribe la fecha en que se le va a aplicar la dosis.');
        }

        $nace = $nacimiento ? Carbon::parse($nacimiento)->startOfDay() : null;
        if ($nace !== null && $planeada->lt($nace)) {
            throw new InvalidArgumentException('La fecha a aplicar no puede ser anterior al nacimiento del niño.');
        }

        $rotulo = self::texto($rotulo, 50);
        $hoy = FechaDelConsultorio::hoy()->startOfDay();
        $cuando = $this->ahora($cuando);

        $nuevos = [
            'dosis' => $rotulo,
            'dosis_numero' => $dosisNumero,
            'fecha_planeada' => $planeada->toDateString(),
            // La tabla del API exige `fecha_ejecutada`; el escritorio la llena con el día de la carga (ver el docblock).
            'fecha_ejecutada' => $hoy->toDateString(),
            'ejecutada' => 'N',
            'nota' => self::texto($datos['nota'] ?? null, 2000),
        ] + self::copiadoDelCatalogo($vacuna);

        return DB::transaction(function () use ($regMedico, $historia, $codigo, $dosisNumero, $rotulo, $nuevos, $cuando, $origen) {
            $filas = VacunaPaciente::query()
                ->where('reg_medico', $regMedico)->where('historia', $historia)->where('codi_vacuna', $codigo)
                ->lockForUpdate()->orderBy('id')->get();

            $esaDosis = $filas->filter(fn (VacunaPaciente $f) => self::esLaMisma($f, $dosisNumero, $rotulo));

            if ($esaDosis->contains(fn (VacunaPaciente $f) => strtoupper((string) $f->ejecutada) === 'S')) {
                throw new InvalidArgumentException('Esa dosis ya está registrada como aplicada.');
            }

            if ($esaDosis->isNotEmpty()) {
                throw new InvalidArgumentException('Esa dosis ya está en la lista del paciente.');
            }

            $fila = $this->crearFila(VacunaPaciente::class, 'vacuna_paciente', $nuevos + [
                'reg_medico' => $regMedico, 'historia' => $historia, 'codi_vacuna' => $codigo,
            ], $cuando, $origen);

            return (object) ['fila' => $fila, 'creada' => true];
        });
    }
}
