<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **O-20: los siete ecos ofrecen imprimir, y en hoja completa** (con O-26). Recorre **tipo por tipo** del estudio
 * ecográfico —pélvico, obstétrico del 1.º, del 2.º y del 3.er trimestre, Doppler, mamario, abdominal y cardiograma
 * fetal— y comprueba, para cada uno:
 *
 *  1. **con datos**, la sección ofrece el botón «Imprimir» que guarda y después imprime (`name="imprimir"` con la clave de
 *     su documento) y la hoja responde **200** con su título;
 *  2. **sin datos**, la hoja **no** se arma —no está entre las que la consulta ofrece— y pedirla igual muestra «Guarda
 *     los datos del estudio para poder imprimir» en vez de un 404. El **botón** sí está: imprimir guarda lo escrito, y
 *     es lo que un estudio recién cargado necesita para salir sin apretar «Guardar» antes (O-16);
 *  3. la hoja y su **formato en blanco** —donde el escritorio lo tiene— salen en **hoja completa vertical** (O-26), que es
 *     lo que declara `ModoDeLaHoja`.
 *
 * El 1.er trimestre es el que la revisión marcó como «no ofrece imprimir»: quedó cubierto acá, con su hoja y su botón.
 * El eco pélvico, el mamario, el abdominal y el ecocardiograma fetal **no** tienen formato en blanco en el escritorio
 * (su panel de formatos no los lista): por eso no están en `FormatosEnBlanco` y no se piden acá.
 */
class ImpresionDeLosSieteEcosWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    /**
     * Los siete tipos del ítem 5 con lo mínimo para que su estudio sea un documento: la tabla, sus columnas de clave y
     * una columna con dato (la que hace que el estudio diga algo).
     *
     * @return array<string,array{tipo:string, documento:string, tabla:string, claves:array<string,mixed>, dato:array<string,mixed>, titulo:string}>
     */
    public static function ecos(): array
    {
        return [
            'pélvico' => [
                'tipo' => TiposDeEstudio::GINECOLOGICO,
                'documento' => DocumentoImpreso::ECO_PELVICO,
                'tabla' => 'eco_pelvico',
                'claves' => ['numhistoria' => 1001, 'nroconsulta' => 1],
                'dato' => ['cuerpo' => 62.5, 'sugerencias' => 'Control en 6 meses.'],
                'titulo' => 'INFORME ECOSONOGRÁFICO GINECOLÓGICO',
            ],
            'obstétrico del 1.º' => [
                'tipo' => TiposDeEstudio::OBSTETRICO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO,
                'tabla' => 'eco_obstetrico',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['lcc' => '25'],
                'titulo' => 'ECOSONOGRAFÍA OBSTÉTRICA',
            ],
            'obstétrico del 2.º' => [
                'tipo' => TiposDeEstudio::SEGUNDO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
                'tabla' => 'eco_obstetrico_tercer_2',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['bio_ca_bp' => '45'],
                'titulo' => 'ECOSONOGRAFÍA OBSTÉTRICA',
            ],
            'obstétrico del 3.er' => [
                'tipo' => TiposDeEstudio::TERCERO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
                'tabla' => 'eco_obstetrico_tercer',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['bio_ca_bp' => '45'],
                'titulo' => 'ECOSONOGRAFÍA OBSTÉTRICA',
            ],
            'Doppler' => [
                'tipo' => TiposDeEstudio::DOPPLER,
                'documento' => DocumentoImpreso::ECO_DOPPLER,
                'tabla' => 'eco_doppler',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['arteria_uraina_menor' => '0.85'],
                'titulo' => 'ECO DOPPLER PERINATAL',
            ],
            'mamario' => [
                'tipo' => TiposDeEstudio::MAMARIO,
                'documento' => DocumentoImpreso::ECO_MAMARIO,
                'tabla' => 'ultra_mama',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['mama_derecha' => 'Sin hallazgos.'],
                'titulo' => 'ULTRASONIDO DE MAMAS',
            ],
            'abdominal' => [
                'tipo' => TiposDeEstudio::ABDOMINAL,
                'documento' => DocumentoImpreso::ECO_ABDOMINAL,
                'tabla' => 'ultra_abdominal',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['higado' => 'De tamaño normal.'],
                'titulo' => 'ULTRASONIDO ABDOMINAL',
            ],
            'cardiograma fetal' => [
                'tipo' => TiposDeEstudio::FETAL,
                'documento' => DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
                'tabla' => 'ecocadiograma_fetal',
                'claves' => ['historia' => 1001, 'consulta' => 1],
                'dato' => ['biome_dbp' => 25],
                'titulo' => 'ECOCARDIOGRAMA FETAL',
            ],
        ];
    }

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        return [$user, $medico, $gineco, $consulta];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    private function seccion(Consulta $consulta, string $tipo): string
    {
        return route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => $tipo,
        ]);
    }

    /** @param  array<string,mixed>  $datos */
    private function estudio(Medico $medico, string $tabla, array $datos): void
    {
        DB::table($tabla)->insert(array_merge([
            'reg_medico' => $medico->reg_medico,
            'fecha' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ], $datos));
    }

    // ==================================================================== Con datos: botón y hoja

    /**
     * @dataProvider ecos
     */
    public function test_con_datos_cada_eco_ofrece_el_boton_y_su_hoja_responde_200(
        string $tipo,
        string $documento,
        string $tabla,
        array $claves,
        array $dato,
        string $titulo,
    ): void {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->estudio($medico, $tabla, array_merge($claves, $dato));

        // La hoja: 200 y su título (el del DataWindow del escritorio, con el trimestre si lo lleva).
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $documento))
            ->assertOk()
            ->assertSee($titulo);

        // Y la sección ofrece el botón de imprimir, que guarda antes (O-16): un `submit` con la clave del documento.
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, $tipo))
            ->assertOk()
            ->assertSee('name="imprimir" value="' . $documento . '"', false);
    }

    // =================================================================== Sin datos: no se ofrece

    /**
     * @dataProvider ecos
     */
    public function test_sin_datos_cada_eco_no_ofrece_la_hoja_pero_si_el_boton_que_guarda_e_imprime(
        string $tipo,
        string $documento,
        string $tabla,
        array $claves,
        array $dato,
        string $titulo,
    ): void {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        // Una fila que sólo trae su ficha (la fecha): el escritorio la deja y no es el informe de nadie.
        $this->estudio($medico, $tabla, $claves);

        $clavesOfrecidas = array_column(
            app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]),
            'clave',
        );
        $this->assertNotContains($documento, $clavesOfrecidas, 'Sin datos del estudio no se ofrece la hoja');

        // El **botón** sí está (O-16: imprime lo que hay en pantalla, guardándolo antes); lo que no hay es hoja que
        // imprimir, y la hoja pedida lo dice en vez de dar 404.
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, $tipo))
            ->assertOk()
            ->assertSee('name="imprimir" value="' . $documento . '"', false);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $documento))
            ->assertOk()
            ->assertSee('Guarda los datos del estudio para poder imprimir.');
    }

    // ================================================================ La hoja completa (O-26)

    /**
     * @dataProvider ecos
     */
    public function test_el_modo_de_la_hoja_de_cada_eco_es_completa_y_vertical(
        string $tipo,
        string $documento,
        string $tabla,
        array $claves,
        array $dato,
        string $titulo,
    ): void {
        $this->assertSame(ModoDeLaHoja::COMPLETA, ModoDeLaHoja::de($documento));
        $this->assertTrue(ModoDeLaHoja::esCompleta($documento));

        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->estudio($medico, $tabla, array_merge($claves, $dato));

        // El informe de una eco es un formulario lleno a **página completa**: la hoja vertical del motor (`informe-hoja`)
        // y no una mitad del A4 horizontal.
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $documento))
            ->assertOk()
            ->assertSee('class="informe-hoja"', false);
    }

    public function test_los_formatos_en_blanco_de_los_ecos_que_el_escritorio_tiene_salen_en_hoja_completa(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        // Los cuatro del catálogo (el pélvico, el mamario, el abdominal y el fetal no están: el escritorio no los tiene).
        foreach ([
            DocumentoImpreso::ECO_OBSTETRICO => 'ECOSONOGRAFÍA OBSTÉTRICA · PRIMER TRIMESTRE',
            DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO => 'ECOSONOGRAFÍA OBSTÉTRICA · SEGUNDO TRIMESTRE',
            DocumentoImpreso::ECO_OBSTETRICO_TERCERO => 'ECOSONOGRAFÍA OBSTÉTRICA · TERCER TRIMESTRE',
            DocumentoImpreso::ECO_DOPPLER => 'ECO DOPPLER PERINATAL',
        ] as $formato => $titulo) {
            $this->como($user, $medico, $gineco)
                ->get(route('clinica.formato.en-blanco', $formato))
                ->assertOk()
                ->assertSee($titulo)
                ->assertSee('recipe-hoja-completa', false);
        }
    }
}
