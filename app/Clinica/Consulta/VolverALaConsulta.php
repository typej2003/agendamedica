<?php

namespace App\Clinica\Consulta;

use Illuminate\Http\Request;

/**
 * **El botón «Volver a la consulta»** de las pantallas del paciente que se abren desde la consulta (Alexander, 2026-10-26: desde
 * *Inmunizaciones*, el crecimiento o el representante «no hay cómo retornar a la consulta»).
 *
 * Esas pantallas son del paciente y no saben de la consulta, así que **la consulta abierta se recuerda en la sesión** al abrirla y el armazón
 * (`ArmazonClinicaComposer`) ofrece el botón en las pantallas del **mismo paciente**. Al terminar la consulta se olvida: pasado ese punto la ficha
 * ya no «viene de» ninguna consulta. Sin cambiar ninguna de esas pantallas: el botón lo pinta el layout.
 */
final class VolverALaConsulta
{
    private const CLAVE = 'clinica.consulta_abierta';

    public static function recordar(Request $peticion, int $consultaId, int $pacienteId, int $nroconsulta): void
    {
        $peticion->session()->put(self::CLAVE, ['id' => $consultaId, 'paciente_id' => $pacienteId, 'nroconsulta' => $nroconsulta]);
    }

    public static function olvidar(Request $peticion): void
    {
        $peticion->session()->forget(self::CLAVE);
    }

    /**
     * El botón de la pantalla que se está viendo, o `null` si no hay consulta abierta o la pantalla es de otro paciente.
     *
     * @return array{url:string, texto:string}|null
     */
    public static function para(Request $peticion): ?array
    {
        $ruta = $peticion->route();
        $nombre = (string) $ruta?->getName();

        if (! $peticion->hasSession() || ! str_starts_with($nombre, 'clinica.pacientes.')) {
            return null;
        }

        $abierta = $peticion->session()->get(self::CLAVE);
        if (! is_array($abierta) || ! isset($abierta['id'], $abierta['paciente_id'])) {
            return null;
        }

        $paciente = $ruta->parameter('paciente');
        $pacienteId = is_object($paciente) ? (int) $paciente->getKey() : (int) $paciente;

        if ($pacienteId !== (int) $abierta['paciente_id']) {
            return null;
        }

        return [
            'url' => route('clinica.consulta', $abierta['id']),
            'texto' => 'Volver a la consulta N.º ' . (int) ($abierta['nroconsulta'] ?? 0),
        ];
    }
}
