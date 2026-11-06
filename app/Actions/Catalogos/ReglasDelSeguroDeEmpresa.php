<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\SegEmp;
use InvalidArgumentException;

/**
 * **Las reglas del catálogo de seguros / empresas del consultorio** (`seg_emp`, O-62), escritas una vez para el alta
 * y la edición.
 *
 * ## Qué se guarda y con qué ancho
 *
 * El ancho es el de las columnas del legado, que son las del DataWindow `d_a_seguros.srd` (la ventana
 * `w_agregar_seguro_empresa.srw`) y las de la migración que creó la tabla:
 *
 * | columna | ancho | ¿obligatoria? |
 * |---|---|---|
 * | `nombre` | 150 | **sí** (decisión del usuario, O-62) |
 * | `rif` | 50 | no |
 * | `direccion` | 350 | no |
 * | `telef` | 50 | no |
 *
 * El nombre va **en mayúsculas** y con los espacios colapsados, que es la regla compartida de los catálogos
 * (`ReglasDelCatalogo::descripcion`): el legado ya tiene `PERSONAL`, `CADELA REGION LOS ANDES` y `DESURCA CADAFE`
 * así. El RIF también se guarda en mayúsculas (se escribe con letra: `J-12345678-9`); la dirección y el teléfono se
 * respetan como los escribió el médico.
 *
 * ## El código
 *
 * Lo da el servidor con la regla del catálogo (el mayor numérico + 1, con ceros a la izquierda), pero **con dos
 * dígitos**, que es el ancho con el que el escritorio numera: `w_agregar_seguro_empresa.srw:129` y `:152` hacen
 * `right("00"+trim(codigo),2)` (la versión de tres dígitos está comentada en `:150`). La columna entra tres, así que
 * el tope real del legado son **99 seguros por consultorio**.
 *
 * `00` es **PERSONAL** (`CatalogoDeSeguros::PERSONAL`), el default del alta del paciente y la fila que siembra
 * `2026_11_05_150000_sembrar_el_seguro_personal_en_los_consultorios`; el correlativo arranca en 1, así que **nunca
 * devuelve `00`**.
 *
 * ## Sin duplicados
 *
 * El mismo nombre (sin tildes ni mayúsculas) dos veces en el catálogo del mismo consultorio no se permite: el
 * `<select>` del alta del paciente se llenaría de repetidos. La comparación es contra **ese** consultorio: cada
 * `reg_medico` tiene su propio catálogo.
 *
 * ## El sync del escritorio
 *
 * `seg_emp` tiene disparadores **de fila** en el escritorio (`tr_ddrsync_seg_emp_ins/_upd/_del`, en
 * `Docs/instalaciones/Ddrpedia - fuente/bridge/registro.sql`): se sube fila por fila con `codesegemp` de clave, no
 * como reemplazo de la tabla entera. Por eso una fila que crea la web sobrevive a la siguiente subida del
 * escritorio, y por eso el correlativo es por `reg_medico`, que es como numera el escritorio en su base local.
 * Sólo chocaría un código que el escritorio y la web den a la vez, igual que en `doctores`.
 */
final class ReglasDelSeguroDeEmpresa
{
    public const LARGO_NOMBRE = 150;
    public const LARGO_RIF = 50;
    public const LARGO_DIRECCION = 350;
    public const LARGO_TELEFONO = 50;

    /** Los dígitos del correlativo: dos, como el `right("00"+trim(codigo),2)` del escritorio. */
    public const DIGITOS_DEL_CODIGO = 2;

    /** Cómo se llama cada columna en los avisos ("cambió el RIF y el teléfono"). */
    public const ETIQUETAS = [
        'nombre' => 'el nombre',
        'rif' => 'el RIF',
        'direccion' => 'la dirección',
        'telef' => 'el teléfono',
    ];

    /**
     * Los cuatro campos de la fila, normalizados.
     *
     * @param  array<string,mixed>  $datos  tal como llegan del formulario
     * @return array{nombre:string, rif:?string, direccion:?string, telef:?string}
     *
     * @throws InvalidArgumentException si falta el nombre o algo pasa del ancho de su columna
     */
    public static function campos(array $datos): array
    {
        return [
            'nombre' => self::nombre($datos['nombre'] ?? null),
            'rif' => AgregarMedicamentoAlVademecum::opcional($datos['rif'] ?? null, self::LARGO_RIF, 'El RIF', true),
            // La dirección conserva sus renglones (es un `<textarea>`), como la de un médico referido.
            'direccion' => AgregarMedicamentoAlVademecum::opcional($datos['direccion'] ?? null, self::LARGO_DIRECCION, 'La dirección', false, false),
            'telef' => AgregarMedicamentoAlVademecum::opcional($datos['telef'] ?? null, self::LARGO_TELEFONO, 'El teléfono', false),
        ];
    }

    /**
     * El nombre como se guarda: mayúsculas, espacios colapsados y sin sobrantes.
     *
     * @throws InvalidArgumentException si queda vacío o pasa de 150
     */
    public static function nombre(?string $texto): string
    {
        try {
            return ReglasDelCatalogo::descripcion((string) $texto, self::LARGO_NOMBRE, 'seguro');
        } catch (InvalidArgumentException $e) {
            // La regla compartida habla de «descripción»; en este catálogo el campo se llama «nombre».
            throw new InvalidArgumentException(str_replace(
                ['El seguro no tiene descripción.', 'La descripción del seguro'],
                ['Escribe el nombre del seguro o la empresa.', 'El nombre del seguro'],
                $e->getMessage()
            ));
        }
    }

    /** El nombre comparable: sin tildes, sin mayúsculas, con los espacios colapsados. */
    public static function clave(?string $texto): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));
    }

    /** El código que le toca al seguro nuevo: el mayor numérico del consultorio + 1, en dos dígitos. */
    public static function siguienteCodigo(iterable $codigos): string
    {
        return ReglasDelCatalogo::siguienteCodigo($codigos, 'seguros y empresas', self::DIGITOS_DEL_CODIGO);
    }

    /**
     * @throws InvalidArgumentException si el consultorio ya tiene otro seguro con ese nombre
     */
    public static function noDuplicar(string $regMedico, string $nombre, ?int $excepto = null): void
    {
        $clave = self::clave($nombre);

        $otro = SegEmp::where('reg_medico', $regMedico)
            ->when($excepto !== null, fn ($consulta) => $consulta->where('id', '<>', $excepto))
            ->get()
            ->first(fn (SegEmp $fila) => self::clave($fila->nombre) === $clave);

        if ($otro !== null) {
            throw new InvalidArgumentException('Ya tienes «' . trim((string) $otro->nombre) . '» en el catálogo (código '
                . trim((string) $otro->codesegemp) . '). Edítalo desde la lista o cambia el nombre.');
        }
    }
}
