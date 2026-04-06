<x-layouts.guest title="Mot de passe oublié">
    <div class="min-h-screen flex items-center justify-center bg-core-bg dark:bg-black py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 bg-white dark:bg-zinc-900 p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-xl shadow-core-primary/5">
            <div>
                <div class="w-16 h-16 mx-auto flex items-center justify-center">
                    <img src="/assets/logo/logo.svg" alt="CoreArr Logo" class="w-full h-full drop-shadow-md" />
                </div>
                <h2 class="mt-6 text-center text-2xl font-extrabold text-zinc-900 dark:text-white">
                    Mot de passe oublié
                </h2>
                <p class="mt-2 text-center text-sm text-zinc-500">
                    Saisissez votre email pour recevoir un lien de réinitialisation sécurisé.
                </p>
            </div>

            @if (session('status'))
                <div class="bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 p-4 rounded-xl text-sm font-medium border border-green-200 dark:border-green-800/50">
                    {{ session('status') }}
                </div>
            @endif

            <form class="mt-8 space-y-6" method="POST" action="{{ route('password.email') }}">
                @csrf
                <div>
                    <label for="email" class="sr-only">Adresse Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required autofocus class="appearance-none rounded-xl relative block w-full px-3 py-3 border border-zinc-300 dark:border-zinc-800 placeholder-zinc-500 text-zinc-900 dark:text-white bg-zinc-50 dark:bg-zinc-950 focus:outline-none focus:ring-2 focus:ring-core-primary focus:border-core-primary focus:z-10 sm:text-sm" placeholder="Adresse Email">
                    @error('email') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <button type="submit" class="cursor-pointer group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-xl text-white bg-core-primary hover:bg-core-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-core-primary transition shadow-lg shadow-core-primary/30">
                        Envoyer le lien
                    </button>
                </div>
                
                <div class="text-center mt-4">
                    <a href="{{ route('login') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition">
                        &larr; Retour à la connexion
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-layouts.guest>
