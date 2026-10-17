<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\TipoDeAntecedentes;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * **Qué tipo de antecedente muestra la sección** (A-06): el `?tipo=` de los chips de la pantalla.
 *
 * Es una clase sin estado y sin base de datos —recibe el catálogo de tipos y lo que el paciente tiene ya
 * leídos—, así que se prueba sin Laravel. Lo que fija: lo que pidió el médico manda si existe; al entrar gana
 * el primer tipo **con antecedentes del paciente**; sin antecedentes, el primero del catálogo; y las filas sin
 * tipo tienen su propio grupo.
 */
class TipoDeAntecedentesTest extends TestCase
{
    private const TIPOS = [
        ['codigo' => '01', 'nombre' => 'PERSONAL'],
        ['codigo' => '02', 'nombre' => 'FAMILIAR'],
        ['codigo' => '04', 'nombre' => 'OBSTETRICOS'],
        ['codigo' => '07', 'nombre' => 'VARIOS'],
    ];

    private function antecedente(?string $tipoCodigo): object
    {
        return (object) ['id' => 1, 'codigo' => '0001', 'tipoCodigo' => $tipoCodigo];
    }

    public function test_manda_el_tipo_que_pidio_el_medico_si_existe(): void
    {
        $this->assertSame('04', TipoDeAntecedentes::elegido('04', new Collection([$this->antecedente('01')]), self::TIPOS));
    }

    public function test_al_entrar_gana_el_primero_del_catalogo_con_antecedentes_del_paciente(): void
    {
        // El paciente tiene 07 y 02: gana 02 porque es el primero **en el orden del catálogo**, no en el de la
        // carga de las filas.
        $paciente = new Collection([$this->antecedente('07'), $this->antecedente('02')]);

        $this->assertSame('02', TipoDeAntecedentes::elegido('', $paciente, self::TIPOS));
    }

    public function test_sin_antecedentes_el_tipo_por_defecto_es_el_primero_del_catalogo(): void
    {
        $this->assertSame('01', TipoDeAntecedentes::elegido('', new Collection(), self::TIPOS));
    }

    public function test_un_tipo_que_no_esta_en_el_catalogo_se_ignora(): void
    {
        $this->assertSame('01', TipoDeAntecedentes::elegido('99', new Collection(), self::TIPOS));
        $this->assertSame('', TipoDeAntecedentes::elegido('99', new Collection(), []));
    }

    public function test_las_filas_sin_tipo_tienen_su_propio_grupo(): void
    {
        // Es lo único que el paciente tiene: la pantalla abre en «Sin clasificar» para que no queden escondidas.
        $paciente = new Collection([$this->antecedente(null)]);

        $this->assertSame(TipoDeAntecedentes::SIN_CLASIFICAR, TipoDeAntecedentes::elegido('', $paciente, self::TIPOS));
    }

    public function test_el_chip_sin_clasificar_no_existe_si_no_hay_filas_de_ese_grupo(): void
    {
        $paciente = new Collection([$this->antecedente('02')]);

        $this->assertSame('02', TipoDeAntecedentes::elegido(TipoDeAntecedentes::SIN_CLASIFICAR, $paciente, self::TIPOS));
        $this->assertFalse(TipoDeAntecedentes::haySinClasificar($paciente, array_column(self::TIPOS, 'codigo')));
    }

    public function test_un_codigo_fuera_del_catalogo_cuenta_como_sin_clasificar(): void
    {
        $paciente = new Collection([$this->antecedente('ZZ')]);

        $this->assertTrue(TipoDeAntecedentes::haySinClasificar($paciente, array_column(self::TIPOS, 'codigo')));
        $this->assertSame(TipoDeAntecedentes::SIN_CLASIFICAR, TipoDeAntecedentes::elegido('', $paciente, self::TIPOS));
    }
}
