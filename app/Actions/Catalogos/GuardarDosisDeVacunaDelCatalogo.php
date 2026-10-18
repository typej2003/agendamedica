<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Models\Vacuna;
use App\Models\VacunaDosis;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta, edición y baja de una dosis de una vacuna del médico** (WEB-7.4.4e): `vacunas_dosis` con su `reg_medico`. Las dosis son el calendario que arma
 * cada médico: número, rótulo (un **refuerzo** se reconoce por el rótulo, no por `obligatoria`) y edad en meses. Sin dosis, la vacuna cuenta como
 * una dosis única a su edad inicial (`CalendarioDeVacunas`).
 *
 * El número no se repite dentro de la vacuna. Sólo escribe lo que cambió, con el rastro por columna (`source = web`). Nunca toca las dosis del
 * catálogo inicial ni las de otro consultorio. Quitar una dosis que ya se aplicó no borra la aplicación: el calendario la lista como «aplicada» suelta.
 */
final class GuardarDosisDeVacunaDelCatalogo
{
    use EscribeConRastro;

    /**
     * @param  array{dosis?:mixed, dosis_descri?:?string, meses?:mixed}  $datos
     * @param  int|null  $dosisId  la dosis que se edita (`null` = alta)
     *
     * @throws InvalidArgumentException
     */
    public function ejecutar(string $regMedico, int $vacunaId, array $datos, ?int $dosisId = null, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $numero = trim((string) ($datos['dosis'] ?? ''));
        if (! preg_match('/^\d{1,2}$/', $numero) || (int) $numero < 1) {
            throw new InvalidArgumentException('El número de la dosis es un entero de 1 a 99.');
        }

        $rotulo = trim((string) ($datos['dosis_descri'] ?? ''));
        if (mb_strlen($rotulo) > 50) {
            throw new InvalidArgumentException('El rótulo de la dosis no puede pasar de 50 caracteres.');
        }

        $meses = $datos['meses'] ?? null;
        if ($meses === null || trim((string) $meses) === '') {
            $meses = null;
        } else {
            $texto = str_replace(',', '.', trim((string) $meses));
            if (! is_numeric($texto) || (float) $texto < 0 || (float) $texto > 1200) {
                throw new InvalidArgumentException('La edad de la dosis va en meses (un número de 0 a 1200).');
            }
            $meses = round((float) $texto, 2);
        }

        $nuevos = ['dosis' => (int) $numero, 'dosis_descri' => $rotulo === '' ? null : $rotulo, 'meses' => $meses];
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $vacunaId, $dosisId, $nuevos, $cuando, $origen) {
            $vacuna = Vacuna::where('reg_medico', $regMedico)->where('id', $vacunaId)->lockForUpdate()->first();

            if ($vacuna === null) {
                throw new InvalidArgumentException('Esa vacuna no está en tu catálogo.');
            }

            $dosis = VacunaDosis::where('reg_medico', $regMedico)->where('codi_vacuna', $vacuna->codi_vacuna)->get();

            if ($dosis->contains(fn (VacunaDosis $d) => (int) $d->dosis === $nuevos['dosis'] && (int) $d->id !== (int) $dosisId)) {
                throw new InvalidArgumentException('Esta vacuna ya tiene una dosis con ese número.');
            }

            if ($dosisId === null) {
                $this->crearFila(VacunaDosis::class, 'vacunas_dosis', $nuevos + ['reg_medico' => $regMedico, 'codi_vacuna' => $vacuna->codi_vacuna], $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            $fila = $dosis->firstWhere('id', $dosisId);
            if ($fila === null) {
                throw new InvalidArgumentException('Esa dosis no está en tu catálogo.');
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'vacunas_dosis', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** Quita una dosis del médico. @throws InvalidArgumentException si no es suya */
    public function quitar(string $regMedico, int $dosisId, ?CarbonInterface $cuando = null, ?string $origen = 'web'): void
    {
        $fila = VacunaDosis::where('reg_medico', $regMedico)->where('id', $dosisId)->first();

        if ($fila === null) {
            throw new InvalidArgumentException('Esa dosis no está en tu catálogo.');
        }

        $this->borrarFila($fila, 'vacunas_dosis', $this->ahora($cuando), $origen);
    }
}
