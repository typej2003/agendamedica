<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomLoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'user_type' => 'required|in:Medico,Paciente',
        ]);

        $email = $request->email;
        $password = $request->password;
        $userType = $request->user_type;
        $remember = $request->has('remember');

        // 1. Verificar existencia según el tipo de acceso seleccionado
        if ($userType === 'Medico') {
            $entity = Medico::where('email', $email)->first();
            $roleName = 'Medico';
        } else {
            $entity = Paciente::where('email', $email)->first();
            $roleName = 'Paciente';
        }

        // Si la entidad (Médico/Paciente) no existe en su respectiva tabla
        if (!$entity) {
            throw ValidationException::withMessages([
                'email' => ["No existe ningún {$userType} registrado con este correo electrónico."],
            ]);
        }

        // 2. Buscar o vincular con la tabla `users`
        $user = User::where('email', $email)->first();

        if (!$user) {
            // Si el usuario general no existe en la tabla users, se crea automáticamente
            $user = User::create([
                'name'     => $entity->nombre ?? $entity->name ?? 'Usuario ' . $userType,
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
        } else {
            // Si ya existe en users, validamos la contraseña ingresada
            if (!Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['La contraseña ingresada es incorrecta.'],
                ]);
            }
        }

        // 3. Asignar el Rol Spatie correspondiente
        if (!$user->hasRole($roleName)) {
            $user->assignRole($roleName);
        }

        // 4. Iniciar sesión en Laravel
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}