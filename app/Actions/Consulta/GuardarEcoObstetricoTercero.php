<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoObstetricoTercero;

/**
 * **Guardar el eco obstétrico del segundo/tercer trimestre** (WEB-6.2, `eco_obstetrico_tercer`): la regla la
 * lleva `GuardarEcoObstetricoBase` y acá está sólo lo propio de este estudio.
 *
 * ⚠️ Recordá que el 2.º y el 3.er trimestre comparten tabla y DataWindow de pantalla: guardar este estudio
 * sobre una consulta que ya lo tenía **lo corrige**, no crea otro (una fila por consulta).
 */
final class GuardarEcoObstetricoTercero extends GuardarEcoObstetricoBase
{
    public function __construct(private EcoObstetricoTercero $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoObstetricoTercero::TABLA;
    }

    protected function modelo(): string
    {
        return \App\Models\EcoObstetricoTercer::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoObstetricoTercero::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
