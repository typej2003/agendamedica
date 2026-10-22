<?php

namespace App\Services;

use App\Actions\Agenda\CrearCita;
use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\Jornada;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Paciente\BloqueoDePacientes;
use App\Models\Specialty;
use App\Support\DisponibilidadEnTexto;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PacienteAtencionContextService
{
    /**
     * Cupos máximos de atención por día por médico por defecto
     */
    public const LIMITE_CUPOS_DIARIOS = 15;

    /** Anticipación mínima para agendar una cita normal (24 h). Una urgencia podrá pasar otra a `lugaresDelDia`. */
    public const ANTICIPACION_MINIMA_MINUTOS = 24 * 60;

    /**
     * Las dependencias de la agenda son opcionales para no romper a quien instancia el servicio a mano
     * (los tests lo hacían con `new PacienteAtencionContextService()`); sin ellas se resuelven del
     * contenedor la primera vez que se reserva una cita.
     */
    public function __construct(
        private ?CrearCita $crearCita = null,
        private ?ArmadorDeAgenda $armador = null,
    ) {
    }

    /**
     * Busca al paciente en el modelo Paciente probando variantes del número telefónico.
     */
    public function buscarPacientePorTelefono(string $telefono): ?Paciente
    {
        $clean = preg_replace('/\D/', '', $telefono); // Ej: 584165800403

        if (empty($clean)) {
            return null;
        }

        $posiblesValores = [
            $telefono,
            $clean,
        ];

        // Variaciones para números en Venezuela (+58)
        if (str_starts_with($clean, '58') && strlen($clean) >= 12) {
            $nacional = '0' . substr($clean, 2); // 04165800403
            $sinPrefijo = substr($clean, 2);      // 4165800403
            $posiblesValores[] = $nacional;
            $posiblesValores[] = $sinPrefijo;
            $posiblesValores[] = '+' . $clean;
            if (strlen($nacional) === 11) {
                $posiblesValores[] = substr($nacional, 0, 4) . '-' . substr($nacional, 4); // 0416-5800403
                $posiblesValores[] = substr($nacional, 0, 4) . ' ' . substr($nacional, 4); // 0416 5800403
            }
        } elseif (str_starts_with($clean, '0') && strlen($clean) === 11) {
            $internacional = '58' . substr($clean, 1);
            $posiblesValores[] = $internacional;
            $posiblesValores[] = '+' . $internacional;
            $posiblesValores[] = substr($clean, 1);
            $posiblesValores[] = substr($clean, 0, 4) . '-' . substr($clean, 4);
        }

        $posiblesValores = array_unique(array_filter($posiblesValores));

        try {
            // 1. Búsqueda exacta por cualquiera de las variantes formateadas
            $paciente = Paciente::whereIn('telefono', $posiblesValores)->first();
            if ($paciente) {
                if (trim((string) $paciente->apellidos) === 'WhatsApp') {
                    return null;
                }
                return $paciente;
            }

            // 2. Búsqueda por los últimos 10 dígitos (código de operador + abonado en VE, ej: 4141234567)
            if (strlen($clean) >= 10) {
                $ultimos10 = substr($clean, -10);
                $paciente = Paciente::where('telefono', 'like', "%{$ultimos10}%")->first();
                if ($paciente) {
                    if (trim((string) $paciente->apellidos) === 'WhatsApp') {
                        return null;
                    }
                    return $paciente;
                }
            } elseif (strlen($clean) === 7) {
                // Entrada exacta de 7 dígitos locales
                $paciente = Paciente::where('telefono', 'like', "%{$clean}%")->first();
                if ($paciente) {
                    if (trim((string) $paciente->apellidos) === 'WhatsApp') {
                        return null;
                    }
                    return $paciente;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error al buscar paciente: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Evalúa si un mensaje es un saludo o comando de inicio.
     */
    public function esSaludo(string $mensaje): bool
    {
        $limpio = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $mensaje)));
        $saludos = [
            'hola', 'ola', 'buenas', 'buenos dias', 'buenos días', 'buenas tardes',
            'buenas noches', 'buen dia', 'buen día', 'saludos', 'hola buen dia',
            'hola buenas', 'hola buenas tardes', 'hola buenos dias', 'menu', 'inicio',
            'empezar', 'start', 'ayuda', 'hi', 'hello'
        ];
        return in_array($limpio, $saludos, true);
    }

    /**
     * Gestiona el flujo secuencial de registro para un paciente nuevo cuando su
     * número de teléfono no se encuentra en el modelo Paciente:
     * 1. Preguntar apellidos.
     * 2. Preguntar nombres.
     * 3. Preguntar cédula.
     * 4. Almacenar datos elementales en Paciente y ofrecer el servicio de especialidades o médico directo.
     */
    public function gestionarRegistroPaciente(string $telefono, string $mensaje): ?string
    {
        $paciente = $this->buscarPacientePorTelefono($telefono);
        if ($paciente !== null) {
            return null;
        }

        $cleanTel = preg_replace('/\D/', '', $telefono);
        $cacheKey = "wa_registro_{$cleanTel}";
        $estado = Cache::get($cacheKey) ?? Cache::get("wa_registro_{$telefono}");

        $mensajeBienvenidaInicial = "¡Hola! Te damos la bienvenida a *Doctorisimo*. 👋\n\n"
            . "Notamos que tu número de teléfono no se encuentra registrado en nuestro sistema. Para poder brindarte atención médica y agendar tus citas, te ofrecemos registrarte.\n\n"
            . "Para completar tu registro, te solicitaremos los siguientes datos:\n"
            . "1️⃣ Tu número de cédula de identidad\n"
            . "2️⃣ Tus apellidos\n"
            . "3️⃣ Tus nombres\n\n"
            . "Para comenzar, por favor indícanos tu número de *cédula de identidad* (ejemplo: V-12345678):";

        // Si el usuario envía un saludo o solicita reiniciar durante el registro en curso
        if ($estado !== null && ($this->esSaludo(trim($mensaje)) || in_array(mb_strtolower(trim($mensaje)), ['reiniciar', 'cancelar', 'empezar de nuevo', 'volver a empezar', 'menu', 'inicio'], true))) {
            $nuevoEstado = ['paso' => 'esperando_cedula'];
            Cache::put($cacheKey, $nuevoEstado, now()->addHours(24));
            Cache::put("wa_registro_{$telefono}", $nuevoEstado, now()->addHours(24));

            return $mensajeBienvenidaInicial;
        }

        // Estado 0: Primer mensaje del paciente no registrado -> Bienvenida y solicitud de cédula
        if ($estado === null) {
            $nuevoEstado = ['paso' => 'esperando_cedula'];
            Cache::put($cacheKey, $nuevoEstado, now()->addHours(24));
            Cache::put("wa_registro_{$telefono}", $nuevoEstado, now()->addHours(24));

            return $mensajeBienvenidaInicial;
        }

        // Estado 1: Esperando cédula -> Validar cédula, verificar si ya existe en Paciente o pedir apellidos
        if (($estado['paso'] ?? '') === 'esperando_cedula') {
            $cedulaInput = preg_replace('/^(mi\s+c[eé]dula\s+es|c[eé]dula:?|ci:?|c\.i\.?:?)\s*/iu', '', trim($mensaje));
            [$nac, $cedulaLimpia] = $this->normalizarCedulaYNac($cedulaInput);

            if (empty($cedulaLimpia) || mb_strlen($cedulaLimpia) < 4) {
                return "El número de cédula ingresado no parece válido. Por favor, indícanos tu número de *cédula de identidad* (ejemplo: V-12345678):";
            }

            // Si la cédula ya existe en Paciente, actualizar teléfono y ofrecer servicio directamente
            $pacienteExistente = Paciente::where('cedula', $cedulaLimpia)->first();
            if ($pacienteExistente) {
                $pacienteExistente->telefono = $telefono;
                if (empty($pacienteExistente->nac) && $nac) {
                    $pacienteExistente->nac = $nac;
                }
                $pacienteExistente->save();

                Cache::forget($cacheKey);
                Cache::forget("wa_registro_{$telefono}");

                $especialidades = $this->obtenerEspecialidadesDisponibles();
                Cache::put("wa_menu_especialidades_{$cleanTel}", $especialidades, now()->addHours(2));
                Cache::put("wa_menu_especialidades_{$telefono}", $especialidades, now()->addHours(2));

                $nombreCompleto = trim("{$pacienteExistente->nombres} {$pacienteExistente->apellidos}");
                $msg = "¡Hola, *{$nombreCompleto}*! 👋 Hemos vinculado tu número de WhatsApp a tu ficha médica registrada con cédula *{$nac}-{$cedulaLimpia}*.\n\n";
                $msg .= "A continuación, te ofrecemos nuestras especialidades médicas disponibles:\n\n";
                foreach ($especialidades as $idx => $esp) {
                    $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
                    $msg .= "{$numEmoji} {$esp}\n";
                }
                $msg .= "\nPor favor, responde con el número o nombre de la especialidad que buscas, o indícanos el nombre de tu doctor para verificar disponibilidad. 😊";
                return $msg;
            }

            // Cédula nueva: guardar en caché y pedir apellidos
            $nuevoEstado = [
                'paso' => 'esperando_apellidos',
                'cedula' => $cedulaLimpia,
                'nac' => $nac,
            ];
            Cache::put($cacheKey, $nuevoEstado, now()->addHours(24));
            Cache::put("wa_registro_{$telefono}", $nuevoEstado, now()->addHours(24));

            return "Muchas gracias. Ahora, por favor indícanos tus *apellidos*:";
        }

        // Estado 2: Esperando apellidos -> Guardar y preguntar nombres
        if (($estado['paso'] ?? '') === 'esperando_apellidos') {
            $apellidos = preg_replace('/^(mis\s+apellidos\s+son|mi\s+apellido\s+es|apellidos?:)\s*/iu', '', trim($mensaje));
            $apellidos = trim($apellidos);

            if (empty($apellidos) || mb_strlen($apellidos) < 2) {
                return "Por favor, indícanos tus *apellidos* para poder continuar con tu registro:";
            }

            $apellidosFormateado = mb_convert_case($apellidos, MB_CASE_TITLE, 'UTF-8');
            $nuevoEstado = [
                'paso' => 'esperando_nombres',
                'cedula' => $estado['cedula'] ?? '',
                'nac' => $estado['nac'] ?? 'V',
                'apellidos' => $apellidosFormateado,
            ];
            Cache::put($cacheKey, $nuevoEstado, now()->addHours(24));
            Cache::put("wa_registro_{$telefono}", $nuevoEstado, now()->addHours(24));

            return "Perfecto. Por último, por favor indícanos tus *nombres*:";
        }

        // Estado 3: Esperando nombres -> Guardar en Paciente y ofrecer el servicio
        if (($estado['paso'] ?? '') === 'esperando_nombres') {
            $nombres = preg_replace('/^(mis\s+nombres\s+son|mi\s+nombre\s+es|me\s+llamo|nombres?:)\s*/iu', '', trim($mensaje));
            $nombres = trim($nombres);

            if (empty($nombres) || mb_strlen($nombres) < 2) {
                return "Por favor, indícanos tus *nombres* para continuar con tu registro:";
            }

            $nombresFormateado = mb_convert_case($nombres, MB_CASE_TITLE, 'UTF-8');
            $apellidos = $estado['apellidos'] ?? '';
            $cedulaLimpia = $estado['cedula'] ?? '';
            $nac = $estado['nac'] ?? 'V';

            // Guardar datos elementales en el modelo Paciente
            $paciente = Paciente::create([
                'apellidos' => $apellidos,
                'nombres' => $nombresFormateado,
                'nac' => $nac,
                'cedula' => $cedulaLimpia,
                'telefono' => $telefono,
                'fingreso' => \App\Clinica\FechaDelConsultorio::hoyComoTexto(),
            ]);

            // Limpiar estado de registro de caché
            Cache::forget($cacheKey);
            Cache::forget("wa_registro_{$telefono}");

            // Ofrecer el servicio (especialidades, médicos según la especialidad, o médico directo)
            $especialidades = $this->obtenerEspecialidadesDisponibles();
            Cache::put("wa_menu_especialidades_{$cleanTel}", $especialidades, now()->addHours(2));
            Cache::put("wa_menu_especialidades_{$telefono}", $especialidades, now()->addHours(2));

            $nombreCompleto = trim("{$paciente->nombres} {$paciente->apellidos}");

            $msg = "✅ *¡DATOS REGISTRADOS CON ÉXITO!*\n\n";
            $msg .= "👤 *Paciente:* {$nombreCompleto}\n";
            $msg .= "🆔 *Cédula:* {$nac}-{$cedulaLimpia}\n";
            $msg .= "📱 *Teléfono:* {$telefono}\n\n";
            $msg .= "Te damos una cordial bienvenida a *Doctorisimo*. A continuación, te ofrecemos nuestras especialidades médicas disponibles:\n\n";

            foreach ($especialidades as $idx => $esp) {
                $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
                $msg .= "{$numEmoji} {$esp}\n";
            }

            $msg .= "\nPor favor, responde con el número o nombre de la especialidad que buscas, o indícanos el nombre de tu doctor para agendar directamente tu cita. 😊";

            return $msg;
        }

        return null;
    }

    /**
     * Normaliza y separa la nacionalidad (V, E, P) y el número de cédula.
     */
    public function normalizarCedulaYNac(string $input): array
    {
        $limpio = trim($input);
        $nac = 'V';
        $cedula = '';

        if (preg_match('/^\s*([VEPvep])\s*[-–.]?\s*([\d.]+)/iu', $limpio, $matches)) {
            $nac = mb_strtoupper($matches[1]);
            $cedula = preg_replace('/\D/', '', $matches[2]);
        } else {
            $cedula = preg_replace('/\D/', '', $limpio);
        }

        if (empty($cedula)) {
            $cedula = trim(preg_replace('/[^a-zA-Z0-9]/', '', $limpio));
        }

        return [$nac, $cedula];
    }

    /**
     * Obtiene el contexto completo del paciente a partir de su número de teléfono.
     * Consulta Paciente, MedicoPaciente, Medico y Specialty.
     *
     * @param string $telefono
     * @return array
     */
    public function obtenerContextoPaciente(string $telefono): array
    {
        $paciente = $this->buscarPacientePorTelefono($telefono);
        $especialidades = $this->obtenerEspecialidadesDisponibles();

        // 1. Paciente NO registrado en la tabla pacientes
        if (!$paciente) {
            return [
                'existe' => false,
                'telefono' => $telefono,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => $especialidades,
            ];
        }

        // 2. Paciente SÍ registrado en la tabla pacientes
        $nombreCompleto = trim("{$paciente->nombres} {$paciente->apellidos}");
        $numhistoria = null;
        try {
            $numhistoria = $paciente->numhistoria ?? $paciente->historias()->value('numhistoria');
        } catch (\Throwable $e) {
            // Si la columna numhistoria no existe en la tabla pacientes
        }

        // Consultar el modelo relacional MedicoPaciente
        $medicosAsociados = [];
        try {
            $relaciones = MedicoPaciente::where(function ($q) use ($paciente, $numhistoria) {
                $q->where('paciente_id', $paciente->id);
                if (!empty($numhistoria)) {
                    $q->orWhere('numhistoria', $numhistoria);
                }
            })->get();
            $regs = $relaciones->pluck('reg_medico')->filter()->unique()->toArray();
            $medicoIds = $relaciones->pluck('medico_id')->filter()->unique()->toArray();

            if (!empty($regs) || !empty($medicoIds)) {
                $medicosQuery = Medico::with('specialties');

                if (!empty($regs) && !empty($medicoIds)) {
                    $medicosQuery->where(function ($q) use ($regs, $medicoIds) {
                        $q->whereIn('reg_medico', $regs)
                          ->orWhereIn('id', $medicoIds);
                    });
                } elseif (!empty($regs)) {
                    $medicosQuery->whereIn('reg_medico', $regs);
                } else {
                    $medicosQuery->whereIn('id', $medicoIds);
                }

                $medicos = $medicosQuery->get();

                foreach ($medicos as $m) {
                    $esp = $m->specialties->pluck('name')->first() ?? $m->biography ?? 'Medicina General';
                    $reg = $m->reg_medico ?? (string)$m->id;

                    $medicosAsociados[] = [
                        'id' => $m->id,
                        'reg_medico' => $reg,
                        'nombre' => trim("Dr(a). {$m->name} {$m->lastname}"),
                        'especialidad' => $esp,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error al consultar MedicoPaciente: ' . $e->getMessage());
        }

        return [
            'existe' => true,
            'telefono' => $telefono,
            'paciente_id' => $paciente->id,
            'nombre' => $nombreCompleto,
            'numhistoria' => $numhistoria,
            'medicos_asociados' => $medicosAsociados,
            'tiene_medicos' => !empty($medicosAsociados),
            'especialidades' => $especialidades,
        ];
    }

    /**
     * Genera el mensaje estructurado de WhatsApp para el primer contacto o saludo.
     */
    public function generarMensajePrimerContacto(array $contexto): string
    {
        $especialidades = $contexto['especialidades'] ?? [];

        // CASO 1: El teléfono NO existe en la tabla pacientes (Paciente Nuevo)
        if (!$contexto['existe']) {
            $msg = "¡Hola! Te damos la bienvenida a *Doctorisimo*. 👋\n\n";
            $msg .= "Notamos que tu número de teléfono no se encuentra registrado en nuestro sistema. Para poder brindarte atención médica y agendar tus citas, te ofrecemos registrarte.\n\n";
            $msg .= "Para completar tu registro, te solicitaremos los siguientes datos:\n";
            $msg .= "1️⃣ Tu número de cédula de identidad\n";
            $msg .= "2️⃣ Tus apellidos\n";
            $msg .= "3️⃣ Tus nombres\n\n";
            $msg .= "Para comenzar, por favor indícanos tu número de *cédula de identidad* (ejemplo: V-12345678):";
            return $msg;
        }

        // CASO 2: El teléfono SÍ existe en la tabla pacientes
        $nombre = $contexto['nombre'];
        $medicos = $contexto['medicos_asociados'] ?? [];
        $totalMedicos = count($medicos);

        // 2.A: Si son varios médicos en MedicoPaciente
        if ($totalMedicos > 1) {
            $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
            $msg .= "Vemos que en nuestro consultorio te has atendido anteriormente con los siguientes médicos:\n\n";

            foreach ($medicos as $idx => $med) {
                $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
                $msg .= "{$numEmoji} *{$med['nombre']}* ({$med['especialidad']})\n";
            }

            $msg .= "\n¿A qué médico deseas referenciar para agendar tu cita?\n";
            $msg .= "*(Escribe el número o el nombre de tu doctor, o indícanos si prefieres otra especialidad)*.";
            return $msg;
        }

        // 2.B: Si es 1 solo médico en MedicoPaciente
        if ($totalMedicos === 1) {
            $med = $medicos[0];
            $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
            $msg .= "Vemos que tu médico tratante registrado es el *{$med['nombre']}* ({$med['especialidad']}).\n\n";
            $msg .= "¿Deseas consultar la disponibilidad de cita con tu doctor habitual o prefieres buscar otra especialidad médica?\n\n";
            $msg .= "1️⃣ Consultar disponibilidad con {$med['nombre']}\n";
            $msg .= "2️⃣ Buscar otra especialidad médica";
            return $msg;
        }

        // 2.C: Si NO existe registro en MedicoPaciente
        $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
        $msg .= "¿A cuál especialidad médica deseas buscar un médico para agendar tu cita?\n\n";

        foreach ($especialidades as $idx => $esp) {
            $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
            $msg .= "{$numEmoji} {$esp}\n";
        }

        $msg .= "\nPor favor, responde con el número o nombre de la especialidad que buscas. 😊";
        return $msg;
    }

    /**
     * Resuelve una instancia del modelo Medico a partir de reg_medico, id o nombre/apellido.
     */
    public function resolverMedico(string $criterio): ?Medico
    {
        $criterio = trim($criterio);
        if ($criterio === '') {
            return null;
        }

        // 1. Búsqueda exacta por reg_medico o ID numérico
        $medico = Medico::with(['specialties', 'registro', 'medicalCenters'])
            ->where('reg_medico', $criterio)
            ->orWhere('id', $criterio)
            ->first();

        if ($medico) {
            return $medico;
        }

        // 2. Búsqueda mediante la tabla MedicoRegistro
        $reg = MedicoRegistro::where('reg_medico', $criterio)->first();
        if ($reg && $reg->medico) {
            return $reg->medico->load(['specialties', 'registro', 'medicalCenters']);
        }

        // 3. Limpiar prefijos de cortesía habituales
        $limpio = trim(preg_replace('/^(dr\.|dra\.|dr|dra|doctor|doctora)\s+/i', '', $criterio));

        // 4. Búsqueda por coincidencia de nombre, apellido o nombre completo
        $partes = array_values(array_filter(explode(' ', $limpio)));

        return Medico::with(['specialties', 'registro', 'medicalCenters'])
            ->where(function ($q) use ($limpio, $partes) {
                $q->where('name', 'like', "%{$limpio}%")
                  ->orWhere('lastname', 'like', "%{$limpio}%");

                if (count($partes) >= 2) {
                    $primer = $partes[0];
                    $ultimo = $partes[count($partes) - 1];
                    $q->orWhere(function ($sub) use ($primer, $ultimo) {
                        $sub->where('name', 'like', "%{$primer}%")
                            ->where('lastname', 'like', "%{$ultimo}%");
                    });
                }
            })
            ->first();
    }

    /**
     * Consulta el número de teléfono directo o de contacto del médico (modelo Medico).
     */
    public function obtenerTelefonoMedico(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        if (!$medico) {
            return [
                'encontrado' => false,
                'mensaje' => "No encontramos registrado(a) al médico '{$criterioMedico}' en nuestro sistema.",
            ];
        }

        $nombre = $medico->nombre_mostrar;
        $telefono = $medico->phone;

        return [
            'encontrado' => true,
            'medico' => $nombre,
            'reg_medico' => $medico->regMedicoPrincipal() ?? $medico->reg_medico,
            'telefono' => $telefono ?: 'No registrado',
            'email' => $medico->email ?: 'No registrado',
            'mensaje' => !empty($telefono)
                ? "📞 El número de teléfono de contacto de *{$nombre}* es *{$telefono}*."
                : "ℹ️ *{$nombre}* no tiene un número de teléfono de contacto personal registrado en el sistema.",
        ];
    }

    /**
     * Consulta las sedes, clínicas o centros médicos donde atiende el médico
     * a través de la relación medicalCenters (modelo MedicoMedicalCenter / MedicalCenter).
     */
    public function obtenerCentrosDondeAtiende(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        if (!$medico) {
            return [
                'encontrado' => false,
                'mensaje' => "No encontramos registrado(a) al médico '{$criterioMedico}' en nuestro sistema.",
            ];
        }

        $nombre = $medico->nombre_mostrar;
        $centros = $medico->medicalCenters;

        if ($centros->isEmpty()) {
            return [
                'encontrado' => true,
                'medico' => $nombre,
                'centros' => [],
                'mensaje' => "ℹ️ Actualmente no tenemos registradas sedes o clínicas asociadas para *{$nombre}*.",
            ];
        }

        $listaCentros = [];
        $textoCentros = "🏥 *Sedes donde atiende {$nombre}:*\n\n";

        foreach ($centros as $idx => $c) {
            $num = $this->obtenerNumeroEmoji($idx + 1);
            $ciudadStr = $c->city?->name ? " ({$c->city->name})" : '';
            $dirStr = $c->address ?: 'Dirección no especificada';
            $telStr = $c->phone ? "\n   📞 Teléfono: {$c->phone}" : '';

            $textoCentros .= "{$num} *{$c->name}*{$ciudadStr}\n   📍 Dirección: {$dirStr}{$telStr}\n\n";

            $listaCentros[] = [
                'id' => $c->id,
                'nombre' => $c->name,
                'direccion' => $c->address,
                'telefono' => $c->phone,
                'ciudad' => $c->city?->name,
                'estado' => $c->estado?->name,
            ];
        }

        return [
            'encontrado' => true,
            'medico' => $nombre,
            'centros' => $listaCentros,
            'mensaje' => trim($textoCentros),
        ];
    }

    /**
     * Consulta en la tabla evolucion la dirección del consultorio y los datos de contacto del médico (reg_medico).
     */
    public function obtenerDatosConsultorio(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        $regMedico = $medico?->regMedicoPrincipal() ?? $criterioMedico;

        try {
            $evolucion = Evolucion::where('reg_medico', $regMedico)->first();

            if (!$evolucion && $medico && $medico->email) {
                $evolucion = Evolucion::where('correo_med', $medico->email)->first();
            }

            $nombreMedico = $medico ? $medico->nombre_mostrar : "Dr(a). {$criterioMedico}";
            $especialidad = $evolucion?->especialidad
                ?: ($medico?->specialties->pluck('name')->first() ?? $medico?->biography ?? 'Consulta Médica');

            if (!$evolucion) {
                return [
                    'encontrado' => false,
                    'medico' => $nombreMedico,
                    'especialidad' => $especialidad,
                    'reg_medico' => $regMedico,
                    'telefono_medico' => $medico?->phone ?: 'No registrado',
                    'mensaje' => "No se encontraron datos registrados de la ubicación del consultorio para {$nombreMedico}.",
                ];
            }

            // Construir dirección limpia combinando linea_1, linea_2 y linea_3
            $lineas = array_filter([
                trim((string) $evolucion->linea_1),
                trim((string) $evolucion->linea_2),
                trim((string) $evolucion->linea_3),
            ]);
            $direccion = !empty($lineas) ? implode(', ', $lineas) : 'Dirección no especificada';

            $consultorioNombre = $evolucion->consultorio ?: 'Consultorio principal';
            $telefonoConsultorio = $evolucion->telefono ?: 'No especificado';
            $telefonoMedico = $medico?->phone ?: 'No especificado';

            $mensajeTexto = "📍 *Consultorio:* {$consultorioNombre}\n"
                . "🏢 *Dirección:* {$direccion} ({$evolucion->ciudad})\n"
                . "📞 *Teléfono del consultorio:* {$telefonoConsultorio}\n";
            if (!empty($medico?->phone)) {
                $mensajeTexto .= "📱 *Teléfono de contacto del médico:* {$medico->phone}\n";
            }

            return [
                'encontrado' => true,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'reg_medico' => $regMedico,
                'consultorio' => $consultorioNombre,
                'ciudad' => $evolucion->ciudad ?: 'No especificada',
                'direccion' => $direccion,
                'telefono' => $evolucion->telefono ?: ($medico?->phone ?: 'No especificado'),
                'telefono_consultorio' => $telefonoConsultorio,
                'telefono_medico' => $telefonoMedico,
                'correo' => $evolucion->correo_med ?: ($medico?->email ?: 'No especificado'),
                'mensaje' => trim($mensajeTexto),
            ];
        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::obtenerDatosConsultorio error: ' . $e->getMessage());
            return [
                'encontrado' => false,
                'error' => 'Error al consultar los datos del consultorio en la base de datos.',
            ];
        }
    }

    /**
     * Consulta en la tabla evolucion los días y horarios de atención del médico.
     */
    public function obtenerHorariosAtencion(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        $regMedico = $medico?->regMedicoPrincipal() ?? $criterioMedico;

        try {
            $evolucion = Evolucion::where('reg_medico', $regMedico)->first();

            if (!$evolucion && $medico && $medico->email) {
                $evolucion = Evolucion::where('correo_med', $medico->email)->first();
            }

            $nombreMedico = $medico ? $medico->nombre_mostrar : "Dr(a). {$criterioMedico}";
            $especialidad = $evolucion?->especialidad
                ?: ($medico?->specialties->pluck('name')->first() ?? 'Consulta Médica');

            if (!$evolucion) {
                return [
                    'encontrado' => false,
                    'medico' => $nombreMedico,
                    'especialidad' => $especialidad,
                    'reg_medico' => $regMedico,
                    'mensaje' => "No se registraron horarios de atención específicos en el sistema para {$nombreMedico}.",
                ];
            }

            $diasConfigurados = [];
            $mapaDias = [
                'lunes'     => ['nombre' => 'Lunes',     'inicio' => $evolucion->lunes_i,     'fin' => $evolucion->lunes_f],
                'martes'    => ['nombre' => 'Martes',    'inicio' => $evolucion->martes_i,    'fin' => $evolucion->martes_f],
                'miercoles' => ['nombre' => 'Miércoles', 'inicio' => $evolucion->miercoles_i, 'fin' => $evolucion->miercoles_f],
                'jueves'    => ['nombre' => 'Jueves',    'inicio' => $evolucion->jueves_i,    'fin' => $evolucion->jueves_f],
                'viernes'   => ['nombre' => 'Viernes',   'inicio' => $evolucion->vienes_i ?? $evolucion->viernes_i ?? null, 'fin' => $evolucion->viernes_f],
                'sabado'    => ['nombre' => 'Sábado',    'inicio' => $evolucion->sabado_i,    'fin' => $evolucion->sabado_f],
                'domingo'   => ['nombre' => 'Domingo',   'inicio' => $evolucion->domingo_i,   'fin' => $evolucion->domigo_f ?? $evolucion->domingo_f ?? null],
            ];

            foreach ($mapaDias as $campoDia => $info) {
                $activo = $evolucion->{$campoDia};
                $esActivo = !empty($activo) && !in_array(strtolower(trim((string)$activo)), ['0', 'n', 'no', 'false', 'none'], true);

                if ($esActivo) {
                    $horario = 'Horario no especificado';
                    if (!empty($info['inicio']) && !empty($info['fin'])) {
                        $horario = "{$info['inicio']} a {$info['fin']}";
                    } elseif (!empty($info['inicio'])) {
                        $horario = "Desde las {$info['inicio']}";
                    }
                    $diasConfigurados[] = "{$info['nombre']}: {$horario}";
                }
            }

            $citaPreviaBool = !empty($evolucion->cita_previa) && !in_array(strtolower(trim((string)$evolucion->cita_previa)), ['0', 'n', 'no', 'false'], true);
            $modalidad = $citaPreviaBool ? 'Por cita previa' : 'Por orden de llegada';

            return [
                'encontrado' => true,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'reg_medico' => $regMedico,
                'dias_atencion' => $diasConfigurados,
                'modalidad' => $modalidad,
                'tiempo_por_paciente' => $evolucion->tiempo_paci ? "{$evolucion->tiempo_paci} minutos aprox." : null,
                'cupos_maximos_diarios' => $evolucion->cantidad_paciente ?: self::LIMITE_CUPOS_DIARIOS,
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::obtenerHorariosAtencion error: ' . $e->getMessage());
            return [
                'encontrado' => false,
                'error' => 'Error al consultar los horarios de atención.',
            ];
        }
    }

    /**
     * Retorna una ficha integral del médico combinando identidad, consultorio (evolucion) y horarios.
     */
    public function obtenerInformacionMedico(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        $consultorio = $this->obtenerDatosConsultorio($criterioMedico);
        $horarios = $this->obtenerHorariosAtencion($criterioMedico);
        $telefono = $this->obtenerTelefonoMedico($criterioMedico);
        $centros = $this->obtenerCentrosDondeAtiende($criterioMedico);

        return [
            'medico' => $consultorio['medico'] ?? $horarios['medico'] ?? ($medico?->nombre_mostrar ?? $criterioMedico),
            'especialidad' => $consultorio['especialidad'] ?? $horarios['especialidad'] ?? 'Consulta',
            'reg_medico' => $consultorio['reg_medico'] ?? $horarios['reg_medico'] ?? $criterioMedico,
            'telefono_medico' => $telefono['telefono'] ?? ($medico?->phone ?: 'No registrado'),
            'centros_donde_atiende' => $centros['centros'] ?? [],
            'consultorio' => $consultorio,
            'horarios' => $horarios,
        ];
    }

    /**
     * Resuelve preguntas informativas directas de los pacientes sobre:
     * 1. Teléfono del médico (modelo Medico: campo phone).
     * 2. Dónde atiende el médico / clínicas / sedes (relación MedicoMedicalCenter).
     * 3. Teléfono o dirección del consultorio (tabla evolucion: consultorio, linea_1, telefono).
     */
    public function resolverPreguntaInformativa(string $mensaje, array $contexto = []): ?string
    {
        $limpio = trim($mensaje);

        // A) Dónde atiende el médico / clínicas / sedes
        $esDondeAtiende = preg_match('/(?:donde|d[oó]nde|en\s+qu[eé]\s+(?:cl[ií]nica|hospital|centro|sede))\s+(?:atiende|pasa\s+consulta|labora|trabaja|ubicar)/iu', $limpio);

        // B) Teléfono del médico (modelo Medico)
        $esTelefonoMedico = preg_match('/(?:tel[eé]fono|celular|tlf|n[uú]mero)\s+(?:del?\s+)?(?:m[eé]dico|doctor(?:a)?|dr\.?|dra\.?)/iu', $limpio)
            || preg_match('/(?:tel[eé]fono|celular|tlf|n[uú]mero)\s+(?:de\s+contacto\s+)?(?:de\s+)?(?:el\s+|la\s+)?(?:dr\.?|dra\.?|doctor(?:a)?)/iu', $limpio);

        // C) Teléfono o dirección del consultorio (tabla evolucion)
        $esConsultorio = preg_match('/consultorio/iu', $limpio) && (
            preg_match('/(?:donde\s+queda|d[oó]nde\s+queda|direcci[oó]n|ubicaci[oó]n|tel[eé]fono|tlf|n[uú]mero)/iu', $limpio)
        );

        // D) Dirección general del médico
        $esDireccionMedico = preg_match('/(?:donde\s+queda|d[oó]nde\s+queda|direcci[oó]n|ubicaci[oó]n)\s+(?:de(?:l)?\s+)?(?:el\s+|la\s+)?(?:dr\.?|dra\.?|doctor(?:a)?)/iu', $limpio);

        if (!$esDondeAtiende && !$esTelefonoMedico && !$esConsultorio && !$esDireccionMedico) {
            return null;
        }

        // Extraer nombre del médico si se menciona explícitamente
        $criterioMedico = null;
        if (preg_match('/(?:dr\.?|dra\.?|doctor(?:a)?|m[eé]dico)\s+([a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+)/iu', $limpio, $m)) {
            $cand = preg_replace('/\s+(por\s+favor|porfa|gracias|\?)\s*$/iu', '', trim($m[1]));
            $criterioMedico = trim($cand);
        }

        // Si no se extrajo del mensaje, pero el paciente tiene 1 solo médico registrado en su contexto
        if (empty($criterioMedico) && !empty($contexto['medicos_asociados']) && count($contexto['medicos_asociados']) === 1) {
            $criterioMedico = $contexto['medicos_asociados'][0]['reg_medico'] ?? $contexto['medicos_asociados'][0]['nombre'];
        }

        if (empty($criterioMedico)) {
            return null;
        }

        // 1. Dónde atiende el médico (sedes/MedicalCenter)
        if ($esDondeAtiende) {
            $res = $this->obtenerCentrosDondeAtiende($criterioMedico);
            return $res['mensaje'] ?? null;
        }

        // 2. Teléfono personal/profesional del médico (modelo Medico)
        if ($esTelefonoMedico && !$esConsultorio) {
            $res = $this->obtenerTelefonoMedico($criterioMedico);
            return $res['mensaje'] ?? null;
        }

        // 3. Consultorio (dirección o teléfono de evolucion)
        if ($esConsultorio || $esDireccionMedico) {
            $res = $this->obtenerDatosConsultorio($criterioMedico);
            if (!empty($res['encontrado']) && !empty($res['mensaje'])) {
                return "🏥 *Datos del consultorio de {$res['medico']}:*\n\n" . $res['mensaje'];
            }
            return $res['mensaje'] ?? null;
        }

        return null;
    }

    /**
     * Consulta el modelo Cola para encontrar el día más próximo donde le queda cupo al médico
     * buscando por su campo reg_medico o nombre.
     *
     * @param string $regMedico
     * @param int $diasMaximos
     * @return array
     */
    public function consultarProximoDiaDisponible(string $regMedico, int $diasMaximos = 30, int $opciones = 3): array
    {
        try {
            $medico = $this->resolverMedico($regMedico);
            $regFinal = $medico?->regMedicoPrincipal() ?? $regMedico;
            $nombreMedico = $medico ? $medico->nombre_mostrar : "Médico (Registro: {$regMedico})";
            $especialidad = $medico ? ($medico->specialties->pluck('name')->first() ?? 'Medicina General') : 'Consulta';

            if (! $medico) {
                return [
                    'disponible' => false,
                    'reg_medico' => $regFinal,
                    'medico' => $nombreMedico,
                    'mensaje' => "No se encontró al médico para consultar su agenda.",
                ];
            }

            // Consultar límite configurado en evolucion si existe (sólo para el `cupos_disponibles` de
            // compatibilidad: al paciente no se le muestran cupos).
            $limiteDiario = self::LIMITE_CUPOS_DIARIOS;
            try {
                $ev = Evolucion::where('reg_medico', $regFinal)->first();
                if ($ev && !empty($ev->cantidad_paciente) && (int)$ev->cantidad_paciente > 0) {
                    $limiteDiario = (int)$ev->cantidad_paciente;
                }
            } catch (\Throwable $e) {
                // Mantener límite por defecto
            }

            $hoy = \App\Clinica\FechaDelConsultorio::hoyComoFecha();
            $disponibles = [];
            $ocupadasPrimerDia = null;

            // Se ofrecen varios días, y **los mismos** que aceptaría la reserva: la comprobación es
            // `primerLugarDisponible`, la que usa el agendamiento, no una cuenta de cupos por día aparte.
            for ($i = 0; $i < $diasMaximos && count($disponibles) < max(1, $opciones); $i++) {
                $fechaEvaluar = $hoy->copy()->addDays($i);

                if ($fechaEvaluar->isSunday()) {
                    continue;
                }

                $lugar = $this->primerLugarDisponible($regFinal, $medico, $fechaEvaluar, 1);

                if (empty($lugar['disponible'])) {
                    continue;
                }

                if ($ocupadasPrimerDia === null) {
                    $ocupadasPrimerDia = Cola::where('reg_medico', $regFinal)
                        ->whereDate('fecha', $fechaEvaluar->toDateString())
                        ->count();
                }

                $disponibles[] = [
                    'fecha' => $fechaEvaluar->toDateString(),
                    'fecha_formateada' => ucfirst($fechaEvaluar->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')),
                    'hora' => $lugar['hora'] ?? null,
                ];
            }

            if ($disponibles === []) {
                return [
                    'disponible' => false,
                    'reg_medico' => $regFinal,
                    'medico' => $nombreMedico,
                    'mensaje' => "El {$nombreMedico} no cuenta con cupos disponibles en los próximos {$diasMaximos} días.",
                ];
            }

            return [
                'disponible' => true,
                'reg_medico' => $regFinal,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                // El primero, para quien todavía lea las claves sueltas; `opciones` es la lista completa.
                'fecha' => $disponibles[0]['fecha'],
                'fecha_formateada' => $disponibles[0]['fecha_formateada'],
                // Interno: **no se muestra** en el mensaje (decisión del usuario, 2026-10-18). Se mantiene
                // porque hay tests y consumidores que lo leen.
                'cupos_disponibles' => max(0, $limiteDiario - (int) $ocupadasPrimerDia),
                'opciones' => $disponibles,
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::consultarProximoDiaDisponible error: ' . $e->getMessage());
            return [
                'disponible' => false,
                'error' => 'No se pudo consultar la agenda en este momento.',
            ];
        }
    }

    /**
     * El texto con los días que se le ofrecen a la paciente. **Sin cupos**: cuántos lugares quedan es
     * información interna del consultorio (decisión del usuario, 2026-10-18). El formato vive en
     * `App\Support\DisponibilidadEnTexto`; esto queda como atajo por si se llama desde el servicio.
     *
     * @param  array<string,mixed>  $disponibilidad  lo que devuelve `consultarProximoDiaDisponible`
     */
    public function textoDeOpcionesDeDisponibilidad(array $disponibilidad): string
    {
        return DisponibilidadEnTexto::de($disponibilidad);
    }

    /**
     * Interpreta la respuesta de la paciente a esa lista: un número (1…n), un día («el martes») o una
     * referencia al primero («el primero», «el más pronto»). Devuelve la fecha `Y-m-d` o `null` si el
     * mensaje no elige ninguno (ahí decide el resto del flujo, con la primera opción).
     *
     * @param  array<string,mixed>  $pendiente  lo guardado en `Cache` al ofrecer los días
     */
    public function interpretarDiaElegido(string $mensaje, array $pendiente): ?string
    {
        $fechas = array_values(array_filter((array) ($pendiente['fechas'] ?? [])));

        if ($fechas === []) {
            return null;
        }

        $limpio = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $mensaje)));
        // Sin tildes, como la lista de días: «miércoles» tiene que encontrar «miercoles».
        $limpio = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $limpio);

        if ($limpio === '') {
            return null;
        }

        // "2" o "la 2" → la segunda opción.
        if (preg_match('/(?:^|\s)(\d)(?:\s|$)/', $limpio, $coincidencia)) {
            $indice = (int) $coincidencia[1] - 1;

            return $fechas[$indice] ?? null;
        }

        // "el primero", "el más pronto", "la primera".
        if (preg_match('/\b(primer|primera|primero|mas pronto|más pronto)\b/u', $limpio)) {
            return $fechas[0];
        }

        // "el martes" → la opción que cae ese día de la semana.
        $dias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

        foreach ($dias as $dia) {
            if (! str_contains($limpio, $dia)) {
                continue;
            }

            foreach ($fechas as $fecha) {
                $nombreDia = $this->diaDeLaSemana($fecha);

                if ($nombreDia !== null && str_contains($nombreDia, $dia)) {
                    return $fecha;
                }
            }
        }

        return null;
    }

    /** El día de la semana de una fecha `Y-m-d`, sin tildes y en minúsculas ("miercoles"). */
    private function diaDeLaSemana(string $fecha): ?string
    {
        try {
            $texto = mb_strtolower(Carbon::parse($fecha)->locale('es')->isoFormat('dddd'));
        } catch (\Throwable $e) {
            return null;
        }

        return str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $texto);
    }

    /**
     * Busca los médicos asociados a una especialidad dada.
     */
    public function buscarMedicosPorEspecialidad(string $nombreEspecialidad): array
    {
        try {
            $specialty = Specialty::where('name', 'like', "%{$nombreEspecialidad}%")->first();

            $medicos = collect();
            if ($specialty) {
                $medicos = $specialty->medicos()->get();
            }
            if ($medicos->isEmpty()) {
                $medicos = Medico::where('biography', 'like', "%{$nombreEspecialidad}%")->get();
            }

            $resultado = [];
            foreach ($medicos as $m) {
                $resultado[] = [
                    'id' => $m->id,
                    'reg_medico' => $m->reg_medico ?? (string)$m->id,
                    'nombre' => trim("Dr(a). {$m->name} {$m->lastname}"),
                    'especialidad' => $specialty->name ?? $m->biography ?? $nombreEspecialidad,
                ];
            }

            return [
                'especialidad' => $specialty->name ?? $nombreEspecialidad,
                'medicos' => $resultado,
            ];
        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::buscarMedicosPorEspecialidad error: ' . $e->getMessage());
            return [
                'especialidad' => $nombreEspecialidad,
                'medicos' => [],
            ];
        }
    }

    /**
     * Resuelve si el mensaje del usuario corresponde a la selección de una especialidad
     * ya sea por número de opción o por el nombre de la especialidad.
     */
    public function resolverSeleccionEspecialidad(string $mensaje, ?string $telefono = null): ?array
    {
        $limpio = trim($mensaje);
        if ($limpio === '') {
            return null;
        }

        $cleanTel = $telefono ? preg_replace('/\D/', '', $telefono) : '';

        // 1. Si es numérico, buscar en el menú presentado en caché o en las especialidades disponibles
        if (is_numeric($limpio)) {
            $indice = (int)$limpio - 1;
            $especialidadesMenu = ($cleanTel ? Cache::get("wa_menu_especialidades_{$cleanTel}") : null)
                ?: ($telefono ? Cache::get("wa_menu_especialidades_{$telefono}") : null)
                ?: $this->obtenerEspecialidadesDisponibles();

            if (isset($especialidadesMenu[$indice])) {
                $espElegida = $especialidadesMenu[$indice];
                return $this->buscarMedicosPorEspecialidad($espElegida);
            }
        }

        // 2. Si el texto coincide total o parcialmente con una especialidad
        $todasEspecialidades = Specialty::pluck('name')->all();
        if (empty($todasEspecialidades)) {
            $todasEspecialidades = $this->obtenerEspecialidadesDisponibles();
        }

        $limpioAscii = Str::ascii(mb_strtolower($limpio));

        foreach ($todasEspecialidades as $esp) {
            $espAscii = Str::ascii(mb_strtolower($esp));
            if (mb_stripos($limpioAscii, $espAscii) !== false || mb_stripos($espAscii, $limpioAscii) !== false) {
                return $this->buscarMedicosPorEspecialidad($esp);
            }
        }

        return null;
    }

    /**
     * Identifica si el usuario solicita directamente a un médico específico en su mensaje.
     */
    public function identificarSolicitudDirectaMedico(string $mensaje): ?array
    {
        $limpio = trim($mensaje);

        if (is_numeric($limpio) || mb_strlen($limpio) < 3) {
            return null;
        }

        // Descartar si el mensaje es una consulta sobre dirección, ubicación, horarios, teléfono o dónde atiende
        $patronesInformativos = [
            'donde queda', 'dónde queda', 'donde atiende', 'dónde atiende', 'donde consulta', 'dónde consulta',
            'donde trabaja', 'dónde trabaja', 'direccion', 'dirección', 'ubicacion', 'ubicación',
            'horario', 'horarios', 'dias atencion', 'días de atención', 'como llegar', 'cómo llegar',
            'telefono', 'teléfono', 'contacto', 'informacion', 'información'
        ];
        $limpioAscii = Str::ascii(mb_strtolower($limpio));
        foreach ($patronesInformativos as $patron) {
            if (str_contains($limpioAscii, Str::ascii($patron))) {
                return null;
            }
        }

        // Descartar palabras de confirmación, negación o saludos genéricos
        $palabrasComunes = ['si', 'sí', 'no', 'hola', 'menu', 'inicio', 'ok', 'gracias', 'ayuda', 'buenas'];
        if (in_array(mb_strtolower($limpio), $palabrasComunes, true)) {
            return null;
        }

        // 1. Extraer nombre tras patrones de cortesía o frases de cita
        if (preg_match('/(?:cita\s+(?:con\s+)?(?:el\s+|la\s+)?(?:dr\.|dra\.|doctor|doctora)?|dr\.|dra\.|doctor|doctora)\s+([a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+)/iu', $limpio, $m)) {
            $candidato = trim($m[1]);
            // Limpiar cortesías al final (ej: "por favor", "porfa", "gracias")
            $candidato = preg_replace('/\s+(por\s+favor|porfa|gracias|muchas\s+gracias)\s*$/iu', '', $candidato);
            $candidato = trim($candidato);

            if (mb_strlen($candidato) >= 3) {
                $medico = $this->resolverMedico($candidato);
                return [
                    'solicitado' => true,
                    'registrado' => $medico !== null,
                    'medico' => $medico,
                    'nombre_solicitado' => $medico ? $medico->nombre_mostrar : $candidato,
                ];
            }
        }

        // 2. Intentar resolver directamente con resolverMedico limpiando cortesías al final
        $sinCortesias = preg_replace('/\s+(por\s+favor|porfa|gracias|muchas\s+gracias)\s*$/iu', '', $limpio);
        $sinCortesias = trim($sinCortesias);
        $medicoDirecto = $this->resolverMedico($sinCortesias);

        if ($medicoDirecto) {
            return [
                'solicitado' => true,
                'registrado' => true,
                'medico' => $medicoDirecto,
                'nombre_solicitado' => $medicoDirecto->nombre_mostrar,
            ];
        }

        return null;
    }

    /**
     * Obtiene la lista de nombres de especialidades desde el modelo Specialty.
     */
    public function obtenerEspecialidadesDisponibles(): array
    {
        try {
            if (class_exists(Specialty::class)) {
                $lista = Specialty::orderBy('name')
                    ->pluck('name')
                    ->filter()
                    ->take(8)
                    ->values()
                    ->toArray();

                if (!empty($lista)) {
                    return $lista;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error cargando Specialty: ' . $e->getMessage());
        }

        return ['Medicina General', 'Ginecología y Obstetricia', 'Pediatría', 'Cardiología', 'Traumatología'];
    }

    /**
     * Ayudante para emojis numéricos
     */
    public function obtenerNumeroEmoji(int $numero): string
    {
        $emojis = [
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            5 => '5️⃣',
            6 => '6️⃣',
            7 => '7️⃣',
            8 => '8️⃣',
            9 => '9️⃣',
            10 => '🔟',
        ];

        return $emojis[$numero] ?? "{$numero}.";
    }

    /**
     * Reserva e inserta un registro de cita en la tabla 'cola'.
     *
     * La fila **no se escribe acá**: sale por `App\Actions\Agenda\CrearCita`, la misma Action de la web y
     * del sync del móvil, para que la cita nazca con el rastro de `sync_changes` y con la invariante de la
     * fila que esa Action garantiza (auditoría del chatbot, 2026-10-17, §6: «agendar por `CrearCita` o no
     * agendar»).
     *
     * **Dónde cae la cita** lo resuelve `ArmadorDeAgenda` + `Jornada`, el mismo lugar que la agenda de la
     * web y del móvil: el primer bloque de `office_schedules` que quede con lugar, en la primera fecha que
     * sirva, con la sede del bloque (`medical_center_id`), el número de la jornada y la hora del bloque.
     * Antes la hora era inventada (`08:00 + 20 min × turno`) y la cita nacía **sin sede**; un médico **sin
     * bloques configurados** (el caso de casi todas las instalaciones del legado) conserva esa cuenta
     * vieja como respaldo, porque `cola.hora_ini` es obligatoria.
     */
    public function reservarCitaEnCola(string $telefono, string $criterioMedico, ?string $fecha = null, ?string $motivo = null): array
    {
        try {
            // 0. Una cuenta de paciente bloqueada desde el panel no agenda por el chatbot.
            $bloqueo = app(BloqueoDePacientes::class);
            if ($bloqueo->cuentaBloqueada(null, $telefono, null) !== null) {
                return ['exito' => false, 'bloqueado' => true, 'mensaje' => BloqueoDePacientes::AVISO];
            }

            // 1. Identificar al paciente por su número de teléfono
            $paciente = $this->buscarPacientePorTelefono($telefono);

            if ($paciente && $bloqueo->cuentaBloqueada($paciente->cedula, $paciente->telefono, $paciente->email) !== null) {
                return ['exito' => false, 'bloqueado' => true, 'mensaje' => BloqueoDePacientes::AVISO];
            }

            if (!$paciente) {
                // Registrar temporalmente al paciente nuevo por WhatsApp
                $paciente = Paciente::create([
                    'nombres' => 'Paciente',
                    'apellidos' => 'WhatsApp',
                    'telefono' => $telefono,
                ]);
            }

            $nombrePaciente = trim("{$paciente->nombres} {$paciente->apellidos}");
            if ($nombrePaciente === 'Paciente WhatsApp' || empty($nombrePaciente)) {
                $nombrePaciente = "Paciente ({$telefono})";
            }

            $pacienteId = $paciente->id;
            $numhistoria = null;
            try {
                $numhistoria = $paciente->numhistoria ?? $paciente->historias()->value('numhistoria');
            } catch (\Throwable $e) {}

            // 2. Resolver al médico
            $medico = $this->resolverMedico($criterioMedico);
            if (!$medico) {
                return [
                    'exito' => false,
                    'mensaje' => "No se pudo identificar al médico '{$criterioMedico}' para registrar la cita.",
                ];
            }

            $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
            $nombreMedico = $medico->nombre_mostrar;
            $especialidad = $medico->specialties->pluck('name')->first() ?? $medico->biography ?? 'Consulta Médica';

            // Validar si el paciente ya tiene una cita activa pendiente (atendido = 0) con este médico
            $citaPendiente = $this->obtenerCitaPendienteConMedico($paciente, $medico);
            if ($citaPendiente) {
                return [
                    'exito' => false,
                    'ya_tiene_cita' => true,
                    'cita' => $citaPendiente['cita'],
                    'mensaje' => $citaPendiente['mensaje'],
                    'mensaje_confirmacion' => $citaPendiente['mensaje'],
                ];
            }

            // 3. Determinar la fecha de partida
            if (empty($fecha)) {
                $disponible = $this->consultarProximoDiaDisponible($regMedico);
                if (!$disponible['disponible']) {
                    return [
                        'exito' => false,
                        'mensaje' => "El {$nombreMedico} no cuenta con cupos disponibles en los próximos días.",
                    ];
                }
                $fechaCita = Carbon::parse($disponible['fecha']);
            } else {
                $fechaCita = Carbon::parse($fecha);
                if (\App\Clinica\FechaDelConsultorio::esAnteriorAHoy($fechaCita)) {
                    return [
                        'exito' => false,
                        'mensaje' => "La fecha solicitada ({$fecha}) ya ha pasado. Por favor indica una fecha actual o futura.",
                    ];
                }
                if ($fechaCita->isSunday()) {
                    return [
                        'exito' => false,
                        'mensaje' => "Los días domingos no hay atención de consulta. Por favor selecciona un día de lunes a sábado.",
                    ];
                }
            }

            // 4. Dónde cae: el primer bloque con lugar (o el respaldo sin sedes configuradas).
            $lugar = $this->primerLugarDisponible($regMedico, $medico, $fechaCita);
            if (!$lugar['disponible']) {
                return [
                    'exito' => false,
                    'mensaje' => $lugar['mensaje'],
                ];
            }

            $fechaStr = $lugar['fecha'];
            $fechaCita = Carbon::parse($fechaStr);

            // 5. La fila de `cola` y su escritura por la Action compartida.
            $claveMedico = $medico->claveDeEvolucion((array) $regMedico) ?? (int) $medico->id;

            $fila = [
                'fecha'             => $fechaStr,
                'hora_ini'          => $lugar['hora'],
                'turno'             => Jornada::turnoDe($lugar['hora']),
                'numorden'          => $lugar['numorden'],
                'medical_center_id' => $lugar['centro_id'],
                // El escritorio lee la razón de `tipo`; el bot no tiene un código del catálogo, así que
                // deja la marca de origen y escribe lo que dijo la paciente en el `motivo` libre.
                'tipo'              => 'CONSULTA',
                'motivo'            => $motivo ?: 'Cita agendada vía WhatsApp',
                'monto'             => null,
                'medico'            => $claveMedico,
                'atendido'          => 0,
                'estado'            => Cola::ESTADO_CONFIRMADA_PACIENTE,
                'tiempo'            => 20,
            ];

            if ($numhistoria) {
                $fila['numhistoria'] = $numhistoria;
            } else {
                $fila['paciente_sinhistoria_id'] = $pacienteId;
            }

            $nuevaCita = $this->crearCita()->ejecutar($regMedico, $fila, Carbon::now(), 'whatsapp');

            // Vincular al paciente con el **consultorio** en `medico_pacientes` si aún no existe. El
            // vínculo es uno por paciente y tenant (`(reg_medico, paciente_id)`, migración del 2026-10-20):
            // si ya existía se conserva su `medico_id` y su `numhistoria`.
            try {
                MedicoPaciente::firstOrCreate([
                    'reg_medico'  => $regMedico,
                    'paciente_id' => $paciente->id,
                ], [
                    'medico_id'   => $medico->id,
                    'numhistoria' => $numhistoria,
                ]);
            } catch (\Throwable $e) {
                Log::warning('No se pudo vincular MedicoPaciente en reservarCitaEnCola: ' . $e->getMessage());
            }

            // 6. La confirmación: la sede del bloque si la hubo, y si no el consultorio de `evolucion`.
            $consultorio = $lugar['sede'] !== null
                ? [
                    'consultorio' => $lugar['sede']->office_number ?: ($lugar['sede']->medicalCenter->name ?? 'Consultorio principal'),
                    'direccion' => $lugar['sede']->medicalCenter->address ?? 'Dirección no especificada',
                ]
                : $this->obtenerDatosConsultorio($regMedico);

            $fechaFormateada = ucfirst($fechaCita->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'));
            $horaCarbon = Carbon::createFromFormat('H:i', $lugar['hora']);

            $datosConfirmacion = [
                'cita_id' => $nuevaCita->id,
                'paciente' => $nombrePaciente,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'fecha' => $fechaFormateada,
                'hora_estimada' => \App\Support\HoraLocal::hora12($horaCarbon->format('H:i')),
                'turno' => $lugar['numorden'],
                'consultorio' => $consultorio['consultorio'] ?? 'Consultorio principal',
                'direccion' => $consultorio['direccion'] ?? 'Dirección no especificada',
            ];

            return [
                'exito' => true,
                'datos' => $datosConfirmacion,
                'mensaje_confirmacion' => $this->generarMensajeCitaRegistrada($datosConfirmacion),
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::reservarCitaEnCola error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'exito' => false,
                'error' => 'No fue posible registrar la cita en este momento: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * **Dónde entra una cita nueva**, a partir de una fecha: el primer bloque de `office_schedules` con
     * lugar, de la primera sede del médico que atienda ese día. Devuelve también el número de la jornada
     * (`Jornada::siguienteNumero()`) y la hora del bloque.
     *
     * Sin bloques configurados —el estado de las instalaciones del legado— se conserva la cuenta vieja
     * (`08:00 + 20 min` por paciente y el tope diario de `LIMITE_CUPOS_DIARIOS`), porque una cita sin hora
     * no es válida en el esquema.
     *
     * @return array{disponible: bool, mensaje?: string, fecha?: string, hora?: string, numorden?: int, centro_id?: ?int, sede?: ?Office}
     */
    public function primerLugarDisponible(string $regMedico, Medico $medico, Carbon $desde, int $diasMaximos = 30, ?int $anticipacionMinutos = null): array
    {
        $hoy = \App\Clinica\FechaDelConsultorio::hoyComoFecha();

        // Se empieza en la fecha pedida (o hoy si ya pasó): así `--dias` cuenta desde ahí y una consulta
        // por un día concreto mira ese día, no treinta desde hoy.
        $inicio = $desde->copy()->startOfDay()->lt($hoy) ? $hoy->copy() : $desde->copy()->startOfDay();

        for ($i = 0; $i < $diasMaximos; $i++) {
            $lugares = $this->lugaresDelDia($regMedico, $medico, $inicio->copy()->addDays($i), $anticipacionMinutos);

            if ($lugares !== []) {
                return $lugares[0];
            }
        }

        return [
            'disponible' => false,
            'mensaje' => "No hay lugar en la agenda del médico para los próximos {$diasMaximos} días.",
        ];
    }

    /**
     * **Los lugares de un día, uno por bloque de horario** (mañana y tarde son ofertas distintas), en el
     * orden de la agenda. Es el único cálculo de horas ofrecidas: lo usan el chatbot
     * (`primerLugarDisponible`) y el portal público (`DisponibilidadPublica`).
     *
     * Por orden de llegada la hora es el inicio del bloque; con hora de cita, el primer hueco libre del
     * bloque. Un lugar sólo se ofrece si su hora es >= ahora + `$anticipacionMinutos` (por defecto
     * `ANTICIPACION_MINIMA_MINUTOS`, 24 h; las urgencias podrán pasar otra, p. ej. 90). La regla se evalúa
     * por oferta: un bloque descartado no descarta a los demás del día.
     *
     * @return array<int,array{disponible: bool, fecha: string, hora: string, numorden: int, centro_id: ?int, sede: ?Office}>
     */
    public function lugaresDelDia(string $regMedico, Medico $medico, Carbon $fecha, ?int $anticipacionMinutos = null): array
    {
        $fecha = $fecha->copy()->startOfDay();

        // La agenda no atiende domingos: una regla del legado que el bot ya aplicaba.
        if ($fecha->isSunday() || $fecha->lt(\App\Clinica\FechaDelConsultorio::hoyComoFecha())) {
            return [];
        }

        $armador = $this->armador();
        $sedes = $armador->sedes($regMedico, $medico->id);
        $limite = \App\Clinica\FechaDelConsultorio::ahora()
            ->addMinutes($anticipacionMinutos ?? self::ANTICIPACION_MINIMA_MINUTOS);
        $conAnticipacion = fn (string $hora): bool => Carbon::createFromFormat(
            'Y-m-d H:i',
            $fecha->toDateString() . ' ' . $hora,
            \App\Clinica\FechaDelConsultorio::zona()
        )->gte($limite);

        if ($sedes->isEmpty()) {
            $limiteDiario = self::LIMITE_CUPOS_DIARIOS;
            try {
                $evolucion = Evolucion::where('reg_medico', $regMedico)->first();
                if ($evolucion && !empty($evolucion->cantidad_paciente) && (int) $evolucion->cantidad_paciente > 0) {
                    $limiteDiario = (int) $evolucion->cantidad_paciente;
                }
            } catch (\Throwable $e) {}

            $ocupadas = Cola::where('reg_medico', $regMedico)
                ->whereDate('fecha', $fecha->toDateString())
                ->count();
            $hora = Carbon::createFromTime(8, 0)->addMinutes($ocupadas * 20)->format('H:i');

            if ($ocupadas >= $limiteDiario || ! $conAnticipacion($hora)) {
                return [];
            }

            return [[
                'disponible' => true,
                'fecha' => $fecha->toDateString(),
                'hora' => $hora,
                'numorden' => $ocupadas + 1,
                'centro_id' => null,
                'sede' => null,
            ]];
        }

        $lugares = [];

        foreach ($sedes as $sede) {
            foreach ($sede->bloquesDe($fecha->dayOfWeekIso) as $bloque) {
                $apertura = substr((string) $bloque->hora_inicio, 0, 5);
                $jornada = $armador->jornadaPara($regMedico, $sedes, $fecha->copy(), $sede->medical_center_id, $apertura);

                if ($jornada->llena()) {
                    continue;
                }

                $numero = $jornada->siguienteNumero();
                $duracion = $sede->duracion_cita ?: Office::DURACION_CITA_POR_DEFECTO;

                // Por orden de llegada la hora es la apertura del bloque (el paciente no elige hora);
                // con hora de cita, el primer hueco libre después de las que ya hay.
                $hora = $jornada->esPorOrdenDeLlegada()
                    ? $apertura
                    : Carbon::createFromFormat('H:i', $apertura)->addMinutes(($numero - 1) * $duracion)->format('H:i');

                $minutosHora = OfficeSchedule::aMinutos($hora);
                $minutosFin = OfficeSchedule::aMinutos($bloque->hora_fin);
                if ($minutosHora === null || ($minutosFin !== null && $minutosHora >= $minutosFin)) {
                    continue;
                }

                if (! $conAnticipacion($hora)) {
                    continue;
                }

                $lugares[] = [
                    'disponible' => true,
                    'fecha' => $fecha->toDateString(),
                    'hora' => $hora,
                    'numorden' => $numero,
                    'centro_id' => $sede->medical_center_id,
                    'sede' => $sede,
                ];
            }
        }

        return $lugares;
    }

    /** `CrearCita`, la Action compartida de la web y del sync del móvil (se resuelve del contenedor). */
    private function crearCita(): CrearCita
    {
        return $this->crearCita ??= app(CrearCita::class);
    }

    /** El armador de la agenda, la misma lectura que la web y el móvil (se resuelve del contenedor). */
    private function armador(): ArmadorDeAgenda
    {
        return $this->armador ??= app(ArmadorDeAgenda::class);
    }

    /**
     * Genera la plantilla oficial del mensaje de confirmación de cita registrada.
     */
    public function generarMensajeCitaRegistrada(array $datos): string
    {
        $msg = "✅ *¡CITA REGISTRADA CON ÉXITO!*\n\n";
        $msg .= "👤 *Paciente:* {$datos['paciente']}\n";
        $msg .= "👨‍⚕️ *Médico:* {$datos['medico']} ({$datos['especialidad']})\n";
        $msg .= "📅 *Fecha:* {$datos['fecha']}\n";
        $msg .= "🎫 *Turno asignado:* #{$datos['turno']}\n";
        $msg .= "⏰ *Hora sugerida:* {$datos['hora_estimada']}\n";
        $msg .= "📍 *Consultorio:* {$datos['consultorio']}\n";
        $msg .= "🏢 *Dirección:* {$datos['direccion']}\n\n";
        $msg .= "ℹ️ *Nota:* Te recomendamos llegar 15 minutos antes de tu hora estimada.\n\n";
        $msg .= "¿Deseas consultar alguna otra información o requerimiento para tu consulta? 😊";

        return $msg;
    }

    /**
     * Busca si un paciente tiene una cita activa pendiente (atendido = 0) con un médico determinado.
     */
    public function obtenerCitaPendienteConMedico($paciente, $criterioMedico): ?array
    {
        if (!$paciente) {
            return null;
        }

        $medico = $criterioMedico instanceof Medico ? $criterioMedico : $this->resolverMedico((string) $criterioMedico);
        if (!$medico) {
            return null;
        }

        $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
        $registrosDoctor = array_filter(array_unique(array_merge(
            array_filter([$regMedico, $medico->reg_medico]),
            $medico->registros()->pluck('reg_medico')->all()
        )));
        $claveMedico = $medico->claveDeEvolucion((array) $regMedico) ?? (int) $medico->id;

        $historias = [];
        if (!empty($paciente->numhistoria)) {
            $historias[] = $paciente->numhistoria;
        }
        try {
            $fromHistorias = $paciente->historias()->pluck('numhistoria')->all();
            $historias = array_merge($historias, $fromHistorias);
        } catch (\Throwable $e) {}
        try {
            $fromPivot = \DB::table('medico_pacientes')
                ->where('paciente_id', $paciente->id)
                ->whereNotNull('numhistoria')
                ->pluck('numhistoria')
                ->all();
            $historias = array_merge($historias, $fromPivot);
        } catch (\Throwable $e) {}
        $historias = array_values(array_unique(array_filter($historias)));

        $citaExistente = Cola::where('atendido', 0)
            ->where(function ($qDoctor) use ($registrosDoctor, $claveMedico, $medico) {
                if (!empty($registrosDoctor)) {
                    $qDoctor->whereIn('reg_medico', $registrosDoctor);
                }
                if ($claveMedico) {
                    $qDoctor->orWhere('medico', $claveMedico);
                }
                if (!empty($medico->id)) {
                    $qDoctor->orWhere('medico', $medico->id);
                }
            })
            ->where(function ($qPac) use ($paciente, $historias) {
                $qPac->where('paciente_sinhistoria_id', $paciente->id);
                if (!empty($historias)) {
                    $qPac->orWhereIn('numhistoria', $historias);
                }
            })
            ->orderByDesc('fecha')
            ->first();

        if (!$citaExistente) {
            return null;
        }

        $nombrePaciente = trim("{$paciente->nombres} {$paciente->apellidos}");
        if (empty($nombrePaciente) || $nombrePaciente === 'Paciente WhatsApp') {
            $nombrePaciente = $paciente->telefono ? "Paciente ({$paciente->telefono})" : 'Paciente';
        }

        return [
            'cita' => $citaExistente,
            'mensaje' => $this->generarMensajeCitaExistente($citaExistente, $nombrePaciente, $medico),
        ];
    }

    /**
     * Busca cita pendiente de atención (atendido = 0) identificando al paciente por su número de teléfono.
     */
    public function obtenerCitaPendienteConMedicoPorTelefono(string $telefono, $criterioMedico): ?array
    {
        $paciente = $this->buscarPacientePorTelefono($telefono);
        if (!$paciente) {
            return null;
        }

        return $this->obtenerCitaPendienteConMedico($paciente, $criterioMedico);
    }

    /**
     * Genera el mensaje informativo de cita existente cuando el paciente ya tiene una cita activa (atendido = 0).
     */
    public function generarMensajeCitaExistente(Cola $cita, string $nombrePaciente, Medico $medico): string
    {
        $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
        $consultorio = $this->obtenerDatosConsultorio($regMedico);
        $especialidad = $medico->specialties->pluck('name')->first() ?? $medico->biography ?? 'Consulta Médica';

        $fechaCarbon = Carbon::parse($cita->fecha);
        $fechaFormateada = ucfirst($fechaCarbon->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'));

        $horaStr = '';
        if (!empty($cita->hora_ini)) {
            try {
                $horaStr = \App\Support\HoraLocal::hora12(substr($cita->hora_ini, 0, 5), (string) $cita->hora_ini);
            } catch (\Throwable $e) {
                $horaStr = $cita->hora_ini;
            }
        } else {
            $horaStr = '8:00 AM';
        }

        $turno = $cita->numorden ?? 1;

        $msg = "⚠️ *YA TIENES UNA CITA PROGRAMADA*\n\n";
        $msg .= "Estimado(a) *{$nombrePaciente}*, verificamos que ya cuentas con una cita pendiente de atención con el/la *{$medico->nombre_mostrar}* ({$especialidad}):\n\n";
        $msg .= "📅 *Fecha:* {$fechaFormateada}\n";
        $msg .= "🎫 *Turno asignado:* #{$turno}\n";
        $msg .= "⏰ *Hora sugerida:* {$horaStr}\n";
        if (!empty($cita->motivo)) {
            $msg .= "📝 *Motivo:* {$cita->motivo}\n";
        }
        $msg .= "📍 *Consultorio:* " . ($consultorio['consultorio'] ?? 'Consultorio principal') . "\n";
        $msg .= "🏢 *Dirección:* " . ($consultorio['direccion'] ?? 'Dirección no especificada') . "\n\n";
        $msg .= "ℹ️ *Nota:* No es necesario registrar una nueva cita. Si deseas realizar algún cambio o necesitas información adicional, con gusto te atenderemos. 😊";

        return $msg;
    }
}
