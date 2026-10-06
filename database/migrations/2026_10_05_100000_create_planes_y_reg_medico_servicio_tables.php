<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de servicio y servicio contratado por `reg_medico` (ROADMAP: planes y vencimiento).
 *
 *  - `planes`: el catálogo. Lo comercial es la frecuencia de cobro y el monto; el monto tachado y el
 *    resto son visuales. Las restricciones (máx. de médicos, histórico visible…) van en un JSON porque se
 *    van a ir agregando: el modelo asume valores por defecto para lo que falte (App\Support\RestriccionesPlan).
 *  - `reg_medico_servicio`: una fila por contratación o renovación. El servicio vigente de un `reg_medico`
 *    es la fila activa de mayor `vence_el`. Copia las restricciones y el monto del plan al contratar: si
 *    después se edita el plan, lo ya contratado no cambia solo.
 *
 * No toca `medicos` ni ninguna tabla existente.
 *
 * Además siembra los dos planes que el sistema necesita para funcionar y le da servicio a lo que ya existía:
 *  - `prueba`: el plan por defecto, gratis y de 1 mes. Es el que recibe un médico recién registrado.
 *  - `powerbuilder`: 1 año gratis para quien sincroniza desde el escritorio. No se ofrece en el catálogo.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->string('frecuencia', 10)->default('mensual'); // mensual (1 mes) | anual (12 meses)
            $table->decimal('precio_usd', 8, 2)->default(0);
            $table->decimal('precio_tachado_usd', 8, 2)->nullable(); // visual: el "antes" tachado; el ahorro es la resta
            $table->json('restricciones')->nullable();
            $table->boolean('es_default')->default(false); // el que recibe un médico recién registrado (solo uno)
            $table->boolean('visible')->default(true);     // visual: aparece en el catálogo que se le ofrece al médico
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('reg_medico_servicio', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50);
            $table->foreignId('plan_id')->nullable()->constrained('planes')->nullOnDelete();
            $table->string('plan_nombre', 100)->nullable(); // copia: el plan puede renombrarse o borrarse
            $table->string('origen', 20)->default('manual'); // registro | powerbuilder | compra | manual
            $table->date('inicia_el');
            $table->date('vence_el');
            $table->decimal('monto_usd', 8, 2)->default(0);  // lo que se cobró de verdad (0 en lo gratuito)
            $table->json('restricciones')->nullable();       // copia de las del plan al contratar
            $table->string('estado', 12)->default('activo'); // activo | cancelado
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->index(['reg_medico', 'vence_el']);
        });

        $ahora = now();
        $planes = [
            [
                'codigo' => 'prueba', 'nombre' => 'Prueba gratuita', 'frecuencia' => 'mensual', 'precio_usd' => 0,
                'descripcion' => 'Para registrarse y probar sin costo.',
                'es_default' => true, 'visible' => false, 'orden' => 0,
            ],
            [
                'codigo' => 'powerbuilder', 'nombre' => 'GinecoReport · 1 año de cortesía', 'frecuencia' => 'anual', 'precio_usd' => 0,
                'descripcion' => 'Un año gratis para quienes ya usan el escritorio y sincronizan desde él.',
                'es_default' => false, 'visible' => false, 'orden' => 1,
            ],
        ];
        foreach ($planes as $plan) {
            DB::table('planes')->insert($plan + [
                'restricciones' => json_encode(['max_medicos' => null, 'max_historico_meses' => null]),
                'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        $this->darServicioALoQueYaExiste($ahora);
    }

    public function down()
    {
        Schema::dropIfExists('reg_medico_servicio');
        Schema::dropIfExists('planes');
    }

    /**
     * Lo que ya sincronizaba antes de que existieran los planes no puede quedarse sin servicio (se le
     * cortaría la sincronización): quien ya tiene credencial o carga del escritorio recibe el año de
     * cortesía y el médico que solo tiene cuenta recibe la prueba. Una sola vez, por `reg_medico`.
     */
    private function darServicioALoQueYaExiste($ahora): void
    {
        $hoy = $ahora->toDateString();
        $planes = DB::table('planes')->pluck('id', 'codigo');
        $restricciones = json_encode(['max_medicos' => null, 'max_historico_meses' => null]);

        $dar = function (string $reg, string $codigo, string $origen, string $vence, string $nombre) use ($planes, $hoy, $ahora, $restricciones) {
            DB::table('reg_medico_servicio')->insert([
                'reg_medico' => $reg, 'plan_id' => $planes[$codigo], 'plan_nombre' => $nombre, 'origen' => $origen,
                'inicia_el' => $hoy, 'vence_el' => $vence, 'monto_usd' => 0, 'restricciones' => $restricciones,
                'estado' => 'activo', 'nota' => 'Asignado al crear los planes', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        };

        $deEscritorio = collect();
        foreach (['sync_cargas', 'sync_credentials'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                $deEscritorio = $deEscritorio->merge(DB::table($tabla)->whereNotNull('reg_medico')->pluck('reg_medico'));
            }
        }
        $deEscritorio = $deEscritorio->map(fn ($r) => trim((string) $r))->filter()->unique();
        foreach ($deEscritorio as $reg) {
            $dar($reg, 'powerbuilder', 'powerbuilder', $ahora->copy()->addYear()->toDateString(), 'GinecoReport · 1 año de cortesía');
        }

        if (Schema::hasTable('medicos')) {
            $conCuenta = DB::table('medicos')->whereNotNull('reg_medico')->pluck('reg_medico')
                ->map(fn ($r) => trim((string) $r))->filter()->unique()
                ->reject(fn ($r) => $deEscritorio->contains($r));
            foreach ($conCuenta as $reg) {
                $dar($reg, 'prueba', 'registro', $ahora->copy()->addMonth()->toDateString(), 'Prueba gratuita');
            }
        }
    }
};
