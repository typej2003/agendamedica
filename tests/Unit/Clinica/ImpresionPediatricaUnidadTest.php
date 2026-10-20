<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Impresion\DistribuidorDeDocumento;
use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\ItemImpreso;
use App\Clinica\Impresion\MedidasDeLaConsulta;
use App\Clinica\Impresion\MedidasDeLaMitad;
use App\Clinica\Impresion\MedidorDeTexto;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\PacienteImpreso;
use App\Clinica\Impresion\Vistas\HojasDeDocumentoVista;
use App\Clinica\Impresion\Vistas\HojasDeRecipeVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Models\Consulta;
use App\Models\RecipeFormato;
use PHPUnit\Framework\TestCase;

/**
 * **La cabecera de pediatría de los impresos** (2026-10-20), sin base de datos ni navegador: el bloque que imprime el
 * escritorio de pediatría —`SEXO`, `Rep.`, `Historia`, la edad compacta y, en la mitad de *Indicaciones* del récipe, las
 * medidas— y, sobre todo, que **fuera de pediatría la hoja no cambie**.
 *
 * Lo que se fija acá:
 *
 *  - las dos mitades del récipe llevan el sexo, el representante y la historia; **sólo la de indicaciones** lleva las
 *    medidas, y el récipe de la pareja no lleva nada de esto (el bloque es del paciente);
 *  - sin representante ni sexo no se inventa el dato ni queda el rótulo `Rep.:` colgando;
 *  - fuera de pediatría los campos salen vacíos y la edad sigue en años («21 años»), como siempre;
 *  - las medidas se formatean con su unidad y el `0` del legado **no es una medida**; la tensión es la primera de las
 *    tres que tenga valor;
 *  - la línea de la cabecera de pediatría **descuenta alto del cuerpo** en `MedidasDeLaMitad` (el reparto del récipe y de
 *    los documentos tiene que seguir entrando en la hoja).
 */
class ImpresionPediatricaUnidadTest extends TestCase
{
    // ------------------------------------------------------------------------------------------ Ayudas

    private function membrete(): Membrete
    {
        return new Membrete(
            'Dra. Ana Pérez',
            'Pediatría',
            'J-12345678-9',
            null,
            [],
            RecipeFormato::elementosPorDefecto(),
            'negro',
            null,
            null,
            'centro',
        );
    }

