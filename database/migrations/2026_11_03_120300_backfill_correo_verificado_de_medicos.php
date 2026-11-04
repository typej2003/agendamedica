<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **Relleno**: las solicitudes que ya estaban aprobadas antes de la verificación por niveles (2026-11-03)
 * verificaron su correo con el código de 6 dígitos, aunque no tengan fila en `verificaciones_medico`. Se les
 * crea la fila `correo = verificado` para que el perfil no diga «sin verificar» de un dato que sí lo está.
 *
 * **El carnet no se rellena a propósito**: la exigencia nace apagada y, cuando el administrador la encienda,
 * los médicos que ya estaban tienen que validarse a mano desde su detalle (decisión de Alexander). Marcarlos
 * `verificado` sin revisarlos sería mentir sobre lo que se sabe.
 *
 * La `nota` distingue estas filas de las que escribe el alta: el `down` borra sólo las de este relleno.
 */
return new class extends Migration
{
    private const NOTA = 'Alta anterior a la verificación: el correo se verificó con el código del registro.';

    public function up(): void
    {
        DB::table('solicitudes_medico')
            ->where('estado', 'aprobada')
            ->whereNotNull('medico_id')
            ->orderBy('id')
            ->each(function ($solicitud) {
                DB::table('verificaciones_medico')->updateOrInsert(
                    ['medico_id' => $solicitud->medico_id, 'tipo' => 'correo'],
                    [
                        'estado' => 'verificado',
                        'verificado_el' => $solicitud->revisada_el ?? now(),
                        'verificado_por' => null,
                        'nota' => self::NOTA,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            });
    }

    public function down(): void
    {
        DB::table('verificaciones_medico')
            ->where('tipo', 'correo')
            ->where('nota', self::NOTA)
            ->delete();
    }
};
