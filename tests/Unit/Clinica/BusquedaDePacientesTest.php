<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Pacientes\BusquedaDePacientes;
use PHPUnit\Framework\TestCase;

/**
 * La búsqueda de pacientes se prueba sin base de datos ni HTTP: lo que se le pasa al `where` y cómo se
 * normaliza cada palabra (Docs/Web/guia-arquitectura-web.md). El efecto en SQL lo cubre
 * `tests/Feature/PacientesWebTest.php`.
 */
class BusquedaDePacientesTest extends TestCase
{
    public function test_parte_lo_buscado_en_palabras_y_saca_las_repetidas(): void
    {
        $this->assertSame(['Ana', 'Alvarez'], BusquedaDePacientes::palabras('Ana Alvarez'));
        $this->assertSame(['Ana', 'Alvarez'], BusquedaDePacientes::palabras('  Ana   Alvarez  '));
        $this->assertSame(['Pérez'], BusquedaDePacientes::palabras('Pérez Pérez'));
        $this->assertSame([], BusquedaDePacientes::palabras('   '));
    }

    public function test_acota_las_palabras_para_que_una_busqueda_larga_no_multiplique_la_consulta(): void
    {
        $palabras = BusquedaDePacientes::palabras('uno dos tres cuatro cinco seis siete ocho');

        $this->assertCount(5, $palabras);
        $this->assertSame(['uno', 'dos', 'tres', 'cuatro', 'cinco'], $palabras);

        $larga = BusquedaDePacientes::palabras(str_repeat('a', 60));
        $this->assertSame(40, mb_strlen($larga[0]));
    }

    public function test_normaliza_mayusculas_y_acentos_igual_que_la_expresion_sql(): void
    {
        // Es el mismo mapa que usa `columnaNormalizada`: si los dos lados no coinciden, la búsqueda deja
        // de encontrar (en SQLite `LIKE` sólo pliega mayúsculas ASCII).
        $this->assertSame('jaquez', BusquedaDePacientes::normalizar('Jáquez'));
        $this->assertSame('jaquez', BusquedaDePacientes::normalizar('JÁQUEZ'));
        $this->assertSame('pina', BusquedaDePacientes::normalizar('Piña'));
        $this->assertSame('avila ramon', BusquedaDePacientes::normalizar('Ávila Ramón'));
        $this->assertSame('nunez', BusquedaDePacientes::normalizar('Núñez'));
    }

    public function test_la_expresion_de_la_columna_saca_acentos_y_mayusculas(): void
    {
        $expresion = BusquedaDePacientes::columnaNormalizada('pacientes.apellidos');

        $this->assertStringStartsWith('LOWER(REPLACE(', $expresion);
        $this->assertStringEndsWith(')', $expresion);
        $this->assertStringContainsString('pacientes.apellidos', $expresion);
        // Las dos cajas de cada acento: `LOWER` de SQLite no baja `Á`.
        $this->assertStringContainsString("'á', 'a'", $expresion);
        $this->assertStringContainsString("'Á', 'a'", $expresion);
        $this->assertStringContainsString("'ñ', 'n'", $expresion);
    }

    public function test_en_mysql_la_columna_se_compara_tal_cual_porque_la_colacion_pliega_acentos(): void
    {
        // La cadena de `REPLACE` es para SQLite. En MySQL la colación `_ci` del legado ya compara sin
        // acentos ni mayúsculas, y encadenarla por columna y por palabra multiplica el costo del listado.
        $this->assertSame('pacientes.apellidos', BusquedaDePacientes::columnaNormalizada('pacientes.apellidos', 'mysql'));
        $this->assertStringContainsString('REPLACE', BusquedaDePacientes::columnaNormalizada('pacientes.apellidos', 'sqlite'));
    }

    public function test_el_numero_de_historia_se_busca_con_y_sin_los_ceros_a_la_izquierda(): void
    {
        $this->assertSame(['01001', '1001'], BusquedaDePacientes::formasDeNumero('01001'));
        $this->assertSame(['1001'], BusquedaDePacientes::formasDeNumero('1001'));
        $this->assertSame(['0'], BusquedaDePacientes::formasDeNumero('0'));
        $this->assertSame(['00001', '1'], BusquedaDePacientes::formasDeNumero('00001'));
    }

    public function test_una_historia_que_no_es_un_numero_se_busca_tal_cual(): void
    {
        // El legado tiene números escritos a mano (`S/H`): se buscan por texto, sin inventar un entero.
        $this->assertSame(['S/H'], BusquedaDePacientes::formasDeNumero('S/H'));
        $this->assertSame(['10A'], BusquedaDePacientes::formasDeNumero('10A'));
    }

    public function test_los_comodines_del_like_y_el_escape_se_antepone_con_signo_de_admiracion(): void
    {
        $this->assertSame('100!% real', BusquedaDePacientes::escaparLike('100% real'));
        $this->assertSame('an!_', BusquedaDePacientes::escaparLike('an_'));
        $this->assertSame('!!hola', BusquedaDePacientes::escaparLike('!hola'));
        // Sin comodines no cambia nada.
        $this->assertSame('reynoso', BusquedaDePacientes::escaparLike('reynoso'));
    }
}
