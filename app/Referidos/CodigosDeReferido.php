<?php

namespace App\Referidos;

use App\Models\CodigoDeReferido;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * **Los códigos de referido**: uno vigente por consultorio, de 8 caracteres, sin letras que se confunden
 * (`0/O`, `1/I/L`).
 *
 * El código es público (viaja en el link) y no es un secreto: se valida contra la base en cada uso. El
 * link es `/r/{codigo}`; “regenerar” cambia el código y el link viejo deja de atribuir.
 */
final class CodigosDeReferido
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LARGO = 8;

    /** El código vigente del consultorio; lo crea si es la primera vez. */
    public function para(string $regMedico): CodigoDeReferido
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw new RuntimeException('Falta el consultorio del médico.');
        }

        for ($intento = 0; $intento < 5; $intento++) {
            try {
                return CodigoDeReferido::firstOrCreate(
                    ['reg_medico' => $regMedico],
                    ['codigo' => $this->generarUnico(), 'activo' => true],
                );
            } catch (QueryException $e) {
                // Dos peticiones a la vez (o una colisión de código): si la fila ya existe, se usa.
                $existente = CodigoDeReferido::where('reg_medico', $regMedico)->first();
                if ($existente) {
                    return $existente;
                }
            }
        }

        throw new RuntimeException('No se pudo crear el código de referido del consultorio.');
    }

    /** El código activo que corresponde a un link, o null si no existe o se apagó. */
    public function resolver(?string $codigo): ?CodigoDeReferido
    {
        $codigo = strtoupper(trim((string) $codigo));
        if ($codigo === '') {
            return null;
        }

        return CodigoDeReferido::where('codigo', $codigo)->where('activo', true)->first();
    }

    /** Cambia el código del consultorio: el link anterior deja de atribuir. */
    public function regenerar(string $regMedico): CodigoDeReferido
    {
        $codigo = $this->para($regMedico);
        $codigo->update(['codigo' => $this->generarUnico(), 'activo' => true]);

        return $codigo->refresh();
    }

    /** Suma un clic sin guardar IP ni datos personales. */
    public function registrarClic(CodigoDeReferido $codigo): void
    {
        // `increment` no dispara eventos ni toca `updated_at`; es sólo un contador.
        CodigoDeReferido::whereKey($codigo->id)->increment('clics', 1, ['ultimo_clic_el' => now()]);
    }

    private function generarUnico(): string
    {
        for ($intento = 0; $intento < 20; $intento++) {
            $codigo = '';
            for ($i = 0; $i < self::LARGO; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }

            if (! CodigoDeReferido::where('codigo', $codigo)->exists()) {
                return $codigo;
            }
        }

        throw new RuntimeException('No se pudo generar un código de referido único.');
    }
}
