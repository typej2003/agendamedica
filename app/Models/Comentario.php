<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * **Cuántas imágenes** puede llevar un comentario (O-25). El tope es de producto —una captura o tres, no un
     * álbum— y lo valida el controlador, que es donde se ve la lista completa; el tamaño y el tipo de cada una
     * los pone la política de archivos (`ArchivosDeLosComentarios`).
     */
    public const MAXIMO_IMAGENES = 3;

    /** Qué nos está contando. */
    public const SUGERENCIA = 'sugerencia';
    public const BUG = 'bug';
    public const CARENCIA = 'carencia';

    public const TIPOS = [
        self::SUGERENCIA => 'Una sugerencia',
        self::BUG => 'Un error',
        self::CARENCIA => 'Algo que le falta',
    ];

    /**
     * **De dónde salió el comentario.** `origen` es el canal (`web` = la clínica en el navegador, `app` = el
     * app móvil) y `plataforma` es el equipo: las dos juntas dicen «Web» o «App · Android», que es lo que
     * necesita quien atiende la bandeja para ir a reproducir el problema donde pasó (2026-10-20).
     *
     * Los nombres son los que manda el app (`defaultTargetPlatform`, en minúsculas) más `web`, que es tanto
     * la clínica como el app abierto en un navegador (ahí `origen` los distingue).
     */
    public const PLATAFORMAS = [
        'web' => 'Web',
        'android' => 'Android',
        'ios' => 'iOS',
        'windows' => 'Windows',
        'macos' => 'macOS',
        'linux' => 'Linux',
    ];

    /** La plataforma de la web clínica; la pone el servidor, no el formulario. */
    public const PLATAFORMA_WEB = 'web';

    protected $table = 'comentarios';

    protected $fillable = [
        'user_id', 'medico_id', 'reg_medico', 'rol', 'origen', 'plataforma', 'tipo', 'mensaje', 'contexto',
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

    /**
     * Las **imágenes adjuntas** (O-25): las capturas que el usuario pegó o eligió. Cuelgan de la fila
     * (`comentario_imagenes.comentario_id`, con cascada) y viven en el disco privado `comentarios`; se borran
     * con el comentario cuando el reporte se descarta.
     */
    public function imagenes(): HasMany
    {
        return $this->hasMany(ComentarioImagen::class, 'comentario_id')->orderBy('id');
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

    /**
     * **De dónde salió, como se lee en la bandeja**: «Web» o «App · Android». Una fila anterior a la columna
     * (o un app que no dijo su plataforma) queda en «App» o «Web», que es lo único que se sabía de ella.
     */
    public function origenLegible(): string
    {
        $plataforma = $this->plataformaLegible();

        // El comentario de la web clínica: ahí la plataforma es el navegador y no hay nada más que decir.
        if ($this->origen !== 'app') {
            return $plataforma ?? 'Web';
        }

        if ($plataforma === null) {
            return 'App';
        }

        // El app abierto en un navegador (Flutter web): `origen` lo distingue de la clínica, que también dice
        // `web` en la columna.
        return 'App · ' . ($this->plataforma === self::PLATAFORMA_WEB ? 'navegador' : $plataforma);
    }

    /** El nombre de la plataforma para pantalla; `null` si no se sabe. */
    public function plataformaLegible(): ?string
    {
        $plataforma = self::normalizarPlataforma($this->plataforma);

        return $plataforma === null ? null : (self::PLATAFORMAS[$plataforma] ?? ucfirst($plataforma));
    }

    /** El ícono del panel (`bootstrap-icons`) que le corresponde a la plataforma. */
    public function iconoDeLaPlataforma(): string
    {
        return match (self::normalizarPlataforma($this->plataforma)) {
            'android' => 'bi-android2',
            'ios', 'macos' => 'bi-apple',
            'windows' => 'bi-windows',
            'linux' => 'bi-ubuntu',
            default => 'bi-globe2',
        };
    }

    /**
     * La plataforma que se guarda, a partir de lo que mandó el cliente: en minúsculas y sólo si es una de las
     * conocidas. `null` si no dijo nada usable —un valor raro no rechaza el comentario, igual que el contexto
     * de `ComentarioController::contexto()`—: lo que nos tiene que llegar es lo que escribió el usuario.
     */
    public static function normalizarPlataforma(mixed $crudo): ?string
    {
        if (! is_string($crudo)) {
            return null;
        }

        // `iOS` y `macOS` llegan capitalizados (`defaultTargetPlatform.name`); el resto, en minúsculas.
        $plataforma = strtolower(trim($crudo));

        return $plataforma === '' ? null : (array_key_exists($plataforma, self::PLATAFORMAS) ? $plataforma : null);
    }
}
