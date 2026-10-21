<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **De dónde salió el comentario** de "Ayúdanos a mejorar" (2026-10-20, pedido del usuario como
 * administrador): hasta ahora la tabla sólo guardaba el canal (`origen`: `web` o `app`), y la plataforma
 * —android, iOS, Windows, macOS, Linux o el navegador— viajaba dentro de `contexto`, que es un JSON y no se
 * puede ni mirar de un vistazo ni filtrar. Sin ella no se sabe en qué equipo reproducir un error.
 *
 * Columna **nueva y nullable** (la tabla no es del legado, pero sus filas ya existen): los comentarios que
 * llegaron de la web quedan con `web`, que es lo que se sabía de ellos. Los del app quedan en `NULL` —de un
 * teléfono viejo no sabemos la plataforma y no la inventamos—, y la bandeja los muestra como «App» a secas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            // `web`, `android`, `ios`, `windows`, `macos`, `linux` (`App\Models\Comentario::PLATAFORMAS`).
            $table->string('plataforma', 20)->nullable()->after('origen');
        });

        DB::table('comentarios')
            ->where('origen', 'web')
            ->whereNull('plataforma')
            ->update(['plataforma' => 'web']);
    }

    public function down(): void
    {
        Schema::table('comentarios', function (Blueprint $table) {
            $table->dropColumn('plataforma');
        });
    }
};
