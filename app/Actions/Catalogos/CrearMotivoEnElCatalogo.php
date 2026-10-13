<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarMotivoAlCatalogo;
use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Medico;
use App\Models\MotivoConsulta;
use App\Sync\RegistroDeCambios;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un motivo de consulta desde el ABM del catálogo** (WEB-8.1b, el *Nuevo* de `w_agregar_motivos_consul`).
 *
 * La regla del alta es la de `AgregarMotivoAlCatalogo` (mayúsculas, tope de 40, correlativo de cuatro dígitos y "si ya
 * hay uno igual, devuelve el existente"), la misma que usan el alta rápida de la consulta y `POST /api/app/motivos-consulta`
 * del móvil: no se escribe otra vez. El ABM suma el **rastro** (`created`, `source = web`). Las dos superficies que ya
 * existían no cambian.
 *
 * `motivos_consulta` tiene clave primaria en el PowerBuilder (`codemotivo`) y los triggers de fila: se sube **por fila**
 * y no necesita protección (trampa 60 de `TRAMPAS.md`).
 */
final class CrearMotivoEnElCatalogo
{
    use EscribeConRastro;

    public function __construct(private AgregarMotivoAlCatalogo $alta)
    {
    }

    /**
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @return array{0: MotivoConsulta, 1: bool} el motivo y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si la descripción falta o es muy larga, o el catálogo está lleno
     */
    public function ejecutar(
        Medico $medico,
        array $registrosMedicos,
        string $descripcion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): array {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($medico, $registrosMedicos, $descripcion, $cuando, $origen) {
            // El alta rápida compara en mayúsculas pero con tildes; el ABM no deja crear un duplicado que sólo difiera en
            // tildes (`PELVICO` y `PÉLVICO` son el mismo nombre): se busca antes con la comparación sin tildes.
            $clave = BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', $descripcion)));
            $igual = MotivoConsulta::whereIn('reg_medico', array_values(array_unique(array_merge($registrosMedicos, [(string) $medico->regMedicoPrincipal()]))))
                ->get()
                ->first(fn (MotivoConsulta $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion))) === $clave);

            if ($igual !== null) {
                return [$igual, false];
            }

            [$motivo, $creado] = $this->alta->ejecutar($medico, $registrosMedicos, $descripcion);

            if ($creado && $origen !== null) {
                RegistroDeCambios::registrarOperacion('motivos_consulta', (int) $motivo->id, $motivo->reg_medico, 'created', null, null, $cuando, $origen);
            }

            return [$motivo, $creado];
        });
    }
}
