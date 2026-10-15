<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Models\EcoCardiogramaFetal as EcoCardiogramaFetalModelo;

/**
 * **Guardar el ecocardiograma fetal** (WEB-6.3, `ecocadiograma_fetal`): la regla la lleva
 * `GuardarEcoObstetricoBase` y acá está sólo lo que este estudio tiene de propio —su tabla, su modelo, su
 * lectura (`EcoCardiogramaFetal`) y su mapa—, más la lista de columnas de su ficha que no cuentan como
 * hallazgo: la fecha, la FUR, la gesta **y la paridad** (`parto`, `aborto`, `cesarea` y `otros`).
 *
 * Es la subclase fina del mismo camino que `GuardarEcoDoppler` (WEB-6.3) y los dos `GuardarEcoObstetrico*`
 * (WEB-6.2): una fila por consulta, sólo lo que cambió, rastro `source = web` y un estudio sin datos que no se
 * guarda.
 *
 * ⚠️ **`eg` y `referido`** existen en la tabla y van ocultos en el DataWindow: ginecología no los edita ni los
 * imprime, así que **no** están en `columnasDelEstudio()` y esta Action no los toca.
 */
final class GuardarEcoCardiogramaFetal extends GuardarEcoObstetricoBase
{
    /**
     * La ficha del ecocardiograma tiene más columnas que la de los obstétricos (la paridad entera). Se redefine
     * la constante del padre y el padre la lee con `static::` (su comentario lo explica).
     *
     * @var array<int,string>
     */
    protected const NO_CUENTAN = EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN;

    public function __construct(private EcoCardiogramaFetal $estudio)
    {
    }

    protected function tabla(): string
    {
        return EcoCardiogramaFetal::TABLA;
    }

    protected function modelo(): string
    {
        return EcoCardiogramaFetalModelo::class;
    }

    protected function columnasDelEstudio(): array
    {
        return EcoCardiogramaFetal::columnas();
    }

    protected function lectura()
    {
        return $this->estudio;
    }
}
