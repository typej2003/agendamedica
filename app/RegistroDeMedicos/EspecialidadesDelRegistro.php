<?php

namespace App\RegistroDeMedicos;

use App\Especialidades\ManifiestoEspecialidad;
use App\Especialidades\RegistroEspecialidades;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * **Las especialidades que se ofrecen en el registro público de médicos** (`/registro/medico`).
 *
 * Son las que AppDDR **ya atiende**: las que tienen manifiesto en `config/especialidades/` (hoy Ginecología
 * y Obstetricia, y Pediatría). El resto del catálogo `specialties` existe —y se sigue usando para las fichas
 * del escritorio, el panel y la reserva pública— pero **todavía no se ofrece al registrarse**: se van
 * **activando** al sumar el manifiesto de cada especialidad (decisión del usuario, 2026-10-24).
 *
 * La misma lista decide el desplegable del formulario y la validación del servidor, así que una especialidad
 * que no esté acá se rechaza aunque la manden a mano.
 */
final class EspecialidadesDelRegistro
{
    /**
     * La consulta base: el catálogo, sólo las activas y sólo las soportadas, por nombre.
     *
     * @return Builder<Specialty>
     */
    public static function consulta(): Builder
    {
        return Specialty::query()
            ->where('activo', true)
            ->whereIn('slug', self::slugs())
            ->orderBy('name');
    }

    /** Lo que se ofrece en el `<select>` del formulario. @return Collection<int, Specialty> */
    public static function lista(): Collection
    {
        return self::consulta()->get(['id', 'name']);
    }

    /** ¿Esa fila del catálogo se puede elegir al registrarse? */
    public static function admite(int $id): bool
    {
        return self::consulta()->whereKey($id)->exists();
    }

    /**
     * Los slugs del catálogo `specialties` que hoy están soportados: los que declaran los manifiestos.
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_values(array_map(
            fn (ManifiestoEspecialidad $manifiesto) => $manifiesto->specialtySlug,
            (new RegistroEspecialidades())->todos()
        ));
    }
}
