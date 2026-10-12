<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\TipoExamen;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un tipo de examen** (WEB-8.1b, el *Nuevo* de `w_agregar_tipo_exameen`).
 *
 * Como en el escritorio, **el código lo da el servidor**: el mayor del catálogo + 1 en **tres dígitos**
 * (`registro2 ++` y `right("00"+trim(codigo),3)`, `w_agregar_tipo_exameen.srw:127-129`), y el nombre va en mayúsculas con
 * tope de 40 (`d_a_tipo_examen.srd`). Si ya hay un tipo con el mismo nombre (sin distinguir tildes ni mayúsculas) **no
 * se duplica**: se devuelve el existente y la pantalla lo avisa. Deja un `created` en `sync_changes` (`source = web`).
 *
 * `tipos_examenes` tiene clave primaria en el PowerBuilder (`codetipo`) y triggers de fila: el escritorio la sube por
 * fila (trampa 60 de `TRAMPAS.md`). El móvil no guarda esta tabla.
 */
final class CrearTipoDeExamenEnElCatalogo
{
    use EscribeConRastro;

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila: el del contexto
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @return array{0: TipoExamen, 1: bool} el tipo y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, pasa de 40 caracteres o el catálogo está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        string $tipo,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): array {
        $tipo = ReglasDelCatalogo::descripcion($tipo, EditarTipoDeExamenDelCatalogo::LARGO, 'tipo de examen');
        $registros = array_values(array_unique(array_merge($registrosMedicos, [$regMedico])));
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $registros, $tipo, $cuando, $origen) {
            $catalogo = TipoExamen::whereIn('reg_medico', $registros)->get();

            $clave = BusquedaDePacientes::normalizar($tipo);
            $existente = $catalogo->first(fn (TipoExamen $fila) => BusquedaDePacientes::normalizar(trim((string) $fila->tipo)) === $clave);

            if ($existente) {
                return [$existente, false];
            }

            $fila = $this->crearFila(TipoExamen::class, 'tipos_examenes', [
                'reg_medico' => $regMedico,
                'codetipo' => ReglasDelCatalogo::siguienteCodigo($catalogo->pluck('codetipo'), 'tipos de examen', 3),
                'tipo' => $tipo,
            ], $cuando, $origen);

            return [$fila, true];
        });
    }
}
