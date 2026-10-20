<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'reg_medico',
        'must_change_password',
        'is_active',
        'blocked_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'must_change_password' => 'boolean',
        'is_active' => 'boolean',
        'ultima_actividad_web_at' => 'datetime',
    ];

    /** Roles Spatie que dan acceso al dashboard de administración. */
    public const ROLES_ADMIN = ['Root', 'Administrador'];

    /**
     * **¿Este usuario trabaja la consulta?** Es la pregunta de la que cuelga "la consulta es sólo del
     * médico" (PLAN-WEB.md §3.14, Nivel 3 del legado): la secretaría agenda, confirma y cobra, pero no
     * entra a la ficha clínica.
     *
     * Vive acá y no en cada pantalla por la misma razón que `esAdministrador()`: el **mismo** conjunto de
     * roles tiene que decidir tres cosas que no pueden divergir —el grupo `role:Root|Medico` de la ruta
     * del shell, el redirect del mostrador cuando la secretaría toca "Atender" y el botón "Editar
     * consulta" de la agenda—. La regla del menú lateral (qué módulos ve cada rol) es otra: esa sale de
     * `especialidad_modulo.visible_para` (WEB-4.1 no marca `consulta` como módulo implementado).
     */
    public function trabajaLaConsulta(): bool
    {
        return $this->hasAnyRole(['Root', 'Medico']);
    }

    /** Ficha de médico de esta cuenta (vínculo `medicos.user_id`). */
    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class, 'user_id');
    }

    /**
     * Usuarios con rol Root o Administrador. No usa el scope `role()` de Spatie a propósito: ese lanza
     * `RoleDoesNotExist` si algún rol de la lista todavía no existe (por ejemplo `Administrador` en un
     * servidor donde aún no se corrió el seeder).
     */
    public function scopeAdministradores($query)
    {
        return $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', self::ROLES_ADMIN));
    }

    /** Administrador = rol Root o Administrador. Un médico puede serlo también (una cuenta, varios roles). */
    public function esAdministrador(): bool
    {
        return $this->hasAnyRole(self::ROLES_ADMIN);
    }

    /**
     * Nombre de la ruta donde este usuario trabaja, y por lo tanto a dónde entra al iniciar sesión.
     *
     * El panel (`/dashboard`) quedó para administración; el médico y la secretaría trabajan en el
     * consultorio (`/clinica`), que es el mismo flujo con o sin clave temporal (lo usan el login y el
     * cambio de contraseña, para no repetir la regla en cada uno).
     */
    public function rutaDeInicio(): string
    {
        if (! $this->esAdministrador() && $this->hasAnyRole(['Medico', 'Secretaria'])) {
            return 'clinica.inicio';
        }

        // TODO (/consultorio): cuando exista el área del paciente, acá va su ruta.
        return 'dashboard';
    }

    /**
     * Las áreas a las que esta cuenta puede entrar, tal como las ofrece el login.
     *
     * Una cuenta con **un solo** área entra directo, como siempre. Con **más de una** (el caso real: un
     * médico que además administra) el login no puede adivinar cuál quiere, así que se le pregunta en
     * `/elegir-panel` (ver `rutaDeEntrada()`). La clave es estable porque el test y la vista la usan; el
     * rótulo y la descripción son los que ve la persona.
     *
     * @return array<int, array{clave: string, titulo: string, descripcion: string, icono: string, ruta: string}>
     */
    public function panelesDisponibles(): array
    {
        $paneles = [];

        if ($this->esAdministrador()) {
            $paneles[] = [
                'clave'       => 'administracion',
                'titulo'      => 'Administración',
                'descripcion' => 'Cuentas, especialidades, catálogos y servicios.',
                'icono'       => 'bi-shield-lock',
                'ruta'        => 'dashboard',
            ];
        }

        if ($this->hasAnyRole(['Medico', 'Secretaria'])) {
            $paneles[] = [
                'clave'       => 'consultorio',
                'titulo'      => 'Consultorio',
                'descripcion' => 'Agenda, pacientes, consulta y documentos.',
                'icono'       => 'bi-clipboard2-pulse',
                'ruta'        => 'clinica.inicio',
            ];
        }

        return $paneles;
    }

    /**
     * Ruta a la que se manda a esta cuenta al iniciar sesión: directo a su área si tiene una sola, o a
     * elegirla si tiene varias.
     */
    public function rutaDeEntrada(): string
    {
        return count($this->panelesDisponibles()) > 1 ? 'elegir-panel' : $this->rutaDeInicio();
    }

    /**
     * Comprueba si el usuario tiene una sesión activa en los últimos 5 minutos.
     */
    public function isOnline(): bool
    {
        return Cache::has('user-is-online-' . $this->id);
    }
}