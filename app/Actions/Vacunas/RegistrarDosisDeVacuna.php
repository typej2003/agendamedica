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
 * **Registrar una dosis aplicada** (WEB-7.4.4b): escribe en `vacuna_paciente` (clave del escritorio: `historia` + `codi_vacuna` +
 * `fecha_ejecutada`) lo que el calendario de vacunas del niño (WEB-7.4.4a) deja registrar. La usa la web y la reutilizará el móvil (R3).
 *
 * Desde O-68/O-69 es la acción del botón **«Aplicar»** de la lista del paciente (`ListaDeVacunasDelPaciente`) y del «Registrar»
 * del panel del esquema; su hermana `ProgramarDosisDeVacuna` crea la fila planeada y `QuitarDosisDeVacuna` la retira. Las tres
 * comparten las reglas de la vacuna y de la identidad de la dosis en el trait `DosisDeVacuna`.
 *
 * Las reglas:
 *
 *  - **La vacuna tiene que ser del catálogo del médico** (`vacunas` con su `reg_medico`): una desconocida se rechaza. La descripción, la prioridad
 *    y el tipo se copian de ahí.
 *  - **Una dosis se aplica una vez**: si ya hay una fila aplicada (`ejecutada = 'S'`) para esa dosis —por su número o, sin número, por su
 *    rótulo— se rechaza, no se duplica.
 *  - **Si el escritorio había dejado esa dosis como planeada** (`ejecutada` distinta de `S`), esa fila se **completa**; si no, se crea una nueva.
 *    Al completar sólo se escribe **lo que cambió**, con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins.
 *    Es también el camino de una dosis **programada desde la web** (`ProgramarDosisDeVacuna`): la fila planeada se completa en el lugar.
 *  - **La fecha** no puede ser futura (en el día del consultorio) ni anterior al nacimiento. La **edad aplicada** (`edad_aplicada`, en meses) se
 *    calcula con el nacimiento. `aplicada_aca = 'S'`: se aplicó en este consultorio.
 *  - **Son dos booleanos, no tres desenlaces** (O-83, Alexander 2026-11-06): «Uno si se aplicó o no, y el otro si se aplicó acá
 *    o no (si es propia)». Esta Action siempre deja el primero en sí (`ejecutada = 'S'`); el segundo es `$aplicadaAca`, que
 *    **por defecto es `true`** (`aplicada_aca = 'S'`) para que el móvil y los llamadores de siempre no cambien de
 *    comportamiento. Con `false` la dosis queda **aplicada en otro lado**: `ejecutada = 'S'` y `aplicada_aca = 'N'`, que es lo
 *    que la web muestra como «Aplicada en otro lado». El HTTP valida que el formulario sólo mande `S` o `N`.
 *  - **El cobro y el inventario no se tocan** (`vacunas_aplicadas`, `vacunas_salidas`: se difieren con la facturación, F9).
 *
 * El escritorio no verá nunca lo que se registre acá (no tiene bajada): se anota en `PENDIENTES-POWERBUILDER.md`.
 */
final class RegistrarDosisDeVacuna
{
    // Lo que comparte con `ProgramarDosisDeVacuna` y `QuitarDosisDeVacuna`: la vacuna del catálogo, la identidad de la
    // dosis y la lectura de fechas y textos (ver el trait).
    use DosisDeVacuna;
    use EscribeConRastro;

    /** Los campos opcionales que el formulario puede mandar y su tope (las columnas del escritorio). */
    public const TEXTOS = ['lote' => 20, 'via' => 20, 'zona' => 20];

