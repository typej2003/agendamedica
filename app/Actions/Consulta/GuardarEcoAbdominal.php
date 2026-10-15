<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoAbdominal;
use App\Models\UltraAbdominal as UltraAbdominalModelo;

/**
 * **Guardar el eco abdominal** (WEB-6.4, `ultra_abdominal`): la regla la lleva `GuardarEcoObstetricoBase` y
 * acá está sólo lo que este estudio tiene de propio —su tabla, su modelo, su lectura (`EcoAbdominal`) y su
 * mapa—, más la lista de columnas de su ficha que no cuentan como hallazgo: **sólo la fecha** (el abdominal
 * no tiene FUR ni gesta).
 *
 * Es la subclase fina del mismo camino que `GuardarEcoMamario` (WEB-6.4): una fila por consulta, sólo lo que
 * cambió, rastro `source = web` y un estudio sin datos que no se guarda.
 *
 * ⚠️ **`prostata` y `vejiga_urina` no están** en `columnasDelEstudio()`: son de urología y en ginecología van
 * ocultas en el escritorio. La Action **no las toca** —ni al crear ni al corregir—, así que una fila que ya
 * las tuviera cargadas las conserva. `puro_texto` tampoco está: es el informe en texto libre del escritorio,
 * que se muestra y no se edita.
 */
final class GuardarEcoAbdominal extends GuardarEcoObstetricoBase
{
    /**
     * La ficha del abdominal es tan corta como la del mamario: sólo la fecha. Se redefine la constante del
     * padre y el padre la lee con `static::` (su comentario lo explica).
     *
     * @var array<int,string>
     */
    protected const NO_CUENTAN = EcoAbdominal::COLUMNAS_QUE_NO_CUENTAN;

    public function __construct(private EcoAbdominal $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoAbdominal::TABLA;
    }

    protected function modelo(): string
    {
        return UltraAbdominalModelo::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoAbdominal::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
