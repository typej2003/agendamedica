<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Actions\Consulta\EscribeConRastro;
use App\Models\Diagnostico;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Medico;
use App\Sync\RegistroDeCambios;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un diagnóstico desde el ABM del catálogo** (WEB-8.1b, el *Nuevo* de `w_agregar_dianostico`).
 *
 * La regla del alta **no se escribe otra vez**: las mayúsculas, el tope de 50, el correlativo de cuatro dígitos y el "si
 * ya hay uno igual, devuelve el existente" son los de `AgregarDiagnosticoAlCatalogo` (WEB-4.5), la misma Action que
 * usa el alta rápida de la pestaña de diagnósticos de la consulta. Lo que suma el ABM es el **rastro**: un `created` en
 * `sync_changes` con `source = web`. El alta rápida de la consulta no cambia.
 *
 * `diagnosticos` tiene clave primaria en el PowerBuilder (`codediagnostico`) y los triggers de fila: el escritorio la
 * sube **por fila** y una fila creada acá no corre el riesgo de que la subida la borre (trampa 60 de `TRAMPAS.md`).
 */
final class CrearDiagnosticoEnElCatalogo
{
    use EscribeConRastro;

    public function __construct(private AgregarDiagnosticoAlCatalogo $alta)
    {
    }

    /**
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @return array{0: Diagnostico, 1: bool} el diagnóstico y si se creó ahora (`false` = ya existía uno igual)
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
            $igual = Diagnostico::whereIn('reg_medico', array_values(array_unique(array_merge($registrosMedicos, [(string) $medico->regMedicoPrincipal()]))))
                ->get()
                ->first(fn (Diagnostico $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion))) === $clave);

            if ($igual !== null) {
                return [$igual, false];
            }

            [$diagnostico, $creado] = $this->alta->ejecutar($medico, $registrosMedicos, $descripcion);

            if ($creado && $origen !== null) {
                RegistroDeCambios::registrarOperacion('diagnosticos', (int) $diagnostico->id, $diagnostico->reg_medico, 'created', null, null, $cuando, $origen);
            }

            return [$diagnostico, $creado];
        });
    }
}