    /**
     * @param  array<string,mixed>  $datos  `fecha_ejecutada` (obligatoria) y, opcionales, `lote`, `via`, `zona`, `fecha_vence`, `fecha_proxima_dosis`, `nota`
     * @param  string|null  $nacimiento  `AAAA-MM-DD` del niño, para validar la fecha y calcular la edad aplicada
     * @param  string|null  $planeada  la fecha que le tocaba (`AAAA-MM-DD`), si el calendario la conoce
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     * @param  bool  $aplicadaAca  `true` (por defecto) = la aplicó este consultorio; `false` = se aplicó en otro lado (O-83)
     * @return object  `fila` (el modelo), `creada` (bool) y `columnas` (las que se escribieron al completar una planeada)
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
        ?string $planeada = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
        // Va al final a propósito: ningún llamador de siempre (el móvil, las pruebas) cambia de comportamiento ni de firma.
        bool $aplicadaAca = true,
    ): object {
        $vacuna = self::vacunaDelCatalogo($regMedico, $codigo);

        $fecha = self::fecha($datos['fecha_ejecutada'] ?? null, 'La fecha en que se aplicó');
        if ($fecha === null) {
            throw new InvalidArgumentException('Escribe la fecha en que se aplicó la dosis.');
        }
        if ($fecha->gt(FechaDelConsultorio::hoy()->startOfDay())) {
            throw new InvalidArgumentException('La fecha de aplicación no puede ser posterior a hoy.');
        }
        $nace = $nacimiento ? Carbon::parse($nacimiento)->startOfDay() : null;
        if ($nace !== null && $fecha->lt($nace)) {
            throw new InvalidArgumentException('La fecha de aplicación no puede ser anterior al nacimiento del niño.');
        }

        $vence = self::fecha($datos['fecha_vence'] ?? null, 'La fecha de vencimiento');
        $proxima = self::fecha($datos['fecha_proxima_dosis'] ?? null, 'La fecha de la próxima dosis');
        if ($proxima !== null && $proxima->lt($fecha)) {
            throw new InvalidArgumentException('La próxima dosis no puede ser anterior a la aplicación.');
        }

        $rotulo = self::texto($rotulo, 50);
        $nuevos = [
            'dosis' => $rotulo,
            'dosis_numero' => $dosisNumero,
        // La fecha que le tocaba se escribe **sólo si viene**: cuando se aplica una dosis que ya estaba programada, la fila
        // conserva su `fecha_planeada` (si no, se borraría con `null`).
        ] + ($planeada ? ['fecha_planeada' => Carbon::parse($planeada)->toDateString()] : []) + [
            'fecha_ejecutada' => $fecha->toDateString(),
            'ejecutada' => 'S',
        ] + self::copiadoDelCatalogo($vacuna) + [
            'edad_aplicada' => $nace === null ? null : round($nace->diffInDays($fecha) / 30.4375, 2),
            // El segundo booleano (O-83): `S` si la aplicó este consultorio, `N` si el paciente se vacunó en otra parte.
            'aplicada_aca' => $aplicadaAca ? 'S' : 'N',
            'fecha_vence' => $vence?->toDateString(),
            'fecha_proxima_dosis' => $proxima?->toDateString(),
            'nota' => self::texto($datos['nota'] ?? null, 2000),
        ];
        foreach (self::TEXTOS as $campo => $tope) {
            $nuevos[$campo] = self::texto($datos[$campo] ?? null, $tope);
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $historia, $codigo, $dosisNumero, $rotulo, $nuevos, $cuando, $origen) {
            $filas = VacunaPaciente::query()
                ->where('reg_medico', $regMedico)->where('historia', $historia)->where('codi_vacuna', $codigo)
                ->lockForUpdate()->orderBy('id')->get();

            $esaDosis = $filas->filter(fn (VacunaPaciente $f) => self::esLaMisma($f, $dosisNumero, $rotulo));

            if ($esaDosis->contains(fn (VacunaPaciente $f) => strtoupper((string) $f->ejecutada) === 'S')) {
                throw new InvalidArgumentException('Esa dosis ya está registrada como aplicada.');
            }

            // Dos filas con la misma clave del escritorio (historia + vacuna + fecha) no pueden convivir.
            $mismaClave = $filas->first(fn (VacunaPaciente $f) => $f->fecha_ejecutada?->toDateString() === $nuevos['fecha_ejecutada']);
            $planeadaDelEscritorio = $esaDosis->first();

            if ($mismaClave !== null && ($planeadaDelEscritorio === null || $mismaClave->id !== $planeadaDelEscritorio->id)) {
                throw new InvalidArgumentException('Ya hay una dosis de esa vacuna registrada ese día.');
            }

            if ($planeadaDelEscritorio !== null) {
                [$escritas] = $this->corregirFila($planeadaDelEscritorio, 'vacuna_paciente', $nuevos, $cuando, $origen);

                return (object) ['fila' => $planeadaDelEscritorio->refresh(), 'creada' => false, 'columnas' => $escritas];
            }

            $fila = $this->crearFila(VacunaPaciente::class, 'vacuna_paciente', $nuevos + [
                'reg_medico' => $regMedico, 'historia' => $historia, 'codi_vacuna' => $codigo,
            ], $cuando, $origen);

            return (object) ['fila' => $fila, 'creada' => true, 'columnas' => []];
        });
    }
}
