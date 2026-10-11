<?php

namespace App\Actions\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Vademecum;
use InvalidArgumentException;

/**
 * **Alta de un medicamento en el vademécum del médico** (`vademecum`, WEB-5.1, el botón *Agregar* de la
 * pestaña de récipes del escritorio).
 *
 * Es lo que hace `w_nuevo_vademecum` (abierta desde `w_consulta_principal.srw:13239`): guarda con
 * `dw_1.update()` sobre `d_a_vademecum_go.srd`, que actualiza **`vademecum`** —no `vademecum_m`— con el
 * principio activo (`nombregenerico`, `edit.limit=35`, mayúsculas), el nombre comercial (`nombrecomercial`,
 * `edit.limit=35`, mayúsculas), la presentación (`edit.limit=50`, como se escriba) y las indicaciones
 * (`uso`). Esas son las cuatro cosas que la web pide; los demás campos del DataWindow (dosis, concentración,
 * precios, marcas alternas) quedan en `NULL` y son del ABM del vademécum (WEB-8.1).
 *
 * **El código lo da el servidor** (PLAN-WEB.md §3.15), igual que en los catálogos de motivos y de
 * diagnósticos —y con las mismas reglas, en `ReglasDelCatalogo`—: es lo que la ventana del legado hace al
 * abrirse (`w_nuevo_vademecum.srw:118-144` y `:234-253`: ordena por código, toma el mayor, le suma uno y lo
 * deja en cuatro dígitos con `right("000"+trim(codigo),4)`). El código del legado es `char(8)`, pero en la
 * práctica son esos cuatro dígitos; los que no son numéricos (`MED001`) no cuentan para el correlativo.
 *
 * Como en los otros catálogos, el correlativo se mide sobre **todos los registros del tenant** y, si ya hay
 * un medicamento con el mismo nombre comercial **y** la misma presentación (`ADEVIT/jarabe` y `ADEVIT/gotas`
 * son medicamentos distintos), se devuelve **el existente** sin duplicarlo.
 *
 * **Online-only**, como los otros catálogos: crear un medicamento es poco frecuente y no viaja por la cola de
 * sync. El móvil lo recibe por el delta de `vademecum` (la fila nace con `updated_at`).
 */
final class AgregarMedicamentoAlVademecum
{
    /** `vademecum.nombrecomercial` y `nombregenerico` son `char(35)` (`d_a_vademecum_go.srd`). */
    public const LARGO_NOMBRE = 35;

    /** `vademecum.presentacion` es `char(50)`. */
    public const LARGO_PRESENTACION = 50;

    /** Las indicaciones (`uso`) son un `long varchar`; el formulario pone un tope razonable. */
    public const LARGO_USO = 2000;

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila: el del contexto, que es el
     *                             vademécum en el que busca la sección
     * @param  array<int,string>  $registrosMedicos  los registros del tenant, para el correlativo y para no
     *                                               duplicar uno que ya existe en cualquiera de ellos
     * @return array{0: Vademecum, 1: bool} el medicamento y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, algo pasa del largo de su columna o el vademécum está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        string $nombreComercial,
        ?string $nombreGenerico = null,
        ?string $presentacion = null,
        ?string $uso = null,
    ): array {
        $comercial = ReglasDelCatalogo::descripcion($nombreComercial, self::LARGO_NOMBRE, 'medicamento');
        $generico = $this->opcional($nombreGenerico, self::LARGO_NOMBRE, 'El principio activo', true);
        $presentacion = $this->opcional($presentacion, self::LARGO_PRESENTACION, 'La presentación', false);
        $uso = $this->opcional($uso, self::LARGO_USO, 'Las indicaciones', false, false);

        $vademecum = Vademecum::whereIn('reg_medico', array_values(array_unique(array_merge($registrosMedicos, [$regMedico]))))
            ->get();

        $clave = fn (?string $presentacionDeLaFila) => BusquedaDePacientes::normalizar(trim((string) $presentacionDeLaFila));

        $existente = $vademecum->first(
            fn (Vademecum $fila) => mb_strtoupper(trim((string) $fila->nombrecomercial)) === $comercial
                && $clave($fila->presentacion) === $clave($presentacion)
        );

        if ($existente) {
            return [$existente, false];
        }

        $medicamento = Vademecum::create([
            'reg_medico' => $regMedico,
            'codemedicina' => ReglasDelCatalogo::siguienteCodigo($vademecum->pluck('codemedicina'), 'medicamentos'),
            'nombrecomercial' => $comercial,
            'nombregenerico' => $generico,
            'presentacion' => $presentacion,
            'uso' => $uso,
        ]);

        return [$medicamento, true];
    }

    /**
     * Un campo opcional: vacío es `null`; con texto, se colapsan los espacios y se valida el largo. Las
     * indicaciones conservan sus saltos de línea (`$colapsar = false`).
     */
    private function opcional(?string $valor, int $largo, string $nombre, bool $mayusculas, bool $colapsar = true): ?string
    {
        $texto = $colapsar
            ? trim((string) preg_replace('/\s+/u', ' ', (string) $valor))
            : trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $largo) {
            throw new InvalidArgumentException($nombre . ' supera los ' . $largo . ' caracteres.');
        }

        return $mayusculas ? mb_strtoupper($texto) : $texto;
    }
}
