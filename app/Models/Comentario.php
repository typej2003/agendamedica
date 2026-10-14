<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un comentario de **"Ayúdanos a mejorar"** (ROADMAP §9): lo que un médico o una secretaría nos manda desde
 * la web —un error, una sugerencia o algo que le falta— y lo que le respondemos.
 *
 * Tabla **nueva de AppDDR**, no del legado: no entra al sync del escritorio. El flujo es: el usuario lo
 * escribe y lo ve en "Mis comentarios"; nosotros lo recibimos en la bandeja de `/admin`, lo respondemos (o lo
 * descartamos) y él ve la respuesta.
 */
class Comentario extends Model
{
    /** Los estados. `nuevo` es el que espera respuesta; los otros dos ya están atendidos (uno con respuesta
     *  y el otro porque decidimos no hacer nada: descartar es una decisión, no un borrado). */
    public const NUEVO = 'nuevo';
    public const RESPONDIDO = 'respondido';
    public const DESCARTADO = 'descartado';

    public const ESTADOS = [self::NUEVO, self::RESPONDIDO, self::DESCARTADO];

    /** Qué nos está contando. */
    public const BUG = 'bug';
    public const SUGERENCIA = 'sugerencia';
    public const CARENCIA = 'carencia';

    public const TIPOS = [
        self::BUG => 'Un error',
        self::SUGERENCIA => 'Una sugerencia',
        self::CARENCIA => 'Algo que le falta',
    ];

    protected $table = 'comentarios';

    protected $fillable = [
        'user_id', 'medico_id', 'reg_medico', 'rol', 'origen', 'tipo', 'mensaje', 'contexto',
        'estado', 'respuesta', 'respondido_por', 'respondido_el',
    ];

    protected $casts = [
        'contexto' => 'array',
        'respondido_el' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function respondedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondido_por');
    }

    public function estaNuevo(): bool
    {
        return $this->estado === self::NUEVO;
    }

    public function fueRespondido(): bool
    {
        return $this->estado === self::RESPONDIDO;
    }

    public function fueDescartado(): bool
    {
        return $this->estado === self::DESCARTADO;
    }

    /** Cómo se nombra el tipo en pantalla ("Un error", "Una sugerencia"…). */
    public function tipoLegible(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
