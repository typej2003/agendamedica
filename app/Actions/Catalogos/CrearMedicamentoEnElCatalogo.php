<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Actions\Consulta\EscribeConRastro;
use App\Models\Vademecum;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un medicamento en el vademécum desde el ABM del catálogo** (WEB-8.1a, el *Nuevo* de
 * `w_agregar_vademecum`).
 *
 * La regla del alta **no se escribe otra vez**: el código correlativo de cuatro dígitos, las mayúsculas, los topes
 * y el "si ya hay uno con el mismo nombre comercial **y** la misma presentación, no se duplica" son los de
 * `AgregarMedicamentoAlVademecum` (WEB-5.1), la misma Action que usa el alta rápida de la pestaña de récipes. Lo que
 * suma el ABM son los campos que el alta rápida no pide (las otras marcas, *Psicotrópico* y la dosificación,
 * `CamposDelMedicamento`) y el **rastro**: un `created` en `sync_changes` con `source = web`.
 *
 * `vademecum` tiene clave primaria en el PowerBuilder (`codemedicina`), así que el escritorio la sube **por fila** y
 * una fila creada acá no corre el riesgo de que la subida la borre: no necesita protección en
 * `sin_clave_con_altas_del_app` (ver la trampa 56 de `TRAMPAS.md`). El móvil la recibe por el delta de `updated_at`.
 */
final class CrearMedicamentoEnElCatalogo
{
    use EscribeConRastro;

    public function __construct(private AgregarMedicamentoAlVademecum $alta)
    {
    }

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila: el del contexto
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @param  array<string,mixed>  $datos  los campos del formulario (`CamposDelMedicamento::COLUMNAS`)
     * @return array{0: Vademecum, 1: bool} el medicamento y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, algo pasa del largo de su columna o el vademécum está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): array {
        $campos = CamposDelMedicamento::normalizar($datos, true);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $registrosMedicos, $campos, $cuando, $origen) {
            [$medicamento, $creado] = $this->alta->ejecutar(
                $regMedico,
                $registrosMedicos,
                (string) $campos['nombrecomercial'],
                $campos['nombregenerico'],
                $campos['presentacion'],
                $campos['uso'],
                array_diff_key($campos, array_flip(['nombrecomercial', 'nombregenerico', 'presentacion', 'uso'])),
            );

            if ($creado && $origen !== null) {
                RegistroDeCambios::registrarOperacion('vademecum', (int) $medicamento->id, $regMedico, 'created', null, null, $cuando, $origen);
            }

            return [$medicamento, $creado];
        });
    }
}
