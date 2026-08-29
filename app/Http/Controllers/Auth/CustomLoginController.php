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
            'email'     => 'required|email',
            'password'  => 'required|string',
            'user_type' => 'required|in:Medico,Paciente,Root',
        ]);

        $email    = $request->email;
        $password = $request->password;
        $userType = $request->user_type;
        $remember = $request->has('remember');

        // 1. Caso Root / Administrador (Modelo User)
        if ($userType === 'Root') {
            $user = User::where('email', $email)->first();

            if (!$user || !Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Las credenciales ingresadas son incorrectas para el usuario Root.'],
                ]);
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        // 2. Caso Médico (Modelo Medico)
        if ($userType === 'Medico') {
            $medico = Medico::where('email', $email)->first();
            if (!$medico) {
                throw ValidationException::withMessages([
                    'email' => ["No existe ningún médico registrado con el correo {$email}."],
                ]);
            }

            // Validar la contraseña directamente contra el modelo Medico
            if (!Hash::check($password, $medico->password)) {
                throw ValidationException::withMessages([
                    'password' => ['La contraseña ingresada es incorrecta.'],
                ]);
            }

            // Sincronizar o vincular con la tabla `users` para el Auth de Laravel / Spatie
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name'     => $medico->nombre ?? $medico->name ?? 'Dr. ' . $medico->apellido,
                    'email'    => $medico->email,
                    'password' => $medico->password, // Mismo hash ya encriptado
                ]);
                $medico->user_id = $user->id;
                $medico->save();
            }

            if (!$user->hasRole('Medico')) {
                $user->assignRole('Medico');
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        // 3. Caso Paciente (Modelo Paciente)
        if ($userType === 'Paciente') {
            $paciente = Paciente::where('email', $email)->first();

            if (!$paciente) {
                throw ValidationException::withMessages([
                    'email' => ["No existe ningún paciente registrado con el correo {$email}."],
                ]);
            }

            // Validar la contraseña directamente contra el modelo Paciente
            if (!Hash::check($password, $paciente->password)) {
                throw ValidationException::withMessages([
                    'password' => ['La contraseña ingresada es incorrecta.'],
                ]);
            }

            // Sincronizar o vincular con la tabla `users` para el Auth de Laravel / Spatie
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name'     => $paciente->nombre ?? $paciente->name ?? 'Paciente ' . $paciente->apellido,
                    'email'    => $paciente->email,
                    'password' => $paciente->password, // Mismo hash ya encriptado
                ]);
                $paciente->user_id = $user->id;
                $paciente->save();
            }

            if (!$user->hasRole('Paciente')) {
                $user->assignRole('Paciente');
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }
    }
}