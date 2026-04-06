<x-layouts.guest title="{{ __('messages.reset_password') }}">
    <div class="min-h-screen flex items-center justify-center bg-core-bg dark:bg-black py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 bg-white dark:bg-zinc-900 p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-xl shadow-core-primary/5">
            <div>
                <div class="w-16 h-16 mx-auto flex items-center justify-center">
                    <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-full h-full drop-shadow-md" />
                </div>
                <h2 class="mt-6 text-center text-2xl font-extrabold text-zinc-900 dark:text-white">
                    {{ __('messages.reset_password') }}
                </h2>
                <p class="mt-2 text-center text-sm text-zinc-500">
                    {{ __('Choisissez votre nouveau mot de passe sécurisé.') }}
                </p>
            </div>

            <form class="mt-8 space-y-6" method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ request()->route('token') }}">

                <div class="space-y-4">
                    <div>
                        <label for="email" class="sr-only">{{ __('messages.email') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email', request()->email) }}" required readonly class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-100 dark:bg-zinc-950/50 cursor-not-allowed focus:outline-none sm:text-sm" placeholder="{{ __('messages.email') }}">
                        @error('email') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="password" class="sr-only">{{ __('messages.new_password') }}</label>
                        <input id="password" name="password" type="password" required autofocus autocomplete="new-password" class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="{{ __('messages.new_password') }}">
                        @error('password') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="sr-only">{{ __('messages.confirm_password') }}</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="{{ __('messages.confirm_password') }}">
                    </div>
                </div>

                <div>
                    <button type="submit" class="cursor-pointer group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-xl text-white bg-core-primary hover:bg-core-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-core-primary transition shadow-lg shadow-core-primary/30">
                        {{ __('messages.reset_password') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.guest>
