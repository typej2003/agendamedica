<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Vademecum;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **El vademécum del médico para el ABM** (WEB-8.1a): sus medicamentos con buscador sin tildes, ordenados por
 * nombre y paginados (el vademécum de la base de prueba trae ~870 por médico).
 *
 * Es **la misma lista** que ofrece el buscador de la consulta —`vademecum`, no `vademecum_m` (trampa 39)— pero con
 * una diferencia a propósito: el buscador de la consulta junta los repetidos en uno solo (para elegir) y acá se
 * ven **todos**, repetidos incluidos, porque el médico tiene que poder encontrarlos para editarlos o quitarlos.
 * Lo que sí es una sola definición es **cómo se busca** (`RecipesDeLaConsulta::medicamentoCoincide`): lo que se
 * encuentra en la consulta se encuentra acá.
 *
 * Sólo lectura. Todo está acotado al `reg_medico` del contexto: un medicamento de otro médico no existe para la
 * pantalla (`encontrar` responde 404 para quien lo llama).
 */
final class MedicamentosDelCatalogo
{
    public const POR_PAGINA = 25;

    /**
     * Una página del vademécum que coincide con lo que se escribió (todo, si no se escribió nada).
     *
     * @param  string  $ruta  la URL de la pantalla, para los enlaces del paginador
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda)
     */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        $filas = $this->coincidentes($regMedico, $buscar);

        $pagina = max(1, $pagina);

        return new LengthAwarePaginator(
            $filas->forPage($pagina, self::POR_PAGINA)->values(),
            $filas->count(),
            self::POR_PAGINA,
            $pagina,
            ['path' => $ruta, 'query' => $query],
        );
    }

    /** Cuántos medicamentos tiene el vademécum del médico (sin búsqueda): decide el estado vacío. */
    public function total(string $regMedico): int
    {
        return Vademecum::where('reg_medico', $regMedico)->count();
    }

    /** Un medicamento del médico del contexto; el de otro médico no se toca (404 para quien llama). */
    public function encontrar(string $regMedico, int $id): Vademecum
    {
        return Vademecum::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Los medicamentos que coinciden, ordenados por nombre (el comercial o, si falta, el genérico), después por
     * presentación y por código. Los que no tienen ningún nombre van al final: el médico los encuentra para quitarlos.
     *
     * @return Collection<int,Vademecum>
     */
    private function coincidentes(string $regMedico, string $buscar): Collection
    {
        $palabras = RecipesDeLaConsulta::palabrasDeBusqueda($buscar);

        return Vademecum::where('reg_medico', $regMedico)
            ->get()
            ->filter(fn (Vademecum $fila) => RecipesDeLaConsulta::medicamentoCoincide($fila, $palabras))
            ->sortBy(fn (Vademecum $fila) => $this->claveDeOrden($fila))
            ->values();
    }

    private function claveDeOrden(Vademecum $fila): string
    {
        $nombre = CamposDePaciente::texto($fila->nombrecomercial) ?? CamposDePaciente::texto($fila->nombregenerico);

        return ($nombre === null ? '1' : '0')
            . BusquedaDePacientes::normalizar((string) $nombre)
            . '|' . BusquedaDePacientes::normalizar((string) $fila->presentacion)
            . '|' . str_pad(trim((string) $fila->codemedicina), 8, '0', STR_PAD_LEFT);
    }
}
