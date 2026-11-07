<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El estado civil del representante** (O-59, 2026-11-05): en pediatría el estado civil no es del niño,
 * es del adulto que responde por él.
 *
 * El alta del niño ya no lo pide (`resources/views/clinica/pacientes/_campos.blade.php`), y el del
 * representante se carga y se edita en el bloque «Representante» de la ficha. Para guardarlo hacía falta
 * una columna: `representante` no la tiene.
 *
 * **Por qué una columna nueva de AppDDR y no `familia_bebe.situacion_pareja`.** El escritorio de pediatría
 * guarda en la familia la **situación de pareja de los padres**, que es otro dato y otra escala
 * (`E` estable / `S` separados / `V` viudo/a / `D` soltero/a). No sirve como respaldo del estado civil
 * —`S` significaría «Separados» en una y «Soltero(a)» en la otra, y `E` no existe en el catálogo de
 * `codeestado`—: usarla sería afirmar una equivalencia que nadie verificó, el mismo criterio del
 * catálogo de parentescos. El estado civil del representante se carga en la web y, si está vacío, se
 * muestra vacío; no se rellena con la familia. Además, escribir en `familia_bebe` lo pisaría la próxima
 * subida del escritorio.
 *
 * **La escala es la del paciente.** `estado_civil` guarda los mismos códigos que `pacientes.codeestado` y
 * los del `<select name="codeestado">` del alta (`FormularioDePacienteVista::ESTADOS_CIVILES`):
 * `S` soltero(a), `C` casado(a), `D` divorciado(a), `V` viudo(a). Es el estado civil de **una persona**,
 * el mismo dato que el paciente adulto declara.
 *
 * **El sync no se toca.** `representante` sigue en la lista blanca de `config/sync_legado.php`, y el
 * camino de subida (`App\Sync\Escritorio\CambiosEscritorio::guardarGenerico`) filtra la fila del
 * escritorio a las columnas que existen y actualiza **sólo las que vienen**: una fila sin `estado_civil`
 * deja el valor de la web como está (lo fija
 * `CambiosEscritorioTest::test_la_subida_del_escritorio_no_borra_el_estado_civil_del_representante`).
 *
 * La regla del proyecto es la misma de siempre: una tabla del legado no se reestructura y sólo se le
 * agregan **columnas nullable** (`AGENTS.md` §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('representante') && ! Schema::hasColumn('representante', 'estado_civil')) {
            Schema::table('representante', function (Blueprint $table) {
                // Un carácter, como `pacientes.codeestado` y `representante.codeparentesco`.
                $table->string('estado_civil', 1)->nullable()->after('codeparentesco');
            });
        }
    }

    /** Se revierte sin perder datos del legado: la columna nació vacía y nadie más la escribe. */
    public function down(): void
    {
        if (Schema::hasTable('representante') && Schema::hasColumn('representante', 'estado_civil')) {
            Schema::table('representante', function (Blueprint $table) {
                $table->dropColumn('estado_civil');
            });
        }
    }
};
