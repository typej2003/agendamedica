<?php

namespace App\Clinica\Catalogos;

use App\Actions\Catalogos\ReglasDelMotivoDeCita;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\MotivoCita;
use App\Models\Office;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los motivos de cita del médico para el ABM** (WEB-8.1c): `motivo_cita` con su sede, el buscador sin tildes (código,
 * razón o sede) y el paginado de 25.
 *
 * Se ven **todas** las filas del médico, las repetidas por código incluidas (un mismo código puede estar una vez por sede y
 * otra sin sede: así lo trae la sincronización y es lo que permite un precio por sede) y las duplicadas dentro de una sede
 * (`repetidos`: cuántas filas tienen la misma razón en la misma sede; `con_el_mismo_codigo`: en cuántas filas está el código),
 * porque el ABM es donde se limpian.
 *
 * Sólo lectura. Todo está acotado al `reg_medico` del contexto (`encontrar` responde 404 para quien lo llama). La lectura que
 * arma el `<select>` del formulario de la cita y el filtro de la agenda es otra, `App\Clinica\Agenda\MotivosDeCita`.
 */
final class MotivosDeCitaDelCatalogo
{
    use PaginaUnaColeccion;

    /**
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda)
     */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return MotivoCita::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): MotivoCita
    {
        return MotivoCita::with('office.medicalCenter')->where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Las sedes entre las que se elige en el formulario: `id => nombre`, las del médico. Es la misma lista (y el mismo
     * texto) que el paso 1 de «Nueva cita».
     *
     * @return array<int,string>
     */
    public function sedes(string $regMedico, ?int $medicoId): array
    {
        $sedes = [];

        foreach (Office::with('medicalCenter')->delMedico($regMedico, $medicoId)->orderBy('id')->get() as $sede) {
            $sedes[(int) $sede->id] = self::nombreDeLaSede($sede);
        }

        return $sedes;
    }

    /** «San José — C1»: el centro y el consultorio, como se nombra la sede en el formulario de la cita. */
    public static function nombreDeLaSede(?Office $sede): string
    {
        if ($sede === null) {
            return 'Todas las sedes';
        }

        return ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' — ' . $sede->office_number : '');
    }

    /** La sede de una fila: «Todas las sedes» si no tiene, y si apunta a una que ya no existe, se dice en vez de aparentar «todas». */
    public static function sedeDelMotivo(MotivoCita $fila): string
    {
        if ($fila->office_id === null) {
            return 'Todas las sedes';
        }

        return $fila->office === null ? 'Sede ' . $fila->office_id . ' (ya no existe)' : self::nombreDeLaSede($fila->office);
    }

    /** @return Collection<int,MotivoCita> */
    private function coincidentes(string $regMedico, string $buscar): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $razon = fn (MotivoCita $fila) => ReglasDelMotivoDeCita::clave($fila->tipo_atencion);

        $todos = MotivoCita::with('office.medicalCenter')->where('reg_medico', $regMedico)->get();
        $repetidos = $todos->countBy(fn (MotivoCita $fila) => $razon($fila) . '|' . ($fila->office_id ?? ''));
        $conElCodigo = $todos->countBy(fn (MotivoCita $fila) => trim((string) $fila->codigo));

        return $todos
            ->filter(function (MotivoCita $fila) use ($palabras, $razon) {
                $pajar = $razon($fila) . ' ' . BusquedaDePacientes::normalizar(trim((string) $fila->codigo) . ' ' . self::sedeDelMotivo($fila));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            // Por código (los numéricos como número: 2 antes que 10; los demás por texto, con el código de 10 posiciones completado
            // con espacios para que `ECO` quede antes que `ECO4D`) y, a igual código, sin sede primero y luego por sede.
            ->sortBy(fn (MotivoCita $fila) => (ctype_digit(trim((string) $fila->codigo)) ? '0' . str_pad(trim((string) $fila->codigo), 10, '0', STR_PAD_LEFT) : '1' . str_pad(mb_strtolower(trim((string) $fila->codigo)), 10, ' '))
                . '|' . str_pad((string) ($fila->office_id ?? 0), 10, '0', STR_PAD_LEFT) . '|' . str_pad((string) $fila->id, 10, '0', STR_PAD_LEFT))
            ->values()
            ->each(function (MotivoCita $fila) use ($repetidos, $conElCodigo, $razon) {
                $fila->setAttribute('repetidos', (int) ($repetidos[$razon($fila) . '|' . ($fila->office_id ?? '')] ?? 1));
                $fila->setAttribute('con_el_mismo_codigo', (int) ($conElCodigo[trim((string) $fila->codigo)] ?? 1));
            });
    }
}
