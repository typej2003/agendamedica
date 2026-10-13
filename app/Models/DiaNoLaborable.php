<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un día en el que el médico **no atiende**: feriado, congreso, fin de semana, otro consultorio o
 * quirófano. Es la tabla legada `cola_dia_no_labor`, que el escritorio usa para **abortar** el
 * agendamiento (`w_nueva_cita_7.srw:698-706`: "Para el <fecha> El Dr(a) tiene: <motivo>").
 *
 * Dos columnas del legado que no hay que confundir:
 *
 *  - `reg_medico` es el consultorio (la tenancy de AppDDR); es nullable y el legado ni la escribe.
 *  - `medico` es la **clave de `evolucion`** del médico dentro del consultorio —el mismo número que
 *    `cola.medico`—, por eso no coincide con `medicos.id`. Ver `Medico::claveDeEvolucion()`.
 *
 * En el escritorio `(dia, medico)` es clave única: un día tiene un motivo por médico. Acá eso se
 * valida al guardar (el esquema migrado no trae ese índice).
 */
class DiaNoLaborable extends Model
{
    protected $table = 'cola_dia_no_labor';

    /** Dominio de `tipo` del legado (matriz de paridad, pantalla `w_horarios`). */
    public const FERIADO = 'F';
    public const FIN_DE_SEMANA = 'S';
    public const CONGRESO = 'C';
    public const OTRO_CONSULTORIO = 'O';
    public const QUIRURGICO = 'Q';

    protected $fillable = [
        'reg_medico',
        'dia',
        'tipo',
        'motivo',
        'medico',
    ];

    protected $casts = [
        'dia'    => 'date',
        'medico' => 'integer',
    ];

    /** Las etiquetas del `tipo`, en el orden en que se ofrecen. */
    public static function etiquetas(): array
    {
        return [
            self::FERIADO          => 'Feriado',
            self::FIN_DE_SEMANA    => 'Fin de semana',
            self::CONGRESO         => 'Congreso',
            self::OTRO_CONSULTORIO => 'Otro consultorio',
            self::QUIRURGICO       => 'Quirúrgico',
        ];
    }

    /** Los valores válidos de `tipo`, para la validación. */
    public static function tipos(): array
    {
        return array_keys(self::etiquetas());
    }

    /**
     * Cómo se muestra el tipo. Un valor desconocido (el legado no valida nada) se muestra tal cual:
     * el dato no se pierde ni se inventa una etiqueta.
     */
    public function etiquetaTipo(): string
    {
        return self::etiquetas()[$this->tipo] ?? (string) $this->tipo;
    }
}
