<?php

namespace App\Clinica\Historia;

use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de la **historia clínica de un paciente** (WEB-3.5): sus consultas con las secciones que el
 * legado muestra en el panel de la consulta, sus antecedentes y sus documentos.
 *
 * Todo se filtra por el `reg_medico` del contexto **y** por el `numhistoria` del pivote
 * (`medico_pacientes`), que es lo que dice de quién es el paciente: el número de historia es único por
 * médico, no global (`Docs/Wiki/07-modelo-de-datos.md`), así que buscar sólo por el número traería la
 * historia de otro consultorio que use el mismo correlativo.
 *
 * Es **sólo lectura**: la escritura de la consulta es F4 y la de los documentos, F5. Acá no se crea nada.
 *
 * El número llega como el pivote lo guarda (texto, con ceros a la izquierda, `''` o basura) y se convierte
 * con `CamposDePaciente::historiaNumerica`: `consultas.numhistoria` es entero y castear a ciegas una
 * historia escrita a mano (`S/H`) la volvería `0` y traería las consultas del paciente de la historia `0`.
 */
final class HistoriaDelPaciente
{
    /**
     * El código de `antece_paciente.tipo2` que el legado lee como la marca de **alerta** del antecedente.
     *
     * Es el mismo que declara la casilla del DataWindow del paciente —`tipo2` con
     * `checkbox.text="Alerta"`, `checkbox.on="A"` y `checkbox.off="B"` en
     * `d_antecedentes_consulta_bueno.srd`— y el que la sección de antecedentes de la consulta escribe y
     * borra (WEB-4.4); el mapa completo, con sus citas, está en `App\Clinica\Consulta\AntecedentesDelPaciente`.
     *
     * Lo que **no** se interpreta es cualquier otro valor: la `B` de la casilla apagada y el `null` de las
     * filas que inserta el botón *Pasar* del escritorio (`w_consulta_principal.srw:7754-7764`) se muestran
     * **sin marca**, en vez de adjudicarles un significado que la fuente no dice (misma vara que el
     * `codeparentesco` de WEB-3.4).
     */
    public const TIPO2_ALERTA = 'A';

    /**
     * Las consultas de la historia, **la más reciente primero**, cada una con sus motivos y sus
     * diagnósticos ya resueltos por nombre.
     *
     * El legado no marca las consultas con `eliminado` en las ventanas que las listan (barrido de
     * `fuentes/ventana_consulta/`, 2026-10-10: `eliminado` sólo aparece en INSERTs), así que acá se listan
     * igual que en la ficha del paciente: sin filtrar, que es lo que ya hace `PacientesDelConsultorio`.
     *
     * @return Collection<int, object>
     */
    public function consultas(string $regMedico, ?string $numhistoria): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        $motivos = $this->motivosPorConsulta($regMedico, $numero);
        $diagnosticos = $this->diagnosticosPorConsulta($regMedico, $numero);

