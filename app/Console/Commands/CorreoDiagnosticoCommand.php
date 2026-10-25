<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * **Diagnóstico del correo saliente**, para la puesta en el servidor (QA y producción).
 *
 * Muestra qué transporte hay y, si es SMTP, con qué servidor; comprueba que el nombre de ese servidor
 * **resuelva** (el error más común cuando el host del `.env` no existe todavía) y, con `--enviar-a`, manda un
 * correo real de prueba para ver el error del proveedor tal cual.
 *
 *   php artisan correo:diagnostico
 *       Sólo lee la configuración y resuelve el DNS. No manda nada.
 *
 *   php artisan correo:diagnostico --enviar-a=tu@correo.com
 *       Manda un correo de verdad. Valida credenciales, TLS y puerto. Le llega a una persona: usa el tuyo.
 *
 * Sin correo saliente no hay código de registro de médicos ni saludo de cumpleaños (ROADMAP §5). Nunca imprime
 * la contraseña del SMTP: sólo dice si está cargada y de qué largo.
 */
final class CorreoDiagnosticoCommand extends Command
{
    protected $signature = 'correo:diagnostico
        {--enviar-a= : manda un correo REAL de prueba a esa dirección}';

    protected $description = 'Diagnóstico del correo saliente: transporte, servidor SMTP, DNS del host y un envío real de prueba';

    public function handle(): int
    {
        $transporte = (string) config('mail.default');

        $this->line('<info>Correo saliente · configuración</info>');
        $this->table(['Ajuste', 'Valor'], $this->filas($transporte));

        $problemas = $this->revisar($transporte);

        $destino = trim((string) $this->option('enviar-a'));

        if ($destino !== '') {
            $problemas = array_merge($problemas, $this->probarEnvio($destino));
        }

        $this->newLine();

        if ($problemas !== []) {
            $this->error('Hay problemas con el correo saliente: ' . implode(', ', $problemas) . '.');

            return self::FAILURE;
        }

        $this->info('La configuración del correo saliente está completa'
            . ($destino !== '' ? ' y el envío de prueba salió.' : '.'));

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function filas(string $transporte): array
    {
        $filas = [
            ['MAIL_MAILER', $transporte],
            ['MAIL_FROM_ADDRESS', $this->texto(config('mail.from.address'))],
            ['MAIL_FROM_NAME', $this->texto(config('mail.from.name'))],
        ];

        if ($transporte !== 'smtp') {
            return $filas;
        }

        $smtp = (array) config('mail.mailers.smtp', []);
        $clave = trim((string) ($smtp['password'] ?? ''));

        return array_merge($filas, [
            ['MAIL_HOST', $this->texto($smtp['host'] ?? '')],
            ['MAIL_PORT', $this->texto($smtp['port'] ?? '')],
            ['MAIL_ENCRYPTION', $this->texto($smtp['encryption'] ?? '')],
            ['MAIL_USERNAME', trim((string) ($smtp['username'] ?? '')) !== '' ? 'cargado' : '—'],
            ['MAIL_PASSWORD', $clave !== '' ? 'cargada (' . mb_strlen($clave) . ' caracteres)' : '—'],
        ]);
    }

    /** @return list<string> lo que está mal */
    private function revisar(string $transporte): array
    {
        $problemas = [];
        $de = trim((string) config('mail.from.address'));

        if ($de === '' || str_ends_with(strtolower($de), 'example.com')) {
            $this->warn('MAIL_FROM_ADDRESS no está cargado: el remitente saldría como «' . $de . '» y los servidores de destino lo rechazan.');
            $problemas[] = 'remitente';
        }

        if ($transporte === 'log') {
            $this->warn('Con MAIL_MAILER=log no sale ningún correo: el mensaje queda en storage/logs/laravel.log.');
            $problemas[] = 'MAIL_MAILER=log';
        }

        if ($transporte === 'array') {
            $this->warn('Con MAIL_MAILER=array los correos no salen: se guardan en memoria (es el transporte de los tests).');
        }

        if ($transporte !== 'smtp') {
            return $problemas;
        }

        $host = trim((string) config('mail.mailers.smtp.host'));

        if ($host === '') {
            $this->error('Falta MAIL_HOST: sin servidor SMTP no hay correo saliente.');
            $problemas[] = 'MAIL_HOST';

            return $problemas;
        }

        if (! $this->resuelve($host)) {
            $this->error('El servidor «' . $host . '» no resuelve (DNS): ese nombre no existe o le falta el registro.');
            $this->line('Es el error que aparece al pedir el código del registro de médicos («getaddrinfo for … failed: Name or service not known»).');
            $this->line('O el nombre está mal escrito, o el servidor de correo de ese dominio todavía no está montado, o hay que apuntar MAIL_HOST al SMTP real (y después <comment>php artisan config:clear</comment>).');
            $problemas[] = 'DNS';
        }

        return $problemas;
    }

    /** @return list<string> lo que está mal del envío */
    private function probarEnvio(string $destino): array
    {
        if (filter_var($destino, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--enviar-a no es un correo válido: ' . $destino);

            return ['destino'];
        }

        $this->line('Mandando un correo de prueba a ' . $destino . '…');

        try {
            Mail::raw(
                'Prueba de correo de Doctorísimo (' . now()->format('d/m/Y H:i') . ').'
                . ' Si recibes esto, el correo saliente del servidor funciona.',
                fn ($mensaje) => $mensaje->to($destino)->subject('Prueba de correo · Doctorísimo')
            );
        } catch (Throwable $e) {
            $this->error('No salió: ' . $e->getMessage());
            $this->line('Ese texto es el error real del proveedor: autenticación, TLS, host que no resuelve o puerto bloqueado.');

            return ['envío'];
        }

        $this->info('Enviado. Revisa la bandeja (y el spam) de ' . $destino . '.');

        return [];
    }

    /** ¿El nombre del servidor existe en el DNS? Una IP vale tal cual. */
    private function resuelve(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return gethostbyname($host) !== $host;
    }

    private function texto(mixed $valor): string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? '—' : $valor;
    }
}
