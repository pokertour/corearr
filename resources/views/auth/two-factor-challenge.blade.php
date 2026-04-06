<x-layouts.guest title="Authentification à deux facteurs">
    <div class="min-h-screen flex items-center justify-center bg-core-bg dark:bg-black py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 bg-white dark:bg-zinc-900 p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-xl shadow-core-primary/5" x-data="{ recovery: false }">
            <div>
                <div class="w-16 h-16 mx-auto flex items-center justify-center">
                    <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-full h-full drop-shadow-md" />
                </div>
                <h2 class="mt-6 text-center text-2xl font-extrabold text-zinc-900 dark:text-white">
                    {{ __('messages.two_factor_auth') }}
                </h2>
                <p class="mt-2 text-center text-sm text-zinc-500" x-show="! recovery">
                    {{ __('messages.2fa_enter_code') }}
                </p>
                <p class="mt-2 text-center text-sm text-zinc-500" x-show="recovery" style="display: none;">
                    {{ __('Veuillez confirmer l\'accès à votre compte en saisissant l\'un de vos codes de récupération d\'urgence.') }}
                </p>
            </div>

            <form class="mt-8 space-y-6" method="POST" action="{{ route('two-factor.login') }}">
                @csrf

                <div class="space-y-4">
                    <div x-show="! recovery">
                        <label for="code" class="sr-only">Code</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autofocus autocomplete="one-time-code" class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="XXXXXX">
                    </div>

                    <div x-show="recovery" style="display: none;">
                        <label for="recovery_code" class="sr-only">Code de récupération</label>
                        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code" class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="abcdef-123456">
                    </div>
                </div>

                <div class="flex items-center justify-end">
                    <button type="button" class="text-sm font-medium text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 cursor-pointer transition"
                                    x-show="! recovery"
                                    x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">
                        {{ __('messages.2fa_recovery_toggle') }}
                    </button>

                    <button type="button" class="text-sm font-medium text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 cursor-pointer transition"
                                    x-show="recovery"
                                    x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })"
                                    style="display: none;">
                        {{ __('messages.2fa_totp_toggle') }}
                    </button>
                </div>

                <div>
                    <button type="submit" class="cursor-pointer group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-xl text-white bg-core-primary hover:bg-core-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-core-primary transition shadow-lg shadow-core-primary/30">
                        {{ __('messages.login_button') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.guest>
