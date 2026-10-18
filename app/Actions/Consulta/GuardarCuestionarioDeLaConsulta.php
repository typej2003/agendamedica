<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ExamenPediatrico;
use App\Models\Cuestionario;

/** **Guardar el cuestionario por aparatos** (WEB-12.2): las once columnas de `cuestionario`. La regla es la de `GuardarTextosPediatricos`. */
final class GuardarCuestionarioDeLaConsulta extends GuardarTextosPediatricos
{
    protected function modelo(): string
    {
        return Cuestionario::class;
    }

    protected function tabla(): string
    {
        return 'cuestionario';
    }

    protected function columnas(): array
    {
        return array_keys(ExamenPediatrico::CUESTIONARIO);
    }
}