        return DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderByDesc('fecha')
            ->orderByDesc('nroconsulta')
            ->get([
                'id',
                'nroconsulta',
                'fecha',
                'enfermedadactual',
                'evolucion',
                'resultadoexamencomp',
                // Las seis secciones de examen que imprime el resumen del legado
                // (`d_historia_clinica_resumen.srd`, band=detail): son las que la hoja "RESUMEN DE
                // HISTORIA CLINICA" lleva por consulta.
                'faringe',
                'nariz',
                'oido',
                'laringe',
                'cuello',
                'otros',
            ])
            ->map(function ($consulta) use ($motivos, $diagnosticos) {
                $nroconsulta = (int) $consulta->nroconsulta;

                $consulta->motivos = $motivos[$nroconsulta] ?? [];
                $consulta->diagnosticos = $diagnosticos[$nroconsulta] ?? [];

                return $consulta;
            });
    }

    /**
     * Los antecedentes del paciente, con el nombre del antecedente y el de su tipo ya resueltos por los
     * catálogos del médico.
     *
     * Los catálogos se buscan primero dentro del mismo `reg_medico` y, si no están ahí, por código en
     * cualquiera: los datos del legado traen `reg_medico` inconsistentes en los catálogos (es la misma
     * decisión que `ReportesRepository::_Catalogo` del móvil). Lo que no se puede resolver se devuelve
     * **tal cual** (el código), nunca traducido a un nombre inventado.
     *
     * @return Collection<int, object>
     */
    public function antecedentes(string $regMedico, ?string $numhistoria): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        $catalogo = $this->catalogoDeAntecedentes();
        $tipos = $this->tiposDeAntecedente();

        return DB::table('antece_paciente')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            // Ordenados por tipo para que el agrupado de la pantalla no cambie de orden entre corridas.
            ->orderBy('tipo')
            ->orderBy('codeantecedente')
            ->get(['id', 'codeantecedente', 'detalles', 'descripcion', 'tipo', 'tipo2'])
            ->map(function ($antecedente) use ($catalogo, $tipos, $regMedico) {
                $codigo = CamposDePaciente::texto($antecedente->codeantecedente);
                $delCatalogo = $codigo === null ? null : ($catalogo[$this->claveDeCatalogo($regMedico, $codigo)] ?? $catalogo['|' . $codigo] ?? null);
                $tipoCodigo = CamposDePaciente::texto($antecedente->tipo) ?? CamposDePaciente::texto($delCatalogo->codtipo ?? null);

                $antecedente->nombre = CamposDePaciente::texto($delCatalogo->descripcion ?? null)
                    ?? CamposDePaciente::texto($antecedente->descripcion)
                    ?? $codigo;
                $antecedente->tipoCodigo = $tipoCodigo;
                $antecedente->tipoNombre = $tipoCodigo === null
                    ? null
                    : ($tipos[$tipoCodigo] ?? null);
                $antecedente->detalle = CamposDePaciente::texto($antecedente->detalles);
                // Sólo el `'A'` que lee la fuente es alerta: el resto de los valores no se interpreta.
                $antecedente->alerta = CamposDePaciente::texto($antecedente->tipo2) === self::TIPO2_ALERTA;

                return $antecedente;
            });
    }

    /**
     * Los **documentos** de la historia: constancia, reposo, referencia, informe y récipes.
     *
     * Se arma con los mismos criterios con los que el móvil decide qué es un documento de verdad
     * (`DoctorisimoMobile/lib/features/reportes/`, `ReportesRepository::armar` / `documento.dart`), porque
     * al abrir una consulta el legado **crea plantillas vacías** que no son documentos de nadie. La regla vive
     * en `QueEsUnDocumento` (WEB-5.3a) y es la misma que usan la sección de solicitudes de la consulta y la
     * impresión:
     *
     *  - **Reposo**: sólo si tiene más de un día **o** una observación.
     *  - **Informe**: sólo si su texto no está vacío ni es `'.'`.
     *  - **Constancia**: existe para **toda** consulta (su texto es fijo), así que acá sólo se lista la que
     *    trae algo propio —observación o título—; una constancia vacía no dice nada que la consulta no
     *    diga y llenaría la lista con una fila por consulta.
     *  - **Referencia** y **récipes**: se listan todos (puede haber varias por consulta).
     *
     * La fecha de cada documento es la suya cuando la tiene (el `fdesde` del reposo, el `fe_cha` del
     * informe) y, si no, la de su consulta. El texto viene **sin recortar**: el recorte para el listado lo
     * hace el ViewModel, y la impresión muestra el texto completo.
     *
     * @param  Collection<int, object>  $consultas  lo que devolvió `consultas()`, para la fecha de cada una
     * @return Collection<int, array{tipo:string, icono:string, consulta:?int, fecha:?string, resumen:?string, recipe:?int, clave:?string}>
     */
    public function documentos(string $regMedico, ?string $numhistoria, Collection $consultas): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        $fechaDeLaConsulta = $consultas->mapWithKeys(
            fn ($consulta) => [(int) $consulta->nroconsulta => $consulta->fecha]
        );

        $documentos = collect();

        foreach ($this->constancias($regMedico, $numero) as $constancia) {
            $observacion = CamposDePaciente::texto($constancia->observacion01)
                ?? CamposDePaciente::texto($constancia->observacion)
                ?? CamposDePaciente::texto($constancia->titulo);

            // La constancia vacía que el legado crea en cada consulta no es un documento de nadie: su
            // texto es fijo y lo arma la consulta. Sólo se lista la que trae algo propio.
            if (! QueEsUnDocumento::constancia($constancia->observacion01, $constancia->observacion, $constancia->titulo)) {
                continue;
            }

            $documentos->push($this->documento(
                'Constancia',
                'bi-file-earmark-medical',
                (int) $constancia->numconsulta,
                $fechaDeLaConsulta,
                $observacion,
                null,
                null,
                'constancia',
            ));
        }

        foreach ($this->reposos($regMedico, $numero) as $reposo) {
            $dias = $reposo->numdias === null ? null : (int) $reposo->numdias;
            $observacion = CamposDePaciente::texto($reposo->obser_reposo);

            if (! QueEsUnDocumento::reposo($dias, $observacion)) {
                continue;
            }

            $documentos->push($this->documento(
                'Reposo',
                'bi-bandaid',
                (int) $reposo->nroconsulta,
                $fechaDeLaConsulta,
                trim(collect([
                    $dias === null ? 'Reposo médico' : $dias . ($dias === 1 ? ' día' : ' días'),
                    $observacion,
                ])->filter()->implode(' — ')) ?: null,
                CamposDePaciente::texto($reposo->fdesde),
                null,
                'reposo',
            ));
        }

        $nombresDeDoctores = $this->nombresDeDoctores();

        foreach ($this->referencias($regMedico, $numero) as $referencia) {
            $motivo = CamposDePaciente::texto($referencia->referencia);
            $medico = $nombresDeDoctores[$this->claveDeCatalogo($regMedico, (string) $referencia->ceduladoctor)] ?? null;

            $documentos->push($this->documento(
                'Referencia',
                'bi-person-up',
                (int) $referencia->nroconsulta,
                $fechaDeLaConsulta,
                trim(collect([
                    $medico === null ? null : 'Referido a ' . $medico,
                    $motivo,
                ])->filter()->implode(' — ')) ?: null,
                null,
                null,
                'referencia-' . (int) $referencia->ceduladoctor,
            ));
        }

        foreach ($this->informes($regMedico, $numero) as $informe) {
            $descripcion = CamposDePaciente::texto($informe->descripcion);

            if (! QueEsUnDocumento::informe($descripcion)) {
                continue;
            }

            $documentos->push($this->documento(
                'Informe médico',
                'bi-file-earmark-text',
                (int) $informe->nroconsulta,
                $fechaDeLaConsulta,
                $descripcion,
                CamposDePaciente::texto($informe->fe_cha),
                null,
                'informe',
            ));
        }

        foreach ($this->recipes($regMedico, $numero) as $recipe) {
            $nombres = $this->nombresDeMedicamentos($recipe->codemedicinas);

            $documentos->push($this->documento(
                'Récipe',
                'bi-prescription2',
                (int) $recipe->nroconsulta,
                $fechaDeLaConsulta,
                $recipe->cantidad . ($recipe->cantidad === 1 ? ' medicamento' : ' medicamentos')
                    . ($nombres === '' ? '' : ': ' . $nombres),
                CamposDePaciente::texto($recipe->fecha),
                // El número del récipe dentro de su consulta: con él se arma el enlace a su hoja impresa (WEB-5.2).
                $recipe->numero,
                'recipe-' . $recipe->numero,
            ));
        }

        // El más reciente primero, como las consultas: la fecha del documento y, si no tiene, la de su
        // consulta. Sin fecha no se puede ordenar, así que queda al final.
        return $documentos
            ->sortByDesc(fn (array $documento) => $documento['fecha'] ?? $fechaDeLaConsulta[$documento['consulta']] ?? '')
            ->values();
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * Un renglón de documento. La fecha propia (`$fecha`) pisa la de la consulta cuando existe: un reposo
     * se fecha el día en que empieza, no el día en que se atendió.
     *
     * @param  Collection<int, mixed>  $fechaDeLaConsulta
     * @return array{tipo:string, icono:string, consulta:?int, fecha:?string, resumen:?string, recipe:?int, clave:?string}
     */
    private function documento(string $tipo, string $icono, int $consulta, Collection $fechaDeLaConsulta, ?string $resumen, ?string $fecha = null, ?int $recipe = null, ?string $clave = null): array
    {
        return [
            'tipo'     => $tipo,
            'icono'    => $icono,
            'consulta' => $consulta,
            'fecha'    => $fecha ?? $fechaDeLaConsulta[$consulta] ?? null,
            'resumen'  => $resumen,
            // Sólo los récipes: su número dentro de la consulta (`null` en los demás documentos).
            'recipe'   => $recipe,
            // Con qué clave se pide su hoja impresa (`constancia`, `reposo`, `referencia-3`, `informe`,
            // `recipe-2`): la misma que usa `DocumentosParaImprimir` (WEB-5.3a).
            'clave'    => $clave,
        ];
    }

    /** @return array<int, array<int, string>> nroconsulta => motivos */
    private function motivosPorConsulta(string $regMedico, int $numero): array
    {
        $catalogo = $this->catalogoDeMotivos();

        return DB::table('motivo_consulta_paciente')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->orderBy('id')
            ->get(['nroconsulta', 'codemotivo', 'descripcion'])
            ->groupBy(fn ($fila) => (int) $fila->nroconsulta)
            ->map(fn (Collection $filas) => $filas->map(function ($fila) use ($catalogo, $regMedico) {
                $codigo = CamposDePaciente::texto($fila->codemotivo);

                // La descripción de la fila manda (es la que se eligió en esa consulta) y el catálogo es
                // el respaldo; si no hay ninguna de las dos, se muestra el código.
                return CamposDePaciente::texto($fila->descripcion)
                    ?? ($codigo === null ? null : ($catalogo[$this->claveDeCatalogo($regMedico, $codigo)] ?? $catalogo['|' . $codigo] ?? null))
                    ?? $codigo;
            })->filter()->values()->all())
            ->all();
    }

    /** @return array<int, array<int, string>> nroconsulta => diagnósticos */
    private function diagnosticosPorConsulta(string $regMedico, int $numero): array
    {
        $catalogo = $this->catalogoDeDiagnosticos();

        return DB::table('diagnostico_paciente')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['nroconsulta', 'codediagnostico', 'detalle_diagnostco'])
            ->groupBy(fn ($fila) => (int) $fila->nroconsulta)
            ->map(fn (Collection $filas) => $filas->map(function ($fila) use ($catalogo, $regMedico) {
                $codigo = CamposDePaciente::texto($fila->codediagnostico);

                // Igual que el móvil: el nombre del catálogo, si no el detalle escrito a mano, si no el
                // código.
                return ($codigo === null ? null : ($catalogo[$this->claveDeCatalogo($regMedico, $codigo)] ?? $catalogo['|' . $codigo] ?? null))
                    ?? CamposDePaciente::texto($fila->detalle_diagnostco)
                    ?? $codigo;
            })->filter()->values()->all())
            ->all();
    }

    private function constancias(string $regMedico, int $numero): Collection
    {
        return DB::table('constancia_obs')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->get(['numconsulta', 'observacion', 'titulo', 'observacion01']);
    }

    private function reposos(string $regMedico, int $numero): Collection
    {
        return DB::table('reposo_paciente')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->get(['nroconsulta', 'numdias', 'fdesde', 'obser_reposo']);
    }

    private function referencias(string $regMedico, int $numero): Collection
    {
        return DB::table('referencia')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->get(['nroconsulta', 'ceduladoctor', 'referencia']);
    }

    private function informes(string $regMedico, int $numero): Collection
    {
        return DB::table('informe')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->get(['nroconsulta', 'descripcion', 'fe_cha']);
    }

    /**
     * Los récipes **agrupados** como los agrupa el legado (`nrohistoria` + `nroconsulta` + `recipe`): una
     * fila por récipe, no una por medicamento. Los nombres de los medicamentos salen del vademécum, que es
     * de donde el legado los imprime (`recipes.descripcion` viene casi siempre NULL).
     */
    private function recipes(string $regMedico, int $numero): Collection
    {
        return DB::table('recipes')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->orderBy('nroconsulta')
            ->orderBy('recipe')
            ->orderBy('orden')
            ->get(['nroconsulta', 'recipe', 'codemedicina', 'fecha'])
            // El mismo agrupamiento que la consulta (`RecipesDeLaConsulta::numeroDeGrupo`): el `NULL` del
            // escritorio es el récipe 1, así que una fila sin número y una con `recipe = 1` son el mismo
            // récipe y no dos documentos.
            ->groupBy(fn ($fila) => (int) $fila->nroconsulta . '|' . RecipesDeLaConsulta::numeroDeGrupo($fila->recipe))
            ->map(fn (Collection $filas) => (object) [
                'nroconsulta'    => (int) $filas->first()->nroconsulta,
                'numero'         => RecipesDeLaConsulta::numeroDeGrupo($filas->first()->recipe),
                'fecha'          => $filas->first()->fecha,
                'cantidad'       => $filas->count(),
                'codemedicinas'  => $filas->pluck('codemedicina')->all(),
            ])
            ->values();
    }

    /** `NOMBRE, NOMBRE y N más`, con lo que el vademécum sepa de cada código. */
    private function nombresDeMedicamentos(array $codigos): string
    {
        $vademecum = $this->vademecum();

        $nombres = collect($codigos)
            ->map(function ($codigo) use ($vademecum) {
                $codigo = CamposDePaciente::texto($codigo);

                if ($codigo === null) {
                    return null;
                }

                $medicamento = $vademecum['|' . $codigo] ?? null;

                return CamposDePaciente::texto($medicamento->nombrecomercial ?? null)
                    ?? CamposDePaciente::texto($medicamento->nombregenerico ?? null)
                    ?? $codigo;
            })
            ->filter();

        return $nombres->take(3)->implode(', ') . ($nombres->count() > 3 ? ' y ' . ($nombres->count() - 3) . ' más' : '');
    }

    /**
     * `reg_medico|codigo => fila` con el respaldo `|codigo => fila`: el catálogo del propio médico manda y
     * el de cualquier otro sirve de respaldo (los datos del legado traen `reg_medico` inconsistentes).
     *
     * @return array<string, object>
     */
    private function catalogo(string $tabla, string $columnaCodigo, array $columnas): array
    {
        $indice = [];

        foreach (DB::table($tabla)->get(array_merge(['reg_medico', $columnaCodigo], $columnas)) as $fila) {
            $codigo = CamposDePaciente::texto($fila->{$columnaCodigo});

            if ($codigo === null) {
                continue;
            }

            $indice[$this->claveDeCatalogo((string) $fila->reg_medico, $codigo)] = $fila;
            $indice['|' . $codigo] ??= $fila;
        }

        return $indice;
    }

    private function claveDeCatalogo(string $regMedico, string $codigo): string
    {
        return $regMedico . '|' . $codigo;
    }

    /** @return array<string, object> */
    private function catalogoDeAntecedentes(): array
    {
        return $this->catalogo('antecedentes', 'codantecedente', ['descripcion', 'codtipo']);
    }

    /** @return array<string, string> codetipo => descripción del tipo */
    private function tiposDeAntecedente(): array
    {
        $tipos = [];

        foreach (DB::table('tipo_antecedente')->get(['codetipo', 'descripcion']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codetipo);
            $descripcion = CamposDePaciente::texto($fila->descripcion);

            if ($codigo !== null && $descripcion !== null) {
                $tipos[$codigo] ??= $descripcion;
            }
        }

        return $tipos;
    }

    /** @return array<string, string> */
    private function catalogoDeMotivos(): array
    {
        $catalogo = [];

        foreach (DB::table('motivos_consulta')->get(['reg_medico', 'codemotivo', 'descripcion']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codemotivo);
            $descripcion = CamposDePaciente::texto($fila->descripcion);

            if ($codigo !== null && $descripcion !== null) {
                $catalogo[$this->claveDeCatalogo((string) $fila->reg_medico, $codigo)] ??= $descripcion;
                $catalogo['|' . $codigo] ??= $descripcion;
            }
        }

        return $catalogo;
    }

    /** @return array<string, string> */
    private function catalogoDeDiagnosticos(): array
    {
        $catalogo = [];

        foreach (DB::table('diagnosticos')->get(['reg_medico', 'codediagnostico', 'descripcion']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codediagnostico);
            $descripcion = CamposDePaciente::texto($fila->descripcion);

            if ($codigo !== null && $descripcion !== null) {
                $catalogo[$this->claveDeCatalogo((string) $fila->reg_medico, $codigo)] ??= $descripcion;
                $catalogo['|' . $codigo] ??= $descripcion;
            }
        }

        return $catalogo;
    }

    /**
     * El nombre del médico referido, por su cédula. `referencia.ceduladoctor` es un decimal y
     * `doctores.cedula` también, así que la clave se arma con el número sin decimales (como hace el
     * móvil: `_Catalogo::_codigo`).
     *
     * @return array<string, string>
     */
    private function nombresDeDoctores(): array
    {
        $doctores = [];

        foreach (DB::table('doctores')->get(['reg_medico', 'cedula', 'nombres', 'apellidos']) as $fila) {
            $cedula = CamposDePaciente::texto($fila->cedula);

            if ($cedula === null) {
                continue;
            }

            $nombre = trim(implode(' ', array_filter([
                CamposDePaciente::texto($fila->nombres),
                CamposDePaciente::texto($fila->apellidos),
            ])));

            if ($nombre !== '') {
                $doctores[$this->claveDeCatalogo((string) $fila->reg_medico, (string) (int) $cedula)] = $nombre;
                $doctores['|' . (string) (int) $cedula] ??= $nombre;
            }
        }

        return $doctores;
    }

    /** @return array<string, object> `|codigo => fila` (el vademécum no se filtra por médico: se imprime igual) */
    private function vademecum(): array
    {
        $indice = [];

        foreach (DB::table('vademecum')->get(['codemedicina', 'nombregenerico', 'nombrecomercial']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codemedicina);

            if ($codigo !== null) {
                $indice['|' . $codigo] ??= $fila;
            }
        }

        return $indice;
    }
}
