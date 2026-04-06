<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;

new #[Layout('components.layouts.app')] #[Title('messages.profile')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $locale = '';
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';
    
    // Auth related
    public bool $showQrcode = false;

    public function mount()
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = $user->locale ?? 'en';
    }

    public function updateProfile()
    {
        $user = auth()->user();
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'locale' => 'required|in:en,fr',
        ]);

        $user->update([
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
        ]);

        App::setLocale($this->locale);
        Session::put('locale', $this->locale);

        $this->dispatch('notify', title: __('messages.profile_updated'), type: 'success');
        
        // Refresh to apply language change system-wide
        $this->redirect(route('profile'), navigate: true);
    }

    public function updatePassword()
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);

        auth()->user()->update([
            'password' => Hash::make($this->password),
        ]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->dispatch('notify', title: __('messages.password_changed'), type: 'success');
    }

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable)
    {
        $enable(auth()->user());
        $this->showQrcode = true;
        $this->dispatch('notify', title: __('messages.2fa_enabled'), type: 'success');
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable)
    {
        $disable(auth()->user());
        $this->showQrcode = false;
        $this->dispatch('notify', title: __('messages.2fa_disabled'), type: 'warning');
    }
};

?>

<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.profile') }}</h2>
        <p class="text-sm text-zinc-500">{{ __('messages.two_factor_description') }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
        <div class="space-y-8">
            <!-- Update Profile Info -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.profile_info') }}</h3>
                <form wire:submit="updateProfile" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.display_name') }}</label>
                        <input type="text" wire:model="name" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.email') }}</label>
                        <input type="email" wire:model="email" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                    </div>
                    
                    <!-- Language Selection -->
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.language_preferences') }}</label>
                        <select wire:model="locale" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition cursor-pointer">
                            <option value="en">🇺🇸 English</option>
                            <option value="fr">🇫🇷 Français</option>
                        </select>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="cursor-pointer px-4 py-2 bg-core-primary text-white text-sm font-medium rounded-lg hover:bg-core-primary/90 transition shadow-md shadow-core-primary/20">
                            {{ __('messages.update') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('messages.change_password') }}</h3>
                <form wire:submit="updatePassword" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.current_password') }}</label>
                        <input type="password" wire:model="current_password" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                        @error('current_password') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.new_password') }}</label>
                        <input type="password" wire:model="password" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                        @error('password') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.confirm_password') }}</label>
                        <input type="password" wire:model="password_confirmation" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="cursor-pointer px-4 py-2 bg-core-primary text-white text-sm font-medium rounded-lg hover:bg-core-primary/90 transition shadow-md shadow-core-primary/20">
                            {{ __('messages.change_password') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-8">
            <!-- 2FA Management -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-2">{{ __('messages.two_factor_auth') }}</h3>
                <p class="text-sm text-zinc-500 mb-6">{{ __('messages.two_factor_description') }}</p>

                @if(!auth()->user()->two_factor_secret)
                    <button wire:click="enableTwoFactor" class="cursor-pointer px-4 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-medium rounded-lg hover:bg-zinc-800 dark:hover:bg-zinc-100 transition shadow-sm">
                        {{ __('messages.enable_2fa') }}
                    </button>
                @else
                    @if($showQrcode)
                        <div class="mb-6 p-4 bg-white rounded-xl inline-block shadow-sm">
                            {!! auth()->user()->twoFactorQrCodeSvg() !!}
                        </div>
                        <div class="mb-6">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('messages.recovery_codes') }}</p>
                            <div class="bg-zinc-100 dark:bg-zinc-950 p-4 rounded-lg font-mono text-sm text-zinc-600 dark:text-zinc-400">
                                @foreach((array) auth()->user()->recoveryCodes() as $code)
                                    <div>{{ $code }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <button wire:click="disableTwoFactor" class="cursor-pointer px-4 py-2 bg-red-500/10 text-red-600 dark:text-red-400 text-sm font-medium rounded-lg hover:bg-red-500/20 transition">
                        {{ __('messages.disable_2fa') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
