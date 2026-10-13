<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Examen;
use App\Models\TipoExamen;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un examen o estudio del catálogo** (WEB-8.1b, el *Nuevo* de `w_agregar_examenes_estudios`).
 *
 * Como en el escritorio, **el código lo da el servidor**: el mayor `codeexamen` del catálogo + 1 en **tres dígitos**
 * (`ue_numero`, `w_agregar_examenes_estudios.srw:77-91`: `max(codeexamen)` y `right("00"+trim(codigo),3)`), y el nombre va
 * en mayúsculas con tope de 45 (`d_a_examene_estudios.srd`). El **tipo** (`codetipo`) es uno de `tipos_examenes` del
 * médico: en el escritorio el tipo se elige antes de numerar (`ddplb_estu`); acá es un campo del formulario y es
 * opcional (un examen puede quedar sin tipo, como los hay en los datos).
 *
 * ## No duplicar
 *
 * Un examen es **el mismo** que otro cuando tiene el mismo nombre (sin distinguir tildes ni mayúsculas) **y** el mismo
 * tipo: una «ECOGRAFIA» de imágenes y una «ECOGRAFIA» de preoperatorios son cosas distintas. Si ya hay uno igual no se
 * crea y se devuelve el existente. Deja un `created` en `sync_changes` (`source = web`).
 *
 * `examenes` tiene clave primaria en el PowerBuilder (`codeexamen`) y triggers de fila: se sube por fila (trampa 60 de
 * `TRAMPAS.md`). El móvil no guarda esta tabla.
 */
final class CrearExamenEnElCatalogo
{
    use EscribeConRastro;

    public const LARGO_NOMBRE = 45;

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila: el del contexto
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo, el tipo y no duplicar
     * @return array{0: Examen, 1: bool} el examen y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, pasa de 45 caracteres, el tipo no es del catálogo o está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        string $examen,
        ?string $codetipo,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): array {
        $examen = ReglasDelCatalogo::descripcion($examen, self::LARGO_NOMBRE, 'examen');
        $registros = array_values(array_unique(array_merge($registrosMedicos, [$regMedico])));
        $codetipo = CamposDePaciente::texto($codetipo);
        $cuando = $this->ahora($cuando);

        if ($codetipo !== null && ! TipoExamen::whereIn('reg_medico', $registros)->where('codetipo', $codetipo)->exists()) {
            throw new InvalidArgumentException('Elige un tipo de la lista.');
        }

        return DB::transaction(function () use ($regMedico, $registros, $examen, $codetipo, $cuando, $origen) {
            $catalogo = Examen::whereIn('reg_medico', $registros)->get();

            $clave = BusquedaDePacientes::normalizar($examen);
            $existente = $catalogo->first(
                fn (Examen $fila) => BusquedaDePacientes::normalizar(trim((string) $fila->examen)) === $clave
                    && CamposDePaciente::texto($fila->codetipo) === $codetipo
            );

            if ($existente) {
                return [$existente, false];
            }

            $fila = $this->crearFila(Examen::class, 'examenes', [
                'reg_medico' => $regMedico,
                'codeexamen' => ReglasDelCatalogo::siguienteCodigo($catalogo->pluck('codeexamen'), 'exámenes', 3),
                'examen' => $examen,
                'codetipo' => $codetipo,
            ], $cuando, $origen);

            return [$fila, true];
        });
    }
}
