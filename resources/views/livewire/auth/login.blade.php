<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

new class extends Component {
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function login()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $this->email)->first();

        if ($user && Hash::check($this->password, $user->password)) {
            // Check if 2FA is enabled AND confirmed
            if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
                session()->put('login.id', $user->id);
                session()->put('login.remember', $this->remember);

                return redirect()->route('two-factor.login');
            }

            // Standard login
            Auth::login($user, $this->remember);
            session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        $this->addError('email', trans('auth.failed'));
        $this->dispatch('notify', title: 'Échec de connexion', message: 'Identifiants incorrects.', type: 'error');
    }
};

?>

<div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-white dark:bg-zinc-900 p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-xl shadow-core-primary/5">
        <div>
            <div class="w-20 h-20 mx-auto flex items-center justify-center">
                <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-full h-full drop-shadow-md" />
            </div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-zinc-900 dark:text-white">
                {{ __('messages.login_title') }}
            </h2>
            <p class="mt-2 text-center text-sm text-zinc-500">
                {{ __('messages.login_subtitle') }}
            </p>
        </div>

        @if (session('status'))
            <div class="bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 p-4 rounded-xl text-sm font-medium border border-green-200 dark:border-green-800/50">
                {{ session('status') }}
            </div>
        @endif

        <form class="mt-8 space-y-6" wire:submit="login">
            <div class="space-y-4">
                <div>
                    <label for="email" class="sr-only">{{ __('messages.email') }}</label>
                    <input wire:model="email" id="email" name="email" type="email" autocomplete="email" required class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="{{ __('messages.email') }}">
                    @error('email') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="password" class="sr-only">{{ __('messages.password_label') }}</label>
                    <input wire:model="password" id="password" name="password" type="password" autocomplete="current-password" required class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="{{ __('messages.password_label') }}">
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input id="remember-me" wire:model="remember" name="remember-me" type="checkbox" class="h-4 w-4 text-core-primary focus:ring-core-primary border-zinc-300 dark:border-zinc-800 rounded dark:bg-zinc-900">
                    <label for="remember-me" class="ml-2 block text-sm text-zinc-900 dark:text-zinc-300">
                        {{ __('messages.remember_me') }}
                    </label>
                </div>

                <div class="text-sm">
                    <a href="{{ route('password.request') }}" wire:navigate class="font-medium text-core-primary hover:text-core-primary/80 transition">
                        {{ __('messages.forgot_password') }}
                    </a>
                </div>
            </div>

            <div>
                <button type="submit" class="cursor-pointer group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-xl text-white bg-core-primary hover:bg-core-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-core-primary transition shadow-lg shadow-core-primary/30">
                    {{ __('messages.login_button') }}
                </button>
            </div>
        </form>
    </div>
</div>
