<?php

namespace App\Console\Commands;

use App\Services\JevService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * **Prueba de concepto de triaje con Jev AI** (ROADMAP.md §10.3): mide cuánto acierta el modelo al
 * clasificar mensajes de pacientes de WhatsApp, antes de decidir si se integra.
 *
 * Qué mide, por cada mensaje del corpus (`database/datos/triaje-whatsapp.json`):
 *  - la **intención** (`choice`: agendar, reprogramar, cancelar, consulta_clinica, urgencia, administrativo)
 *    contra la que la secretaría le asignaría;
 *  - si es una **urgencia** (`noul`: probabilidad de 0 a 1) contra la marca del corpus;
 *  - la **confianza** y la probabilidad de la opción elegida, para calibrar el umbral de derivación.
 *
 * Al final imprime aciertos, matriz de confusión, cuántos mensajes quedarían por debajo del umbral de
 * confianza (los que se derivarían a la secretaría) y el costo aproximado por tokens.
 *
 * Lo que **no** hace: no escribe en la base, no manda nada por WhatsApp y no toca el chatbot. Es una
 * medición, igual que la auditoría del 2026-10-17: el resultado decide si se integra o se descarta.
 *
 * Uso (la clave va en el `.env`, nunca en la línea de comandos):
 *
 *     php artisan jev:triaje
 *     php artisan jev:triaje --limite=10 --detalle
 *     php artisan jev:triaje --umbral=0.8 --json=storage/app/jev-triaje.json
 */
class ProbarTriajeJevCommand extends Command
{
    /** Precio publicado del modelo en el sitio de Jev ($0,084 por millón de tokens de entrada; la salida es gratis). */
    private const PRECIO_POR_MILLON = 0.084;

    protected $signature = 'jev:triaje
        {--archivo= : Corpus en JSON (por defecto database/datos/triaje-whatsapp.json)}
        {--limite=0 : Cuántos mensajes probar (0 = todos)}
        {--umbral= : Confianza mínima para automatizar (por defecto, la de config/services.php)}
        {--modelo= : Modelo a usar (por defecto JEV_MODEL)}
        {--protocolo= : systemone, chat o auto (por defecto JEV_PROTOCOLO)}
        {--detalle : Muestra las probabilidades de cada mensaje}
        {--json= : Guarda el resultado crudo y el resumen en este archivo}';

    protected $description = 'Prueba de concepto: mide el triaje de mensajes de WhatsApp con Jev AI (no escribe nada)';

