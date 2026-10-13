<?php

namespace App\Actions\Pacientes;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Models\Medico;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un antecedente en el catálogo del médico** (`antecedentes`, WEB-4.4).
 *
 * Es lo que hace el botón *Agregar* de la pestaña de antecedentes del escritorio: abre `w_nuevo_antecedente`
 * y la ventana guarda con `dw_1.update()` sobre el DataWindow `d_a_antecedentes.srd`, que inserta
 * `codantecedente`, `descripcion` y `codtipo` en `antecedentes` (`w_nuevo_antecedente.srw:597` y `:723`).
 * El **código** no lo escribe el médico: lo calcula la ventana con el correlativo de cuatro dígitos
 * (`:124-137` — ordena por código, toma el mayor, le suma uno y lo rellena con ceros)—, que es la misma
 * cuenta de `AgregarMotivoAlCatalogo` para los motivos de consulta y la que hace `AntecedentesDelPaciente`.
 *
 * El **tipo** (`codtipo`) sí lo elige el médico, y es obligatorio: de él sale la solapa en la que el
 * antecedente aparece y el grupo con el que se agrupa la historia. Un `codtipo` que no esté en
 * `tipo_antecedente` se rechaza: sin tipo, el antecedente quedaría en "sin clasificar" para siempre.
 *
 * **Online-only**, como el alta de motivos (PLAN-WEB.md §3.15): el código lo da el servidor y el alta es
 * poco frecuente. Agregar el antecedente **al paciente** es otra cosa y va en el mismo formulario que el
 * resto de la sección (`GuardarAntecedentes`).
 */
final class AgregarAntecedenteAlCatalogo
{
    /** El largo del `descripcion` del catálogo del legado (`antecedentes.descripcion` es `varchar(40)`). */
    public const LARGO_MAXIMO = 40;

    public function __construct(private AntecedentesDelPaciente $lectura)
    {
    }

    /**
     * @param  Medico  $medico  el médico **de la cuenta** que está creando el antecedente (de ahí sale el
     *                          `reg_medico` con el que se guarda la fila)
     * @return array{0:object, 1:bool} el antecedente del catálogo y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si la descripción está vacía o es muy larga, si el tipo no existe o
     *                                  si el catálogo está lleno
     */
    public function ejecutar(Medico $medico, string $descripcion, string $tipo): array
    {
        $regMedico = (string) $medico->regMedicoPrincipal();

        $descripcion = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $descripcion)));
        $tipo = trim($tipo);

        if ($descripcion === '') {
            throw new InvalidArgumentException('El antecedente no tiene descripción.');
        }
        if (mb_strlen($descripcion) > self::LARGO_MAXIMO) {
            throw new InvalidArgumentException('La descripción del antecedente no puede pasar de ' . self::LARGO_MAXIMO . ' caracteres.');
        }

        if ($this->lectura->tipoDe($regMedico, $tipo) === null) {
            throw new InvalidArgumentException('Elige un tipo de antecedente de tu catálogo.');
        }

        // Ya existe uno con ese nombre: se devuelve el existente en vez de duplicarlo (el catálogo del
        // legado trae repetidos y no queremos agregar uno más). El código más bajo es el que se ofrece,
        // igual que en el buscador.
        $existente = $this->lectura->catalogo($regMedico, $descripcion)
            ->first(fn ($fila) => mb_strtoupper(trim($fila->nombre)) === $descripcion);

        if ($existente !== null) {
            return [$existente, false];
        }

        $codigo = $this->lectura->siguienteCodigo($regMedico);

        if ($codigo === null) {
            throw new InvalidArgumentException('El catálogo de antecedentes está lleno.');
        }

        DB::table('antecedentes')->insert([
            'reg_medico' => $regMedico,
            'codantecedente' => $codigo,
            'descripcion' => $descripcion,
            'codtipo' => $tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$this->lectura->delCatalogo($regMedico, $codigo), true];
    }
}
