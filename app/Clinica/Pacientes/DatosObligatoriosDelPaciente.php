<?php

namespace App\Clinica\Pacientes;

/**
 * **Los datos que el médico tiene que completar para cerrar la consulta** (WEB-11.25, decisión de Alexander, 2026-10-15): al atender
 * se crea la historia (aunque llegue casi vacía) y se **obliga al médico a llenar la filiación** antes de terminar.
 *
 *  - **Por defecto** (ginecología y el resto): nombre y apellido, **fecha de nacimiento** y **teléfono** (y la cédula, que se pide al
 *    editar la ficha; ver abajo).
 *  - **Pediatría**: no hay cédula del paciente y el **teléfono es el del representante**: se piden nombre, fecha de nacimiento y el
 *    teléfono del representante.
 *
 * ⚠️ **La cédula no se puede exigir acá**: «Sin cédula» (S.C.) se guarda **nula** (como el legado, wiki `04-historia-clinica.md`), así que
 * una cédula vacía es indistinguible de «no tiene». El formulario de la ficha ya la pide salvo que se marque «Sin cédula».
 * La lista depende de la especialidad, así que sale del `slug` de la especialidad del contexto. Clase pura: se prueba sin base de datos.
 */
final class DatosObligatoriosDelPaciente
{
    /**
     * Lo que falta, con el rótulo que lee el médico.
     *
     * @param  object  $paciente  con `nombres`, `apellidos`, `cedula`, `fnacimiento`, `telefono`
     * @param  string|null  $specialtySlug  el `slug` de la especialidad del contexto (`pediatria`, `ginecologia-y-obstetricia`…)
     * @param  string|null  $telefonoDelRepresentante  el teléfono del representante de la historia, si lo hay
     * @return array<int,string>
     */
    public static function faltantes(object $paciente, ?string $specialtySlug, ?string $telefonoDelRepresentante = null): array
    {
        $pediatria = $specialtySlug !== null && str_contains($specialtySlug, 'pediatr');
        $faltan = [];

        if (self::vacio($paciente->nombres ?? null) || self::vacio($paciente->apellidos ?? null)) {
            $faltan[] = 'nombre y apellido';
        }

        if (self::vacio($paciente->fnacimiento ?? null)) {
            $faltan[] = 'fecha de nacimiento';
        }

        if ($pediatria) {
            if (self::vacio($telefonoDelRepresentante)) {
                $faltan[] = 'teléfono del representante';
            }
        } elseif (self::vacio($paciente->telefono ?? null)) {
            $faltan[] = 'teléfono';
        }

        return $faltan;
    }

    private static function vacio($valor): bool
    {
        return $valor === null || trim((string) $valor) === '' || strcasecmp(trim((string) $valor), 'none') === 0;
    }
}
