<?php

namespace App\Services\Chatbot;

/**
 * **Filtro pre-LLM de off-topic y prompt injection** para el chatbot de WhatsApp (adaptado de
 * `off-topic-filter.service.ts` de DonDemand/ChatAI).
 *
 * La idea es cortar antes de gastar tokens los mensajes que no tienen nada que ver con la clínica: matemática,
 * trivia, código, traducciones, escritura creativa, intentos de sacar el prompt o de cambiarle las reglas al
 * modelo. El prompt del sistema sigue siendo la segunda línea de defensa.
 *
 * **Conservador por diseño**: sólo se disparan frases explícitas. Un mensaje clínico con números, una cédula,
 * una dirección o un teléfono **no** debe matchear. Ante la duda, se deja pasar al modelo.
 */
final class FiltroDeTema
{
    /**
     * Cada patrón apunta a una frase concreta, no a una palabra suelta.
     *
     * @var array<string,list<string>>
     */
    private const PATRONES = [
        // El falso prerrequisito va primero: suele envolver una pregunta de matemática o trivia y queremos
        // nombrar el ataque, no la excusa.
        'falso_prerequisito' => [
            '/\bte\s+(?:doy|paso|env[ií]o|comparto|digo|escribo)\b.{2,80}?\b(?:despu[eé]s|si|cuando)\b.{0,40}?\b(?:ayud|respond|hag|dig|dic|contest|cuent)\w*\b/iu',
            '/\b(?:dame|dime|resuelve(?:me)?|cont[eé]stame)\b.{2,80}?\bpara\s+(?:darte|decirte|enviarte|compartirte|continuar)\b/iu',
        ],
        'matematica' => [
            '/\b(?:cu[aá]nto\s+(?:es|da|son))\s+[\d\s(),.x×*+\-\/]+(?:\?|$)/iu',
            '/\b(?:calcula(?:me|r)?|resuelve(?:me)?)\s+[\d\s(),.x×*+\-\/]+(?:\?|$)/iu',
            '/\b\d{1,3}\s*[x×*+]\s*\d{1,3}\b/iu',
            '/\b\d{1,3}\s+-\s+\d{1,3}\b/iu',
            '/\b(?:ra[ií]z\s+cuadrada|ecuaci[oó]n|derivada|integral|porcentaje\s+de)\b/iu',
        ],
        'conocimiento' => [
            '/\b(?:capital|presidente|moneda|bandera|poblaci[oó]n)\s+de\s+\w+/iu',
            '/\bqu[eé]\s+(?:d[ií]a|hora|a[ñn]o)\s+es\b/iu',
            '/\b(?:expl[ií]came|expl[ií]ca|d[ií]me\s+sobre)\s+(?:la\s+)?(?:fotos[ií]ntesis|gravedad|teor[ií]a|relatividad|cu[aá]ntica)\b/iu',
        ],
        'escritura' => [
            '/\b(?:escr[ií]be(?:me)?|redacta(?:me)?|cu[eé]nta(?:me)?|inventa(?:me)?)\s+(?:un|una|el|la)\s+(?:poema|cuento|chiste|historia|carta|correo|email|ensayo|resumen)\b/iu',
        ],
        'codigo' => [
            '/\b(?:escribe|programa|codifica)\b.{0,40}\b(?:python|javascript|typescript|java|php|sql|html|css|react|node)\b/iu',
        ],
        'traduccion' => [
            '/\b(?:traduce|traducir|c[oó]mo\s+se\s+dice)\b.{1,60}?\b(?:al|en)\s+(?:ingl[eé]s|franc[eé]s|alem[aá]n|italiano|portugu[eé]s|chino|japon[eé]s)\b/iu',
        ],
        'meta' => [
            '/\b(?:ignora|olv[ií]da)\s+(?:todas\s+)?(?:tus\s+|las\s+)?(?:instrucciones|reglas|directivas)\b/iu',
            '/\b(?:act[uú]a\s+como|finge\s+ser|ahora\s+eres|a\s+partir\s+de\s+ahora\s+eres)\b/iu',
            '/\b(?:modo\s+(?:desarrollador|debug|admin|sudo)|developer\s+mode|jailbreak|DAN\s+mode)\b/iu',
        ],
        'extraccion' => [
            '/\b(?:cu[aá]l\s+es\s+tu|d[ií]me\s+tus?|mu[eé]strame\s+tus?|rep[ií]te\s+tus?)\s+(?:prompt|instrucciones|reglas|configuraci[oó]n|herramientas|tools)\b/iu',
            '/\b(?:qu[eé]|cu[aá]les?)\s+(?:herramientas|tools|funciones)\s+(?:tienes|usas|conoces)\b/iu',
        ],
    ];

    /** Devuelve la categoría detectada o `null` si el mensaje puede pasar al modelo. */
    public function detectar(string $mensaje): ?string
    {
        $mensaje = trim($mensaje);

        if ($mensaje === '') {
            return null;
        }

        foreach (self::PATRONES as $categoria => $patrones) {
            foreach ($patrones as $patron) {
                if (preg_match($patron, $mensaje) === 1) {
                    return $categoria;
                }
            }
        }

        return null;
    }

    public function respuesta(): string
    {
        return 'Soy el asistente de Doctorisimo y sólo puedo ayudarte con citas e información del consultorio. '
            . '¿Quieres agendar una cita o consultar la disponibilidad de un médico?';
    }
}
