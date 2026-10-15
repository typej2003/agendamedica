<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo de **seguros / empresas aseguradoras** del consultorio: lo que llena el `<select>` de
 * "Seguro/E." del alta y de la edición de la ficha (WEB-3.2).
 *
 * La tabla del legado se llama `seg_emp` (singular, como la creó
 * `2026_09_27_110000_create_tablas_legado_faltantes`) y el modelo `App\Models\SegEmp` apunta a
 * `seg_emps`, que **no existe**: por eso la lectura se hace con el query builder sobre el nombre real de
 * la tabla y el catálogo no se sirve por la relación `Paciente::segEmp()`. Se deja como está lo demás (el
 * modelo no lo usa nadie más, ver `ROADMAP.md`).
 *
 * Es **por `reg_medico`**, como el catálogo de motivos: cada consultorio tiene el suyo (`MedicalDataSeeder`
 * le siembra el mismo juego a cada registro de prueba) y un paciente guarda el `codesegemp` de su
 * consultorio. La ficha muestra el nombre del seguro, no el código.
 */
final class CatalogoDeSeguros
{
    /**
     * El código de **PERSONAL**: el paciente que se atiende por su cuenta (el default del alta del
     * escritorio, `w_agregar_pacientes.srw:378`). No se muestra como si fuera una empresa.
     */
    public const PERSONAL = '00';

    /** @return Collection<int,object> filas con `codesegemp` y `nombre`, ordenadas por nombre */
    public function de(string $regMedico): Collection
    {
        return DB::table('seg_emp')
            ->where('reg_medico', $regMedico)
            ->whereNotNull('nombre')
            ->orderBy('nombre')
            ->get(['codesegemp', 'nombre']);
    }

    /**
     * El nombre del seguro de un código, o `null` si no está en el catálogo del consultorio. Un código
     * que no está (de otra instalación, o de un catálogo que todavía no bajó) se muestra tal cual en la
     * ficha: la web no inventa el nombre ni borra el dato.
     */
    public function nombre(string $regMedico, ?string $codesegemp): ?string
    {
        return $this->nombreDe($this->de($regMedico), $codesegemp);
    }

    /**
     * Lo mismo pero sobre un catálogo **ya leído**: la pantalla de la ficha y la del formulario piden el
     * catálogo una vez y resuelven el nombre sin volver a la base.
     *
     * @param  Collection<int,object>  $catalogo
     */
    public function nombreDe(Collection $catalogo, ?string $codesegemp): ?string
    {
        $codigo = trim((string) $codesegemp);

        if ($codigo === '') {
            return null;
        }

        $nombre = $catalogo->firstWhere('codesegemp', $codigo)?->nombre;

        return $nombre !== null ? trim((string) $nombre) : null;
    }

    /**
     * **El texto que muestra un listado** en su columna de empresa/procedencia, a partir del catálogo ya
     * leído: el nombre del catálogo si está, y **el código tal cual** si no —un catálogo que todavía no bajó
     * no puede hacer desaparecer el dato—. El código `00` (PERSONAL) y el vacío devuelven `null`, que la
     * pantalla pinta con `—`: es la misma regla que ya usan la ficha (`FichaDePacienteVista::seguro()`) y el
     * listado de embarazos, escrita una sola vez para los listados entre fechas.
     *
     * Es `static` porque los ViewModels ya tienen el catálogo en la mano y no necesitan la lectura por
     * `reg_medico` (`de()`); así los dos listados resuelven la procedencia sin volver a la base.
     *
     * @param  Collection<int,object>  $catalogo
     */
    public static function paraMostrar(Collection $catalogo, ?string $codesegemp): ?string
    {
        $codigo = trim((string) $codesegemp);

        if ($codigo === '' || $codigo === self::PERSONAL) {
            return null;
        }

        $nombre = trim((string) ($catalogo->firstWhere('codesegemp', $codigo)?->nombre ?? ''));

        return $nombre !== '' ? $nombre : $codigo;
    }
}
