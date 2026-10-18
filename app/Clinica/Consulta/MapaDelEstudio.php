<?php

namespace App\Clinica\Consulta;

use App\Clinica\Consulta\Vistas\TiposDeEstudio;

/**
 * **El mapa del dato del estudio que se está mirando** (O-17): el de cada uno de los tipos del ítem 5, con
 * una sola puerta.
 *
 * Los ocho tipos de la sección (`TiposDeEstudio`) tienen su mapa en su propia clase
 * (`EcoGinecologico`, `EcoObstetrico`, `EcoObstetricoTercero`, `EcoDoppler`, `EcoMamario`, `EcoAbdominal`,
 * `EcoCardiogramaFetal`), pero varias capas necesitan **el que corresponde a un `?tipo=`**: la validación y
 * los rótulos de los mensajes (`ConsultaEstudioController`), las plantillas del formato
 * (`PlantillasDelFormato`) y sus dos endpoints. Antes ese `match` estaba escrito en el controlador; acá está
 * una vez, y un tipo nuevo suma su rama en un solo lugar.
 *
 * Sin base de datos y sin estado: sólo devuelve el mapa declarado por cada estudio.
 */
final class MapaDelEstudio
{
    /**
     * El mapa **por bloques** del tipo (los grupos con su título, su icono y sus campos).
     *
     * @return array<int,array{titulo:string, icono?:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(string $tipo): array
    {
        return match ($tipo) {
            TiposDeEstudio::OBSTETRICO => EcoObstetrico::grupos(),
            TiposDeEstudio::SEGUNDO, TiposDeEstudio::TERCERO => EcoObstetricoTercero::grupos(),
            TiposDeEstudio::DOPPLER => EcoDoppler::grupos(),
            TiposDeEstudio::MAMARIO => EcoMamario::grupos(),
            TiposDeEstudio::ABDOMINAL => EcoAbdominal::grupos(),
            TiposDeEstudio::FETAL => EcoCardiogramaFetal::grupos(),
            default => EcoGinecologico::grupos(),
        };
    }

    /**
     * Los campos del tipo, aplanados y en el orden del formulario.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(string $tipo): array
    {
        $campos = [];

        foreach (self::grupos($tipo) as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $campos[] = $campo;
            }
        }

        return $campos;
    }

    /**
     * Las columnas **descriptivas** del estudio: las opciones, los textos y las áreas.
     *
     * Es la regla de las plantillas del formato (O-17): la plantilla guarda lo que describe al estudio —las
     * opciones del útero y los ovarios, los hallazgos, los anexos, las conclusiones— y **no** las medidas
     * (`numero`), que son de cada paciente, ni las fechas (`fecha`, `fecha-tecleada`), que son del día del
     * estudio o de la FUR. La regla sale del **tipo de control** del mapa, así que vale igual en los siete
     * estudios y un campo nuevo no la tiene que declarar dos veces.
     *
     * @return array<int,string>
     */
    public static function columnasDescriptivas(string $tipo): array
    {
        $descriptivas = [];

        foreach (self::campos($tipo) as $campo) {
            if (self::esDescriptivo($campo)) {
                $descriptivas[] = (string) $campo['columna'];
            }
        }

        return $descriptivas;
    }

    /** ¿El campo es de los que guarda una plantilla del formato? */
    public static function esDescriptivo(array $campo): bool
    {
        return in_array($campo['tipo'] ?? 'texto', ['opciones', 'texto', 'area'], true);
    }
}
