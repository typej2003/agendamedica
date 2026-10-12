<?php

namespace App\Clinica\Verificacion;

use App\Clinica\Consulta\RecipeDeLaPareja;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Impresion\ItemImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeVerificacion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Lee el récipe detrás de un QR para la página pública** (WEB-5.6). Es la única que decide qué se puede decir en público.
 *
 * ## Qué sale y qué no
 *
 * Sale: el estado, la fecha de emisión, la vigencia, el médico (nombre con prefijo, especialidad, RIF, registro profesional y
 * las líneas de pie del membrete —consultorio y ciudad—, respetando lo que el médico ocultó en sus hojas), el número de récipe y
 * los medicamentos con su cantidad, con el nombre que lleva la hoja impresa (comercial o genérico, según la opción del médico).
 * **No sale nada del paciente ni las indicaciones**: ni siquiera se leen (`RecipesParaImprimir::documento` carga al paciente;
 * esta clase no lo usa).
 *
 * ## Siempre el récipe vivo
 *
 * No hay copia: se lee la consulta **ahora**. Si el médico corrigió el récipe, se ve lo nuevo; si lo eliminó, el estado es
 * `ELIMINADO`; si anuló el QR, `ANULADO` (y no se muestra el contenido). La **vigencia** son los días de `recipe_detalle.fe_vence`
 * sobre `fe_emision`; si el récipe no tiene cabecera (filas que sólo trae el escritorio) y para el de la pareja, **30 días sobre la
 * fecha del récipe** (o, a falta de ella, la de la consulta). Vigente hasta ese día inclusive, por el día **del consultorio**.
 *
 * Es de sólo lectura y no loguea el token.
 */
final class LectorDeVerificacion
{
    public function __construct(
        private RecipesDeLaConsulta $recipes,
        private RecipeDeLaPareja $pareja,
        private MembreteDelMedico $membretes,
    ) {
    }

    /** El récipe de ese token; un token mal formado o inexistente da la **misma** respuesta (`NO_ENCONTRADO`). */
    public function porToken(string $token): RecetaVerificada
    {
        if ($token === UrlDeVerificacion::TOKEN_DE_EJEMPLO) {
            return RecetaVerificada::ejemplo();
        }

        if (! CredencialesDeVerificacion::tieneFormaDeToken($token)) {
            return RecetaVerificada::noEncontrado();
        }

        $verificacion = RecipeVerificacion::query()->where('token', $token)->first();

        return $verificacion === null ? RecetaVerificada::noEncontrado() : $this->leer($verificacion);
    }

    /**
     * El token del código corto que se tipeó, o `null` si no corresponde a ningún récipe. El código se normaliza (mayúsculas, sin
     * espacios ni guiones).
     */
    public function tokenDelCodigo(string $texto): ?string
    {
        $codigo = CredencialesDeVerificacion::normalizarCodigo($texto);

        if (! CredencialesDeVerificacion::tieneFormaDeCodigo($codigo)) {
            return null;
        }

        return RecipeVerificacion::query()->where('codigo', $codigo)->value('token');
    }

    private function leer(RecipeVerificacion $verificacion): RecetaVerificada
    {
        $medico = $this->medico($verificacion);
        $membrete = $medico === null ? null : $this->membretes->de($medico);
        $datosDelMedico = $membrete === null ? null : $this->datosDelMedico($medico, $membrete);
        $esDeLaPareja = $verificacion->esDeLaPareja();
        $numero = $esDeLaPareja ? null : (int) $verificacion->recipe;

        if ($verificacion->estaAnulada()) {
            return new RecetaVerificada(
                EstadoDeLaVerificacion::ANULADO,
                $esDeLaPareja,
                $numero,
                anuladoEn: $verificacion->revocado_en->toDateString(),
                medico: $datosDelMedico,
            );
        }

        $viva = $esDeLaPareja ? $this->laDeLaPareja($verificacion) : $this->laDelPaciente($verificacion);

        if ($viva === null) {
            return new RecetaVerificada(EstadoDeLaVerificacion::ELIMINADO, $esDeLaPareja, $numero, medico: $datosDelMedico);
        }

        $generico = $medico !== null && $this->membretes->opciones($medico)->esGenerico();
        $emision = $viva['emision'];
        $vence = $emision === null ? null : Carbon::parse($emision)->addDays($viva['vigencia'])->toDateString();
        $vencido = $vence !== null && $vence < FechaDelConsultorio::hoy()->toDateString();

        return new RecetaVerificada(
            $vencido ? EstadoDeLaVerificacion::VENCIDO : EstadoDeLaVerificacion::VIGENTE,
            $esDeLaPareja,
            $numero,
            $emision,
            $vence,
            $viva['vigencia'],
            medico: $datosDelMedico,
            medicamentos: $viva['items']->map(fn (object $item) => [
                'cantidad' => $item->cantidad === null ? '—' : (string) $item->cantidad,
                'nombre' => ItemImpreso::deLaConsulta($item)->nombre($generico),
            ])->values()->all(),
        );
    }

