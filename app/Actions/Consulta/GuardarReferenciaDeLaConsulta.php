<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\Referencia;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Referir al paciente a un médico** (WEB-5.3a, pestaña *Referencias*): crear la referencia o corregir su motivo.
 *
 * Una referencia es la fila `(nrohistoria, nroconsulta, ceduladoctor)` de `referencia` con el **motivo** en
 * `referencia.referencia`: **varias por consulta, una por médico** (`ReferenciasDeLaConsulta` tiene las citas). El médico
 * referido sale del **catálogo `doctores` del propio médico**: no se refiere a quien no está en su libreta (el alta rápida
 * lo agrega, `AgregarDoctorAlCatalogo`). Como en el escritorio, la referencia puede crearse **sin motivo** (el `INSERT`
 * de *Pasar* no lleva texto, `:18150`) y el motivo se escribe después.
 *
 * Si la consulta ya refiere a ese médico, **se corrige el motivo en el lugar**; no se duplica.
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea, `updated` de `referencia` con el last-write-wins
 * del sync, y cambia `updated_at` (el móvil baja `referencia` por delta).
 */
final class GuardarReferenciaDeLaConsulta
{
    use EscribeConRastro;

    public const LARGO_MAXIMO = 20000;

    public function __construct(
        private ReferenciasDeLaConsulta $referencias,
        private DoctoresDelMedico $doctores,
    ) {
    }

    /**
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto: su catálogo de colegas
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si el médico referido no está en el catálogo del médico
     */
    public function ejecutar(
        Consulta $consulta,
        array $registrosMedicos,
        int $cedula,
        ?string $motivo,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        if (! $this->doctores->estaEnElCatalogo($registrosMedicos, $cedula)) {
            throw new InvalidArgumentException('Ese médico no está en tu catálogo: agrégalo primero.');
        }

        $cuando = $this->ahora($cuando);
        $motivo = TextoDeLaConsulta::limpio($motivo);

        return DB::transaction(function () use ($consulta, $cedula, $motivo, $cuando, $origen) {
            $fila = $this->referencias->fila($consulta, $cedula);

            if ($fila === null) {
                $this->crearFila(Referencia::class, 'referencia', [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'ceduladoctor' => $cedula,
                    'referencia' => $motivo,
                ], $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'referencia', ['referencia' => $motivo], $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}
