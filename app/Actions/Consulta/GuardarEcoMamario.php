<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoMamario;
use App\Models\UltraMama as UltraMamaModelo;

/**
 * **Guardar el eco mamario** (WEB-6.4, `ultra_mama`): la regla la lleva `GuardarEcoObstetricoBase` y acá está
 * sólo lo que este estudio tiene de propio —su tabla, su modelo, su lectura (`EcoMamario`) y su mapa—, más la
 * lista de columnas de su ficha que no cuentan como hallazgo: **sólo la fecha** (el mamario no tiene FUR ni
 * gesta).
 *
 * Es la subclase fina del mismo camino que `GuardarEcoDoppler` y `GuardarEcoCardiogramaFetal` (WEB-6.3): una
 * fila por consulta, sólo lo que cambió, rastro `source = web` y un estudio sin datos que no se guarda.
 *
 * ⚠️ **`para` y `puro_texto` no están** en `columnasDelEstudio()`: el primero va oculto en el DataWindow y el
 * segundo es el informe en texto libre del escritorio, que se muestra y no se edita. La Action no los toca.
 */
final class GuardarEcoMamario extends GuardarEcoObstetricoBase
{
    /**
     * La ficha del mamario es la más corta de los ecos: sólo la fecha. Se redefine la constante del padre y el
     * padre la lee con `static::` (su comentario lo explica).
     *
     * @var array<int,string>
     */
    protected const NO_CUENTAN = EcoMamario::COLUMNAS_QUE_NO_CUENTAN;

    public function __construct(private EcoMamario $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoMamario::TABLA;
    }

    protected function modelo(): string
    {
        return UltraMamaModelo::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoMamario::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
