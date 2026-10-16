<?php

namespace App\Clinica\Consulta;

use App\Models\EcoObstetricoSegundo as ModeloDelSegundo;

/**
 * **El eco obstétrico del 2.º trimestre** (WEB-11.14): el mismo mapa y la misma lectura que el del 3.er trimestre
 * (`EcoObstetricoTercero`), sobre la tabla hermana `eco_obstetrico_tercer_2`. Ver `App\Models\EcoObstetricoSegundo`.
 */
final class EcoObstetricoSegundo extends EcoObstetricoTercero
{
    public const TABLA_DEL_SEGUNDO = 'eco_obstetrico_tercer_2';

    protected function tabla(): string
    {
        return self::TABLA_DEL_SEGUNDO;
    }

    protected function modelo(): string
    {
        return ModeloDelSegundo::class;
    }
}
