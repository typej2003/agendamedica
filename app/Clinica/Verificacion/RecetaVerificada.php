<?php

namespace App\Clinica\Verificacion;

/**
 * **Lo que la página pública puede decir de un récipe** (WEB-5.6): el estado de su verificación y los datos **públicos**
 * del récipe. Nada del paciente (ni nombre, ni cédula, ni edad, ni iniciales) y nada de las indicaciones: la farmacia
 * necesita saber que el récipe es auténtico, de quién es, de cuándo es y qué lleva, no a quién se lo recetaron.
 *
 * Objeto de valores sin lógica: lo arma `LectorDeVerificacion` y lo pinta `Vistas\VerificacionPublicaVista`.
 */
final class RecetaVerificada
{
    /**
     * @param  array{nombre:string, especialidad:?string, rif:?string, registro:?string, lugar:array<int,string>}|null  $medico
     * @param  array<int,array{cantidad:string, nombre:string}>  $medicamentos
     */
    public function __construct(
        public readonly string $estado,
        public readonly bool $esDeLaPareja = false,
        public readonly ?int $numero = null,
        public readonly ?string $emision = null,
        public readonly ?string $vence = null,
        public readonly int $vigenciaEnDias = 0,
        public readonly ?string $anuladoEn = null,
        public readonly ?array $medico = null,
        public readonly array $medicamentos = [],
    ) {
    }

    public static function noEncontrado(): self
    {
        return new self(EstadoDeLaVerificacion::NO_ENCONTRADO);
    }

    public static function ejemplo(): self
    {
        return new self(EstadoDeLaVerificacion::EJEMPLO);
    }
}