    /** La cabecera de un paciente de pediatría, como la arma `PacienteImpreso::datosPediatricos()`. */
    private function personaPediatrica(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Alexandra Tocuyo',
            'cedula' => 'V-14555666',
            'edad' => 21,
            'direccion' => 'Av. Bolívar, edificio Sol, Barquisimeto',
            'sexo' => 'F',
            'representante' => 'ELDRIS MENDOZA',
            'historia' => '00025',
            'edadCompacta' => '21A 10M 16D',
        ], $cambios);
    }

    /** @param array<int,array{etiqueta:string, valor:string}> $medidas */
    private function recipe(array $persona, array $medidas = [], string $tipo = DocumentoDeRecipe::PACIENTE): DocumentoDeRecipe
    {
        return new DocumentoDeRecipe(
            $tipo,
            $tipo === DocumentoDeRecipe::PAREJA ? 'Récipe de la pareja' : 'Récipe 1',
            $tipo === DocumentoDeRecipe::PAREJA ? null : 1,
            '2026-10-19',
            $persona,
            [new ItemImpreso('0001', 2, 'Una cápsula cada 6 horas.', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', null)],
            null,
            $medidas,
        );
    }

    /** @param array<string,mixed> $persona */
    private function documento(array $persona): DocumentoImpreso
    {
        return new DocumentoImpreso(
            DocumentoImpreso::CONSTANCIA,
            DocumentoImpreso::CONSTANCIA,
            'CONSTANCIA',
            'Constancia',
            '2026-10-19',
            $persona,
            [],
            DocumentoImpreso::parrafosDe('Acudió a control.'),
        );
    }

    /** @return array<int,array<string,mixed>> las mitades de la hoja del récipe */
    private function mitadesDelRecipe(bool $pediatria, ?DocumentoDeRecipe $documento = null): array
    {
        $opciones = OpcionesDeRecipe::porDefecto();

        return (new HojasDeRecipeVista(
            $this->membrete(),
            $opciones,
            [$documento ?? $this->recipe($this->personaPediatrica())],
            null,
            ['volver' => '/clinica/agenda', 'volverTexto' => 'Volver'],
            new OpcionesDeImpresionVista($opciones, '/clinica/recipes/opciones', '/clinica/consulta/1/recipes/1/imprimir'),
            $pediatria,
        ))->hojas()[0]['mitades'];
    }

    // ------------------------------------------------------------------ El récipe de pediatría

    public function test_la_cabecera_pediatrica_sale_en_las_dos_mitades_con_la_edad_compacta(): void
    {
        $mitades = $this->mitadesDelRecipe(true);

        $this->assertCount(2, $mitades);

        foreach ($mitades as $mitad) {
            $this->assertSame('F', $mitad['sexo']);
            $this->assertSame('ELDRIS MENDOZA', $mitad['representante']);
            $this->assertSame('00025', $mitad['historia']);
            // La edad va compacta, como el escritorio, y no en años.
            $this->assertSame('21A 10M 16D', $mitad['edad']);
        }
    }

    public function test_las_medidas_salen_solo_en_la_mitad_de_indicaciones(): void
    {
        $medidas = [['etiqueta' => 'PESO:', 'valor' => '20 Kg'], ['etiqueta' => 'T.A.:', 'valor' => '120/80']];
        $mitades = $this->mitadesDelRecipe(true, $this->recipe($this->personaPediatrica(), $medidas));

        $this->assertSame('rp', $mitades[0]['clase']);
        $this->assertSame([], $mitades[0]['medidas']);
        $this->assertSame('indicaciones', $mitades[1]['clase']);
        $this->assertSame($medidas, $mitades[1]['medidas']);
    }

    public function test_sin_representante_ni_sexo_la_cabecera_no_inventa_el_dato(): void
    {
        $persona = $this->personaPediatrica(['sexo' => null, 'representante' => null]);
        $mitades = $this->mitadesDelRecipe(true, $this->recipe($persona));

        $this->assertNull($mitades[0]['sexo']);
        $this->assertNull($mitades[0]['representante']);
        $this->assertSame('00025', $mitades[0]['historia']);
        // La línea que se mide (y que la vista pinta) no deja rótulos vacíos: sólo dice la historia.
        $this->assertSame('Historia: 00025', PacienteImpreso::lineaPediatrica($persona));
    }

    public function test_sin_sexo_representante_ni_historia_no_hay_linea_pediatrica(): void
    {
        $this->assertNull(PacienteImpreso::lineaPediatrica([]));
        $this->assertNull(PacienteImpreso::lineaPediatrica(['sexo' => null, 'representante' => '  ', 'historia' => null]));
        $this->assertSame('SEXO: F   Rep.: ELDRIS MENDOZA   Historia: 00025', PacienteImpreso::lineaPediatrica($this->personaPediatrica()));
    }

    public function test_el_recipe_de_la_pareja_no_lleva_el_bloque_pediatrico(): void
    {
        $persona = [
            'nombre' => 'Mariana Rojas', 'cedula' => '9876543', 'edad' => null, 'direccion' => null,
            'sexo' => 'F', 'representante' => 'ELDRIS MENDOZA', 'historia' => '00025', 'edadCompacta' => '21A 10M 16D',
        ];

        $mitades = $this->mitadesDelRecipe(true, $this->recipe($persona, [], DocumentoDeRecipe::PAREJA));

        foreach ($mitades as $mitad) {
            $this->assertNull($mitad['sexo']);
            $this->assertNull($mitad['representante']);
            $this->assertNull($mitad['historia']);
            $this->assertNull($mitad['edad']);
            $this->assertSame([], $mitad['medidas']);
        }
    }

    public function test_fuera_de_pediatria_la_cabecera_es_la_de_siempre(): void
    {
        $persona = $this->personaPediatrica();

        foreach ($this->mitadesDelRecipe(false, $this->recipe($persona, [['etiqueta' => 'PESO:', 'valor' => '20 Kg']])) as $mitad) {
            $this->assertNull($mitad['sexo']);
            $this->assertNull($mitad['representante']);
            $this->assertNull($mitad['historia']);
            // La edad vuelve a los años y las medidas no salen aunque el documento las traiga.
            $this->assertSame('21 años', $mitad['edad']);
            $this->assertSame([], $mitad['medidas']);
        }
    }

    // ------------------------------------------------------------------ El documento de pediatría

    /** @return array<int,array<string,mixed>> */
    private function mitadesDelDocumento(bool $pediatria, ?array $persona = null): array
    {
        $hojas = (new HojasDeDocumentoVista($this->membrete(), [$this->documento($persona ?? $this->personaPediatrica())], new DistribuidorDeDocumento(), $pediatria))->hojas();

        return $hojas[0]['mitades'];
    }

    public function test_el_documento_pediatrico_lleva_la_misma_cabecera(): void
    {
        $mitad = $this->mitadesDelDocumento(true)[0];

        $this->assertSame('F', $mitad['sexo']);
        $this->assertSame('ELDRIS MENDOZA', $mitad['representante']);
        $this->assertSame('00025', $mitad['historia']);
        $this->assertSame('21A 10M 16D', $mitad['edad']);
    }

    public function test_el_documento_de_ginecologia_no_cambia(): void
    {
        $mitad = $this->mitadesDelDocumento(false)[0];

        $this->assertNull($mitad['sexo']);
        $this->assertNull($mitad['representante']);
        $this->assertNull($mitad['historia']);
        $this->assertSame('21 años', $mitad['edad']);
    }

    // ------------------------------------------------------------------ Las medidas del escritorio

    public function test_las_medidas_salen_con_su_unidad_y_solo_las_que_existen(): void
    {
        $consulta = (new Consulta())->forceFill([
            'peso' => 20.0,
            'talla' => 100.0,
            'circcefalica' => 48.5,
            'tasentado' => '120/80',
        ]);

        $this->assertSame([
            ['etiqueta' => 'PESO:', 'valor' => '20 Kg'],
            ['etiqueta' => 'TALLA:', 'valor' => '100 Cm'],
            ['etiqueta' => 'C. CEFÁLICA:', 'valor' => '48.5 Cm'],
            ['etiqueta' => 'T.A.:', 'valor' => '120/80'],
        ], MedidasDeLaConsulta::de($consulta));

        $this->assertSame(
            'PESO: 20 Kg   TALLA: 100 Cm   C. CEFÁLICA: 48.5 Cm   T.A.: 120/80',
            MedidasDeLaConsulta::linea(MedidasDeLaConsulta::de($consulta)),
        );
    }

    public function test_el_cero_del_legado_no_es_una_medida_y_sin_ninguna_no_hay_linea(): void
    {
        $vacia = (new Consulta())->forceFill(['peso' => 0.0, 'talla' => 0.0, 'circcefalica' => 0.0, 'tasentado' => '', 'taacostado' => null]);

        $this->assertSame([], MedidasDeLaConsulta::de($vacia));
        $this->assertNull(MedidasDeLaConsulta::linea([]));

        // La tensión es la primera de las tres que tenga valor: sentado, acostado y pie, en ese orden.
        $acostado = (new Consulta())->forceFill(['taacostado' => '110/70', 'tapie' => '100/60']);
        $this->assertSame([['etiqueta' => 'T.A.:', 'valor' => '110/70']], MedidasDeLaConsulta::de($acostado));

        // Sólo el peso: la línea sale con lo que hay.
        $soloPeso = (new Consulta())->forceFill(['peso' => 8.5]);
        $this->assertSame('PESO: 8.5 Kg', MedidasDeLaConsulta::linea(MedidasDeLaConsulta::de($soloPeso)));
    }

    // ------------------------------------------------------------------ El alto de la cabecera

    public function test_la_cabecera_pediatrica_descuenta_alto_del_cuerpo(): void
    {
        $medidas = new MedidasDeLaMitad();
        $membrete = $this->membrete();
        $linea = PacienteImpreso::lineaPediatrica($this->personaPediatrica());
        $lineaDeLasMedidas = MedidasDeLaConsulta::linea([['etiqueta' => 'PESO:', 'valor' => '20 Kg']]);

        $sinPediatria = $medidas->altoDelCuerpo($membrete, 'Alexandra Tocuyo', null);
        $conLaCabecera = $medidas->altoDelCuerpo($membrete, 'Alexandra Tocuyo', null, [$linea]);
        $conTodo = $medidas->altoDelCuerpo($membrete, 'Alexandra Tocuyo', null, [$linea, $lineaDeLasMedidas]);

        // Una línea de 9 pt por la cabecera y otra por las medidas: el cuerpo tiene que achicarse en esa medida, o la
        // lista de medicamentos se sale de la hoja. Una línea extra es su renglón (9 pt por la interlínea) más el milímetro
        // de aire de `.recipe-dato-linea`.
        $lineaExtra = 9.0 * MedidasDeLaMitad::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO + 1.0;

        $this->assertLessThan($sinPediatria, $conLaCabecera);
        $this->assertLessThan($conLaCabecera, $conTodo);
        $this->assertEqualsWithDelta($lineaExtra, $sinPediatria - $conLaCabecera, 0.01);
        $this->assertEqualsWithDelta($lineaExtra, $conLaCabecera - $conTodo, 0.01);
    }
}