    public function handle(): int
    {
        // Las opciones pisan la config sólo para esta corrida: sirven para probar otro modelo o el camino de
        // chat sin editar el .env.
        if ($this->option('modelo')) {
            config(['services.jev.model' => (string) $this->option('modelo')]);
        }
        if ($this->option('protocolo')) {
            config(['services.jev.protocolo' => (string) $this->option('protocolo')]);
        }

        $servicio = app(JevService::class);

        try {
            $corpus = $this->corpus((string) ($this->option('archivo') ?: database_path('datos/triaje-whatsapp.json')));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $mensajes = (array) ($corpus['mensajes'] ?? []);
        $categorias = (array) ($corpus['categorias'] ?? []);

        if ($mensajes === [] || $categorias === []) {
            $this->error('El corpus no tiene `mensajes` o `categorias`.');
            $this->line('Mira el formato en database/datos/triaje-whatsapp.json.');

            return self::FAILURE;
        }

        $limite = max(0, (int) $this->option('limite'));
        if ($limite > 0) {
            $mensajes = array_slice($mensajes, 0, $limite);
        }

        $umbral = $this->option('umbral') !== null ? (float) $this->option('umbral') : $servicio->umbral();

        $this->line(sprintf(
            'Jev · modelo <info>%s</info> · %d mensajes · umbral de confianza <info>%.2f</info>',
            $servicio->modelo(),
            count($mensajes),
            $umbral
        ));

        if (! $servicio->tieneClave()) {
            $this->newLine();
            $this->error('Falta JEV_API_KEY.');
            $this->line('Agrégala al .env de MedicAPI (junto a GEMINI_API_KEY) y vuelve a correr el comando:');
            $this->newLine();
            $this->line('  <comment># Jev AI — decisiones tipadas (prueba de concepto jev:triaje)</comment>');
            $this->line('  <comment>JEV_API_KEY=sk_...</comment>');
            $this->line('  <comment># JEV_BASE_URL=https://thejevai.com</comment>');
            $this->line('  <comment># JEV_MODEL=typesafe/jev-1.14</comment>');
            $this->newLine();
            $this->line('Si la clave es de DigitalOcean Inference, además: <comment>JEV_BASE_URL=https://inference.do-ai.run</comment> y <comment>JEV_MODEL=typesafe-jev-1.13.0</comment>.');

            return self::FAILURE;
        }

        $this->informarLoQueSePregunta();

        $resultados = [];
        $error = null;
        $tokens = 0;
        $ms = 0;
        $creditos = 0;
        $costo = 0.0;

        $this->newLine();

        $barra = $this->output->createProgressBar(count($mensajes));
        $barra->setFormat(' %current%/%max% [%bar%] %message%');
        $barra->start();

        foreach ($mensajes as $indice => $mensaje) {
            $texto = (string) ($mensaje['mensaje'] ?? '');

            $barra->setMessage(mb_substr($texto, 0, 40) . '…');

            try {
                $respuesta = $servicio->decidir($texto, $this->preguntas($categorias));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                break;
            }

            $tokens += (int) ($respuesta['usage']['input_tokens'] ?? 0);
            $ms += (int) ($respuesta['elapsed_ms'] ?? 0);
            $creditos += (int) ($respuesta['credits'] ?? 0);
            $costo += (float) ($respuesta['costo'] ?? 0);

            $intencion = $respuesta['answers']['intencion'] ?? [];
            $urgencia = $respuesta['answers']['es_urgencia'] ?? [];

            $elegida = isset($intencion['choice']) ? (string) $intencion['choice'] : '(sin respuesta)';
            $confianza = (float) ($intencion['confidence'] ?? 0);
            $probabilidad = (float) ($intencion['probabilities'][$elegida] ?? 0);
            $probUrgencia = (float) ($urgencia['noul'] ?? 0);
            $resultados[] = [
                'id' => $mensaje['id'] ?? ($indice + 1),
                'mensaje' => $texto,
                'intencion_esperada' => (string) ($mensaje['intencion'] ?? ''),
                'intencion_jev' => $elegida,
                'acierto_intencion' => $elegida === (string) ($mensaje['intencion'] ?? ''),
                'confianza' => $confianza,
                'probabilidad' => $probabilidad,
                'urgencia_esperada' => (bool) ($mensaje['urgencia'] ?? false),
                'urgencia_jev' => $probUrgencia,
                'acierto_urgencia' => ($probUrgencia >= 0.5) === (bool) ($mensaje['urgencia'] ?? false),
                'bajo_umbral' => $confianza < $umbral,
                'probabilities' => $intencion['probabilities'] ?? [],
                'elapsed_ms' => $respuesta['elapsed_ms'] ?? null,
            ];

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        if ($error !== null) {
            $this->error($error);
        }

        if ($resultados === []) {
            return self::FAILURE;
        }

        $protocolo = $servicio->protocoloUsado();

        if ($protocolo !== null) {
            $this->line(sprintf('Transporte usado: <info>%s</info>', $protocolo));
        }

        if ($protocolo === 'chat') {
            $this->newLine();
            $this->line('<comment>⚠️ El proveedor no expone /v1/systemone: la corrida fue por chat/completions.</comment>');
            $this->line('   La categoría se puede medir igual, pero la <comment>confianza es autoinformada</comment> por el modelo,');
            $this->line('   no calibrada: no sirve para fijar un umbral de derivación en producción. Para eso hace falta');
            $this->line('   un proveedor con el protocolo propio de Jev (thejevai.com o DigitalOcean Inference).');
        }

        $this->tablaDeMensajes($resultados);
        $resumen = $this->resumen($resultados, $umbral, $tokens, $ms, $creditos, $costo);

        if ($this->option('json')) {
            $ruta = (string) $this->option('json');
            file_put_contents(
                $ruta,
                json_encode([
                    'modelo' => $servicio->modelo(),
                    'protocolo' => $protocolo,
                    'confianza_calibrada' => $servicio->confianzaCalibrada(),
                    'umbral' => $umbral,
                    'resumen' => $resumen,
                    'mensajes' => $resultados,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $this->newLine();
            $this->line("Resultado guardado en <info>{$ruta}</info>");
        }

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------------------------- Presentación

    /** Qué se le pregunta a Jev: dos preguntas paralelas sobre el mismo mensaje. */
    private function preguntas(array $categorias): array
    {
        return [
            'intencion' => [
                'type' => 'choice',
                'instructions' => 'Eres la secretaría de un consultorio médico. ¿Qué quiere la persona que escribe este mensaje de WhatsApp? Elige la categoría que mejor lo describa. Regla para no confundir el apuro con el cuadro clínico: si PIDE O CAMBIA UNA CITA, la categoría es agendar, reprogramar o cancelar aunque diga «urgente», «para hoy» o «lo antes posible»; reserva `urgencia` para cuando relata un cuadro clínico grave y no está pidiendo una cita.',
                'criteria' => $categorias,
            ],
            // La misma pregunta que usa el chatbot para decidir si una cita puede agendarse con 90 min.
            'es_urgencia' => \App\Reservas\UrgenciaClinicaJev::pregunta(),
        ];
    }

    private function informarLoQueSePregunta(): void
    {
        $this->newLine();
        $this->line('<comment>Lo que se le pregunta a Jev (dos preguntas en la misma llamada):</comment>');
        $this->line('  · <info>intencion</info> (choice): agendar · reprogramar · cancelar · consulta_clinica · urgencia · administrativo');
        $this->line('  · <info>es_urgencia</info> (noul): probabilidad de que haya que escalar ya');
    }

    /** @param  array<int,array<string,mixed>>  $resultados */
    private function tablaDeMensajes(array $resultados): void
    {
        $filas = [];

        foreach ($resultados as $r) {
            $marca = $r['acierto_intencion'] ? '<info>✓</info>' : '<error>✗</error>';
            $urgencia = sprintf('%.2f', $r['urgencia_jev']);
            $urgenciaMarca = $r['acierto_urgencia'] ? '' : ' <error>≠</error>';

            $filas[] = [
                $r['id'],
                mb_strimwidth($r['mensaje'], 0, 58, '…'),
                $r['intencion_esperada'],
                $r['intencion_jev'],
                $marca,
                sprintf('%.2f', $r['confianza']),
                sprintf('%.2f', $r['probabilidad']),
                $urgencia . $urgenciaMarca,
                $r['bajo_umbral'] ? 'sí' : '',
            ];
        }

        $this->table(
            ['#', 'Mensaje', 'Esperado', 'Dijo Jev', '✓', 'Conf.', 'P(opción)', 'Urg. P', 'Derivar'],
            $filas
        );

        if ($this->option('detalle')) {
            $this->newLine();
            $this->line('<comment>Probabilidades por categoría:</comment>');

            foreach ($resultados as $r) {
                $partes = [];
                foreach ((array) $r['probabilities'] as $categoria => $p) {
                    if ($p >= 0.01) {
                        $partes[] = sprintf('%s %.2f', $categoria, $p);
                    }
                }

                $this->line(sprintf(
                    '  #%02d %s',
                    $r['id'],
                    $partes === [] ? '(sin probabilidades)' : implode(' · ', $partes)
                ));
            }
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $resultados
     * @return array<string,mixed>
     */
    private function resumen(array $resultados, float $umbral, int $tokens, int $ms, int $creditos, float $costo = 0.0): array
    {
        $total = count($resultados);
        $aciertos = count(array_filter($resultados, fn (array $r) => $r['acierto_intencion']));
        $aciertosUrgencia = count(array_filter($resultados, fn (array $r) => $r['acierto_urgencia']));
        $derivados = count(array_filter($resultados, fn (array $r) => $r['bajo_umbral']));

        // De los aciertos, cuántos se habrían automatizado (confianza suficiente).
        $automatizables = count(array_filter($resultados, fn (array $r) => $r['acierto_intencion'] && ! $r['bajo_umbral']));
        // Falsos positivos de urgencia: escalar de más también tiene costo (molesta al médico).
        $falsasUrgencias = count(array_filter($resultados, fn (array $r) => $r['acierto_urgencia'] === false && $r['urgencia_jev'] >= 0.5));
        $urgenciasOmitidas = count(array_filter($resultados, fn (array $r) => $r['acierto_urgencia'] === false && $r['urgencia_esperada'] && $r['urgencia_jev'] < 0.5));

        $this->line('── Resumen ─────────────────────────────────────────────');
        $this->line(sprintf(
            'Intención:        <info>%d/%d</info> (%.0f%%)  ·  urgencia: <info>%d/%d</info> (%.0f%%)',
            $aciertos,
            $total,
            $total ? $aciertos * 100 / $total : 0,
            $aciertosUrgencia,
            $total,
            $total ? $aciertosUrgencia * 100 / $total : 0
        ));
        $this->line(sprintf(
            'Con el umbral %.2f: <info>%d</info> automatizables (aciertos con confianza suficiente), %d se derivarían a la secretaría (%.0f%% del total)',
            $umbral,
            $automatizables,
            $derivados,
            $total ? $derivados * 100 / $total : 0
        ));
        $this->line(sprintf(
            'Urgencias con el umbral 0.50: %d falsos positivos (se escalaría de más) y %d omitidas (lo caro)',
            $falsasUrgencias,
            $urgenciasOmitidas
        ));
        $this->line(sprintf(
            'Consumo: %d tokens de entrada · %d ms acumulados · %.0f ms por mensaje · costo %s',
            $tokens,
            $ms,
            $total ? $ms / $total : 0,
            $costo > 0
                ? sprintf('$%s (informado por Jev)', number_format($costo, 6, ',', '.'))
                : sprintf('≈ $%s (a $%.3f/MTok)', number_format($tokens * self::PRECIO_POR_MILLON / 1_000_000, 6, ',', '.'), self::PRECIO_POR_MILLON)
        ));

        if ($creditos > 0) {
            $this->line(sprintf('Créditos informados por Jev: %d', $creditos));
        }

        $this->newLine();
        $this->matriz($resultados);

        $fallos = array_values(array_filter($resultados, fn (array $r) => ! $r['acierto_intencion']));
        if ($fallos !== []) {
            $this->newLine();
            $this->line('<comment>Donde se equivocó de categoría:</comment>');
            foreach ($fallos as $r) {
                $this->line(sprintf(
                    '  #%02d «%s» → esperado %s, dijo %s (conf. %.2f)',
                    $r['id'],
                    mb_strimwidth($r['mensaje'], 0, 52, '…'),
                    $r['intencion_esperada'],
                    $r['intencion_jev'],
                    $r['confianza']
                ));
            }
        }

        return [
            'total' => $total,
            'aciertos_intencion' => $aciertos,
            'aciertos_urgencia' => $aciertosUrgencia,
            'automatizables' => $automatizables,
            'derivados' => $derivados,
            'falsas_urgencias' => $falsasUrgencias,
            'urgencias_omitidas' => $urgenciasOmitidas,
            'umbral' => $umbral,
            'tokens' => $tokens,
            'ms' => $ms,
            'costo_informado_usd' => round($costo, 6),
            'costo_estimado_usd' => round($tokens * self::PRECIO_POR_MILLON / 1_000_000, 6),
        ];
    }

    /** @param  array<int,array<string,mixed>>  $resultados */
    private function matriz(array $resultados): void
    {
        $esperadas = [];
        $obtenidas = [];

        foreach ($resultados as $r) {
            $esperadas[$r['intencion_esperada']] = true;
            $obtenidas[$r['intencion_jev']] = true;
        }

        $columnas = array_keys($obtenidas);
        $encabezado = array_merge(['esperado \\ dijo'], $columnas, ['total']);

        $filas = [];
        foreach (array_keys($esperadas) as $esperada) {
            $fila = [$esperada];
            $suma = 0;

            foreach ($columnas as $columna) {
                $n = count(array_filter($resultados, fn (array $r) => $r['intencion_esperada'] === $esperada && $r['intencion_jev'] === $columna));
                $fila[] = $n > 0 ? (string) $n : '·';
                $suma += $n;
            }

            $fila[] = (string) $suma;
            $filas[] = $fila;
        }

        $this->line('<comment>Matriz de confusión (filas = lo que diría la secretaría):</comment>');
        $this->table($encabezado, $filas);
    }

    // -------------------------------------------------------------------------------------- Entrada

    /** @return array<string,mixed> */
    private function corpus(string $ruta): array
    {
        if (! is_file($ruta)) {
            throw new RuntimeException("No encuentro el corpus: {$ruta}");
        }

        $json = json_decode((string) file_get_contents($ruta), true);

        if (! is_array($json)) {
            throw new RuntimeException("El corpus no es JSON válido: {$ruta}");
        }

        return $json;
    }
}
