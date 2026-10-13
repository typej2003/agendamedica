<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **La verificación pública de un récipe** (WEB-5.6): identifica un récipe impreso desde la web y le da el
 * `token` del QR y el `codigo` corto. No guarda el contenido del récipe: la página pública lo lee vivo.
 *
 * El `token` es una capacidad pública (quien lo tiene ve la página): está **oculto** de la serialización para que no
 * viaje por accidente en un JSON, y no se loguea.
 */
class RecipeVerificacion extends Model
{
    public const TIPO_PACIENTE = 'paciente';

    public const TIPO_PAREJA = 'pareja';

    protected $table = 'recipe_verificaciones';

    protected $fillable = [
        'token', 'codigo', 'reg_medico', 'medico_id', 'nrohistoria', 'nroconsulta', 'tipo', 'recipe', 'revocado_en',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'nrohistoria' => 'integer',
        'nroconsulta' => 'integer',
        'recipe' => 'integer',
        'medico_id' => 'integer',
        'revocado_en' => 'datetime',
    ];

    public function estaAnulada(): bool
    {
        return $this->revocado_en !== null;
    }

    public function esDeLaPareja(): bool
    {
        return $this->tipo === self::TIPO_PAREJA;
    }
}
