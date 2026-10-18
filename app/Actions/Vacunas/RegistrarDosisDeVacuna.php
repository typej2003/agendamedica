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
 * Las reglas:
 *
 *  - **La vacuna tiene que ser del catálogo del médico** (`vacunas` con su `reg_medico`): una desconocida se rechaza. La descripción, la prioridad
 *    y el tipo se copian de ahí.
 *  - **Una dosis se aplica una vez**: si ya hay una fila aplicada (`ejecutada = 'S'`) para esa dosis —por su número o, sin número, por su
 *    rótulo— se rechaza, no se duplica.
 *  - **Si el escritorio había dejado esa dosis como planeada** (`ejecutada` distinta de `S`), esa fila se **completa**; si no, se crea una nueva.
 *    Al completar sólo se escribe **lo que cambió**, con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins.
 *  - **La fecha** no puede ser futura (en el día del consultorio) ni anterior al nacimiento. La **edad aplicada** (`edad_aplicada`, en meses) se
 *    calcula con el nacimiento. `aplicada_aca = 'S'`: se aplicó en este consultorio.
 *  - **El cobro y el inventario no se tocan** (`vacunas_aplicadas`, `vacunas_salidas`: se difieren con la facturación, F9).
 *
 * El escritorio no verá nunca lo que se registre acá (no tiene bajada): se anota en `PENDIENTES-POWERBUILDER.md`.
 */
final class RegistrarDosisDeVacuna
{
    use EscribeConRastro;

    /** Los campos opcionales que el formulario puede mandar y su tope (las columnas del escritorio). */
    public const TEXTOS = ['lote' => 20, 'via' => 20, 'zona' => 20];

    /**
     * @param  array<string,mixed>  $datos  `fecha_ejecutada` (obligatoria) y, opcionales, `lote`, `via`, `zona`, `fecha_vence`, `fecha_proxima_dosis`, `nota`
     * @param  string|null  $nacimiento  `AAAA-MM-DD` del niño, para validar la fecha y calcular la edad aplicada
     * @param  string|null  $planeada  la fecha que le tocaba (`AAAA-MM-DD`), si el calendario la conoce
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
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
    ): object {
        $vacuna = DB::table('vacunas')->where('reg_medico', $regMedico)->where('codi_vacuna', $codigo)->orderBy('id')->first();

        if ($vacuna === null) {
            throw new InvalidArgumentException('Esa vacuna no está en el catálogo de este consultorio.');
        }

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
            'fecha_planeada' => $planeada ? Carbon::parse($planeada)->toDateString() : null,
            'fecha_ejecutada' => $fecha->toDateString(),
            'ejecutada' => 'S',
            'descripcion' => self::texto($vacuna->vacuna, 100),
            'prioridad' => self::texto($vacuna->prioridad ?? null, 1),
            'tipo_vacu' => self::texto($vacuna->tipo_vacu ?? null, 10),
            'edad_aplicada' => $nace === null ? null : round($nace->diffInDays($fecha) / 30.4375, 2),
            'aplicada_aca' => 'S',
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

    /** ¿La fila es de esa dosis? Por su número y, si alguno falta, por su rótulo (sin distinguir mayúsculas). */
    private static function esLaMisma(VacunaPaciente $fila, ?int $numero, ?string $rotulo): bool
    {
        if ($numero !== null && $fila->dosis_numero !== null) {
            return (int) $fila->dosis_numero === $numero;
        }

        $propio = mb_strtolower(trim((string) $fila->dosis));

        return $rotulo !== null && $propio !== '' && $propio === mb_strtolower($rotulo);
    }

    private static function fecha(mixed $valor, string $que): ?Carbon
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("{$que} no es una fecha válida.");
        }
    }

    private static function texto(mixed $valor, int $tope): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $tope);
    }
}
