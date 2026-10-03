<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\MotivoConsulta;
use InvalidArgumentException;

/**
 * Altas en el catálogo de motivos de consulta del médico (ROADMAP.md Paso 18.B2).
 *
 * El código es el correlativo de 4 dígitos del legado (último + 1) y la descripción va en
 * mayúsculas y sin espacios de más, como la captura el escritorio (límite de 40). Si ya existe un
 * motivo con esa descripción (sin distinguir mayúsculas) **no se crea otro**: se devuelve el
 * existente, para que dos usuarios del mismo consultorio que escriban el mismo motivo no dejen el
 * catálogo con repetidos.
 *
 * Límite conocido: dos altas *distintas* en el mismo instante podrían calcular el mismo código (la
 * tabla no tiene unicidad por `(reg_medico, codemotivo)` y el legado podría tener repetidos que
 * impidan agregarla). Crear motivos es poco frecuente, así que el riesgo es muy bajo.
 */
class CatalogoMotivosConsulta
{
    public const LARGO_MAXIMO = 40;

    /**
     * @param list<string> $registrosMedicos los registros del tenant del médico
     * @return array{0: MotivoConsulta, 1: bool} el motivo y si se creó ahora (false = ya existía)
     *
     * @throws InvalidArgumentException si la descripción está vacía, es muy larga o el catálogo está lleno
     */
    public function obtenerOCrear(Medico $medico, array $registrosMedicos, string $descripcion): array
    {
        $descripcion = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $descripcion)));

        if ($descripcion === '') {
            throw new InvalidArgumentException('El motivo no tiene descripción.');
        }
        if (mb_strlen($descripcion) > self::LARGO_MAXIMO) {
            throw new InvalidArgumentException('La descripción del motivo no puede pasar de ' . self::LARGO_MAXIMO . ' caracteres.');
        }

        $catalogo = MotivoConsulta::whereIn('reg_medico', $registrosMedicos)->get();

        $existente = $catalogo->first(fn (MotivoConsulta $m) => mb_strtoupper(trim($m->descripcion)) === $descripcion);
        if ($existente) {
            return [$existente, false];
        }

        $siguiente = ((int) $catalogo->pluck('codemotivo')
            ->filter(fn ($c) => is_numeric($c))
            ->map(fn ($c) => (int) $c)
            ->max()) + 1;
        if ($siguiente > 9999) {
            throw new InvalidArgumentException('El catálogo de motivos está lleno.');
        }

        $motivo = MotivoConsulta::create([
            'reg_medico' => $medico->regMedicoPrincipal(),
            'codemotivo' => str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT),
            'descripcion' => $descripcion,
        ]);

        return [$motivo, true];
    }
}
