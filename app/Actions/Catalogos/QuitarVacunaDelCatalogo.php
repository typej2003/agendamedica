<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\Vacuna;
use App\Models\VacunaDosis;
use App\Models\VacunaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Quitar una vacuna del catálogo del médico** (WEB-7.4.4e) junto con sus dosis. **No se quita si ya se le aplicó a algún niño**
 * (`vacuna_paciente` con `ejecutada = 'S'`): el calendario y el carnet de esos niños la nombran por su código. Sólo toca filas del médico: las del
 * catálogo inicial (sin dueño) y las de otro consultorio no se encuentran.
 */
final class QuitarVacunaDelCatalogo
{
    use EscribeConRastro;

    /**
     * @return string  el nombre de la vacuna quitada
     *
     * @throws InvalidArgumentException si no es del médico o ya está aplicada a algún niño
     */
    public function ejecutar(string $regMedico, int $id, ?CarbonInterface $cuando = null, ?string $origen = 'web'): string
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $id, $cuando, $origen) {
            $fila = Vacuna::where('reg_medico', $regMedico)->where('id', $id)->lockForUpdate()->first();

            if ($fila === null) {
                throw new InvalidArgumentException('Esa vacuna no está en tu catálogo.');
            }

            $nombre = trim((string) $fila->vacuna) ?: 'vacuna ' . trim((string) $fila->codi_vacuna);

            $aplicadas = VacunaPaciente::where('reg_medico', $regMedico)->where('codi_vacuna', $fila->codi_vacuna)->whereRaw("upper(ejecutada) = 'S'")->count();
            if ($aplicadas > 0) {
                throw new InvalidArgumentException('No se quitó «' . $nombre . '» porque ya se aplicó ' . $aplicadas . ($aplicadas === 1 ? ' vez' : ' veces')
                    . ': el calendario y el carnet de esos niños la usan. Déjala en el catálogo.');
            }

            foreach (VacunaDosis::where('reg_medico', $regMedico)->where('codi_vacuna', $fila->codi_vacuna)->get() as $dosis) {
                $this->borrarFila($dosis, 'vacunas_dosis', $cuando, $origen);
            }
            $this->borrarFila($fila, 'vacunas', $cuando, $origen);

            return $nombre;
        });
    }
}
