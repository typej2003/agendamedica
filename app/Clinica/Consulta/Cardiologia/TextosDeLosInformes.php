<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Models\TextoCardiacoMedico;

/**
 * **Las plantillas de los informes del cardiólogo** (C12, 2026-11-07): los tres textos por defecto con los que se
 * **precargan** el Doppler de miembros superiores y el marcapasos, como el escritorio.
 *
 * El escritorio los guarda en una sola fila de `texto_cardiaco` (`clave` = `1`, sin `reg_medico`); la web los guarda
 * por médico en `texto_cardiaco_medico`.
 */
final class TextosDeLosInformes
{
    /** Las columnas del estudio que se precargan, por `tabla.columna`. */
    public const PRECARGA = [
        'dd_arterial_venoso_ms.texto' => 'duplex_superior',
        'implantacion_marcapasos.protocolo_implante' => 'protocolo_marcapaso',
        'implantacion_marcapasos.textazo' => 'texto_marca',
    ];

    public function delMedico(?string $regMedico): ?TextoCardiacoMedico
    {
        return $regMedico === null || $regMedico === ''
            ? null
            : TextoCardiacoMedico::where('reg_medico', $regMedico)->first();
    }

    /**
     * Lo que se precarga en una pestaña, ya por `tabla.columna`.
     *
     * @return array<string,string>
     */
    public function precarga(?string $regMedico): array
    {
        $fila = $this->delMedico($regMedico);

        if ($fila === null) {
            return [];
        }

        $textos = [];

        foreach (self::PRECARGA as $destino => $columna) {
            $texto = trim((string) $fila->{$columna});

            if ($texto !== '') {
                $textos[$destino] = $texto;
            }
        }

        return $textos;
    }
}