    /** @return array{emision:?string, vigencia:int, items:Collection}|null  `null` si el récipe ya no existe o está vacío */
    private function laDelPaciente(RecipeVerificacion $verificacion): ?array
    {
        $recipe = $this->recipes
            ->deLaConsulta($verificacion->reg_medico, $verificacion->nrohistoria, $verificacion->nroconsulta)
            ->first(fn (object $recipe) => $recipe->numero === (int) $verificacion->recipe);

        if ($recipe === null || $recipe->items->isEmpty()) {
            return null;
        }

        return [
            'emision' => $recipe->fecha ?? $this->fechaDeLaConsulta($verificacion),
            'vigencia' => (int) $recipe->vigencia,
            'items' => $recipe->items,
        ];
    }

    /** @return array{emision:?string, vigencia:int, items:Collection}|null */
    private function laDeLaPareja(RecipeVerificacion $verificacion): ?array
    {
        $pareja = $this->pareja->de($verificacion->reg_medico, $verificacion->nrohistoria, $verificacion->nroconsulta);

        if ($pareja === null || $pareja->items->isEmpty()) {
            return null;
        }

        // `recipes_pareja` no tiene cabecera ni fecha de emisión: la del récipe es la de la consulta, con la vigencia estándar.
        return [
            'emision' => $this->fechaDeLaConsulta($verificacion),
            'vigencia' => RecipesDeLaConsulta::VIGENCIA_EN_DIAS,
            'items' => $pareja->items,
        ];
    }

    private function fechaDeLaConsulta(RecipeVerificacion $verificacion): ?string
    {
        $fecha = DB::table('consultas')
            ->where('reg_medico', $verificacion->reg_medico)
            ->where('numhistoria', $verificacion->nrohistoria)
            ->where('nroconsulta', $verificacion->nroconsulta)
            ->value('fecha');

        return $fecha === null ? null : Carbon::parse($fecha)->toDateString();
    }

    /** El médico que imprimió; si no consta, el dueño del `reg_medico`. */
    private function medico(RecipeVerificacion $verificacion): ?Medico
    {
        $medico = $verificacion->medico_id === null ? null : Medico::find($verificacion->medico_id);

        if ($medico !== null) {
            return $medico;
        }

        $id = MedicoRegistro::query()->where('reg_medico', $verificacion->reg_medico)->whereNotNull('medico_id')->orderBy('id')->value('medico_id');

        return $id === null ? Medico::where('reg_medico', $verificacion->reg_medico)->first() : Medico::find($id);
    }

    /**
     * @return array{nombre:string, especialidad:?string, rif:?string, registro:?string, lugar:array<int,string>}
     */
    private function datosDelMedico(Medico $medico, Membrete $membrete): array
    {
        $visible = fn (string $elemento): bool => ($membrete->estiloDe($elemento)['visible'] ?? true) !== false;

        return [
            'nombre' => $medico->nombreMostrar,
            'especialidad' => $visible('especialidad') ? $membrete->especialidad : null,
            'rif' => $visible('rif') ? $membrete->rif : null,
            'registro' => CamposDePaciente::texto($medico->license_number),
            'lugar' => $membrete->lineasDelPie(),
        ];
    }
}
