<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoObstetrico;

/**
 * **Guardar el eco obstétrico del primer trimestre** (WEB-6.2, `eco_obstetrico`): la regla la lleva
 * `GuardarEcoObstetricoBase` y acá está sólo lo que este estudio tiene de propio —su lectura y su mapa—.
 */
final class GuardarEcoObstetrico extends GuardarEcoObstetricoBase
{
    public function __construct(private EcoObstetrico $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoObstetrico::TABLA;
    }

    protected function modelo(): string
    {
        return \App\Models\EcoObstetrico::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoObstetrico::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
