<?php

namespace App\Verificacion;

use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\User;
use App\Models\VerificacionDeMedico;
use InvalidArgumentException;

/**
 * **Las verificaciones de una ficha médica** (2026-11-03): leerlas, marcarlas y dejar el punto de partida
 * al aprobar un registro. Carnet, correo y teléfono, cada uno por separado.
 *
 * Es el único lugar donde se escribe `verificaciones_medico`; la usan el alta del médico (manual y
 * automática), la bandeja de solicitudes y el detalle del médico en el panel.
 *
 * Una ficha sin fila para un tipo se lee como `sin_verificar`: los médicos que ya existían no necesitan
 * una fila para seguir funcionando, y su carnet sin verificar no los bloquea salvo que el administrador
 * encienda la exigencia.
 */
final class VerificacionesDelMedico
{
    /**
     * Las tres verificaciones de la ficha, con las que faltan completadas en memoria (sin escribirlas).
     *
     * @return array<string, VerificacionDeMedico> tipo => fila
     */
    public function de(Medico $medico): array
    {
        $existentes = $medico->verificaciones()->get()->keyBy('tipo');
        $resultado = [];

        foreach (VerificacionDeMedico::TIPOS as $tipo) {
            $resultado[$tipo] = $existentes->get($tipo) ?? new VerificacionDeMedico([
                'medico_id' => $medico->id,
                'tipo' => $tipo,
                'estado' => VerificacionDeMedico::SIN_VERIFICAR,
            ]);
        }

        return $resultado;
    }

    /** El estado de un tipo; `sin_verificar` si la ficha no tiene fila. */
    public function estado(Medico $medico, string $tipo): string
    {
        return $medico->estadoDeVerificacion($tipo);
    }

    /**
     * Marca una verificación (upsert por `medico_id` + `tipo`). `verificado_el` y `verificado_por` sólo
     * quedan cuando el estado es `verificado`; en los demás se limpian, para que la fecha no mienta.
     *
     * @throws InvalidArgumentException si el tipo o el estado no son de los conocidos
     */
    public function marcar(
        Medico $medico,
        string $tipo,
        string $estado,
        ?User $por = null,
        ?string $nota = null,
    ): VerificacionDeMedico {
        if (! in_array($tipo, VerificacionDeMedico::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de verificación desconocido: {$tipo}");
        }

        if (! in_array($estado, VerificacionDeMedico::ESTADOS, true)) {
            throw new InvalidArgumentException("Estado de verificación desconocido: {$estado}");
        }

        $fila = VerificacionDeMedico::firstOrNew([
            'medico_id' => $medico->id,
            'tipo' => $tipo,
        ]);

        $fila->fill([
            'estado' => $estado,
            'verificado_el' => $estado === VerificacionDeMedico::VERIFICADO ? now() : null,
            'verificado_por' => $estado === VerificacionDeMedico::VERIFICADO ? $por?->id : null,
            'nota' => $nota,
        ]);

        $fila->save();

        return $fila;
    }

    /**
     * **El punto de partida al aprobar un registro** (manual o automático):
     *
     *  - el **correo** queda verificado: el médico acaba de escribir el código de 6 dígitos;
     *  - el **carnet** queda verificado si el administrador lo confirmó (sólo en la aprobación manual),
     *    `pendiente` si hay foto para revisar y `sin_verificar` si no adjuntó ninguna;
     *  - el **teléfono** queda `sin_verificar`: se verificará por WhatsApp o SMS desde el perfil del
     *    médico, y por ahora sólo se anota (decisión de Alexander, 2026-11-03).
     */
    public function registrarAlta(
        SolicitudMedico $solicitud,
        Medico $medico,
        bool $carnetVerificado,
        ?User $por = null,
    ): void {
        $this->marcar(
            $medico,
            VerificacionDeMedico::CORREO,
            VerificacionDeMedico::VERIFICADO,
            $por,
            'Verificado con el código de 6 dígitos del registro.',
        );

        $this->marcar(
            $medico,
            VerificacionDeMedico::CARNET,
            match (true) {
                $carnetVerificado => VerificacionDeMedico::VERIFICADO,
                (bool) $solicitud->carnet_ruta => VerificacionDeMedico::PENDIENTE,
                default => VerificacionDeMedico::SIN_VERIFICAR,
            },
            $por,
            match (true) {
                $carnetVerificado => null,
                (bool) $solicitud->carnet_ruta => 'El carnet está adjunto en la solicitud: falta revisarlo.',
                default => 'El médico no adjuntó el carnet.',
            },
        );

        $this->marcar(
            $medico,
            VerificacionDeMedico::TELEFONO,
            VerificacionDeMedico::SIN_VERIFICAR,
            null,
            'Se verificará por WhatsApp o SMS desde el perfil del médico.',
        );
    }
}
