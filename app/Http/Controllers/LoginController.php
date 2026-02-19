<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function showLogin()
    {
        if (session('authenticated')) {
            return redirect()->route('dashboard');
        }
        
        return view('login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $appPassword = config('app.password');
        
        if (Hash::check($request->password, $appPassword)) {
            session(['authenticated' => true]);
            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'password' => 'Invalid password'
        ]);
    }

    public function logout()
    {
        session()->forget('authenticated');
        return redirect()->away('/login');
    }
}