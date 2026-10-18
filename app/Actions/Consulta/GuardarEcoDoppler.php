<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoDoppler;
use App\Models\EcoDoppler as EcoDopplerModelo;

/**
 * **Guardar el eco Doppler perinatal** (WEB-6.3, `eco_doppler`): la regla la lleva `GuardarEcoObstetricoBase`
 * y acá está sólo lo que este estudio tiene de propio —su tabla, su modelo, su lectura (`EcoDoppler`) y su
 * mapa—, más la lista de columnas de su ficha que no cuentan como hallazgo: la fecha, la FUR, la gesta **y el
 * `para`**.
 *
 * Es la subclase fina del mismo camino que `GuardarEcoObstetrico` (WEB-6.2): una fila por consulta, sólo lo
 * que cambió, rastro `source = web` y un estudio sin datos que no se guarda.
 */
final class GuardarEcoDoppler extends GuardarEcoObstetricoBase
{
    /**
     * La ficha del Doppler tiene una columna más que la de los obstétricos: el `para`. Se redefine la
     * constante del padre y el padre la lee con `static::` (su comentario lo explica).
     *
     * @var array<int,string>
     */
    protected const NO_CUENTAN = EcoDoppler::COLUMNAS_QUE_NO_CUENTAN;

    public function __construct(private EcoDoppler $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoDoppler::TABLA;
    }

    protected function modelo(): string
    {
        return EcoDopplerModelo::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoDoppler::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
