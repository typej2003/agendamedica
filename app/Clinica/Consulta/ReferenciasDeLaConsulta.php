<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\Referencia;
use Illuminate\Support\Collection;

/**
 * **Las referencias de una consulta** (WEB-5.3a, pestaña *Referencias* de las *Solicitudes*, ítem 11 del panel): la
 * remisión del paciente a otro médico, con el motivo.
 *
 * ## De qué cuelga y cuántas hay (verificado en la fuente)
 *
 * `referencia` se llavea por `nrohistoria` + `nroconsulta` + **`ceduladoctor`** (`PRIMARY KEY` de las tres en
 * `esquemas-asa/*.sql`; `d_referencia__observa_paci.srd`, las tres `key=yes`): **varias por consulta, una por médico**.
 * El flujo del escritorio es «elegir al médico del listado y *Pasar*» —un `INSERT` con la historia, la consulta y la
 * cédula del médico, **sin texto** (`w_consulta_principal.srw:18131-18160`, `pb_31`, el `INSERT` en `:18150`)—, y después escribir el motivo en
 * la caja *Explicación*. `Eliminar` borra la fila de ese médico (`pb_32`, el `DELETE` en `:18101`) y *Agregar* abre el alta de un médico nuevo (`pb_33`, `open(w_nuevo_medico)`, `:18061`).
 *
 * **El motivo es por médico en la tabla y en la web, no así en la ventana del escritorio**: su caja *Explicación* es
 * una sola (`mle_referencia`) y su `UPDATE` no filtra por `ceduladoctor` (`ue_salvar_ref`, `:17799-17806`), así que
 * al guardar **pisa el motivo de todas las referencias de la consulta con el mismo texto**. Es un descuido de la
 * pantalla: la columna `referencia.referencia` es de cada fila y la hoja que se imprime (`d_referencia_2.srd`) la lee
 * por médico. La web guarda cada motivo en **su** referencia.
 *
 * Es de **sólo lectura**: la escritura son `GuardarReferenciaDeLaConsulta` y `EliminarReferenciaDeLaConsulta`.
 */
final class ReferenciasDeLaConsulta
{
    public function __construct(private DoctoresDelMedico $doctores)
    {
    }

    /**
     * Las referencias de la consulta, en el orden en que se hicieron, cada una con su médico ya resuelto.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto (para resolver al médico referido)
     * @return Collection<int,object{id:int, cedula:int, motivo:?string, medico:?object}>
     */
    public function deLaConsulta(Consulta $consulta, array $registros): Collection
    {
        $filas = Referencia::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->get();

        $medicos = $this->doctores->porCedulas($registros, $filas->pluck('ceduladoctor')->map(fn ($cedula) => (int) $cedula)->all());

        return $filas->map(fn (Referencia $fila) => (object) [
            'id' => (int) $fila->id,
            'cedula' => (int) $fila->ceduladoctor,
            'motivo' => TextoDeLaConsulta::limpio($fila->referencia),
            'medico' => $medicos[(int) $fila->ceduladoctor] ?? null,
        ])->values();
    }

    /** La fila de esa consulta y ese médico, o `null`. */
    public function fila(Consulta $consulta, int $cedula): ?Referencia
    {
        return Referencia::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('ceduladoctor', $cedula)
            ->orderBy('id')
            ->first();
    }
}
