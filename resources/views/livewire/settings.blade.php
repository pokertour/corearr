<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\ServiceSetting;
use App\Services\MediaStack\MediaStackService;

new #[Layout('components.layouts.app')] #[Title('messages.settings')] class extends Component {
    public array $services = [
        'sonarr' => ['base_url' => '', 'api_key' => ''],
        'radarr' => ['base_url' => '', 'api_key' => ''],
        'qbittorrent' => ['base_url' => '', 'username' => '', 'password' => ''],
        'prowlarr' => ['base_url' => '', 'api_key' => ''],
        'jellyseerr' => ['base_url' => '', 'api_key' => ''],
        'emby' => ['base_url' => '', 'api_key' => ''],
        'jellyfin' => ['base_url' => '', 'api_key' => ''],
    ];

    public string $media_server_type = 'emby';

    public function mount()
    {
        $dbServices = ServiceSetting::all()->keyBy('service_name');
        
        foreach ($this->services as $name => $values) {
            if ($dbServices->has($name)) {
                $this->services[$name]['base_url'] = $dbServices->get($name)->base_url;
                if ($name === 'qbittorrent') {
                    $this->services[$name]['username'] = $dbServices->get($name)->username;
                    $this->services[$name]['password'] = $dbServices->get($name)->password;
                } else {
                    $this->services[$name]['api_key'] = $dbServices->get($name)->api_key;
                }
            }
        }

        if ($dbServices->has('jellyfin') && $dbServices->get('jellyfin')->is_active) {
            $this->media_server_type = 'jellyfin';
        } else {
            $this->media_server_type = 'emby';
        }
    }

    public function save(string $serviceName)
    {
        $data = ['base_url' => $this->services[$serviceName]['base_url'], 'is_active' => true];
        
        if ($serviceName === 'qbittorrent') {
            $data['username'] = $this->services[$serviceName]['username'];
            $data['password'] = $this->services[$serviceName]['password'];
        } else {
            $data['api_key'] = $this->services[$serviceName]['api_key'];
        }

        ServiceSetting::updateOrCreate(['service_name' => $serviceName], $data);

        if ($serviceName === 'emby') {
            ServiceSetting::where('service_name', 'jellyfin')->delete();
        } elseif ($serviceName === 'jellyfin') {
            ServiceSetting::where('service_name', 'emby')->delete();
        }

        $this->dispatch('notify', title: __('messages.settings_saved'), message: __('messages.settings_saved_msg', ['service' => ucfirst($serviceName)]), type: 'success');
    }

    public function test(string $serviceName, MediaStackService $mediaService)
    {
        $params = $this->services[$serviceName];

        if (empty($params['base_url'])) {
            $this->dispatch('notify', title: __('messages.url_missing_title'), message: __('messages.url_missing_msg'), type: 'error');
            return;
        }

        $result = $mediaService->testConnection($serviceName, $params);

        if ($result['success']) {
            $this->dispatch('notify', title: __('messages.connection_success_title'), message: $result['message'], type: 'success');
        } else {
            $this->dispatch('notify', title: __('messages.connection_fail_title'), message: $result['message'], type: 'error');
        }
    }
};

?>

<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.settings_title') }}</h2>
        <p class="text-sm text-zinc-500">{{ __('messages.settings_subtitle') }}</p>
    </div>

    @php
        $ports = ['sonarr' => '8989', 'radarr' => '7878', 'prowlarr' => '9696', 'qbittorrent' => '8080', 'jellyseerr' => '5055', 'emby' => '8096', 'jellyfin' => '8096'];
    @endphp

    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-zinc-800 dark:text-zinc-200 border-b border-zinc-200 dark:border-zinc-800 pb-2">Arr Services</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach(['sonarr' => 'Sonarr', 'radarr' => 'Radarr', 'qbittorrent' => 'qBittorrent', 'prowlarr' => 'Prowlarr'] as $key => $label)
                <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $label }}</h3>
                        @if(!empty($services[$key]['base_url']))
                            <span class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-green-500/10 text-[10px] font-bold text-green-600 dark:text-green-500 uppercase tracking-widest">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Configured
                            </span>
                        @endif
                    </div>
                    <form wire:submit="save('{{ $key }}')" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium border-core-primary text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.base_url_label') }}</label>
                            <input type="url" wire:model="services.{{ $key }}.base_url" placeholder="http://192.168.1.X:{{ $ports[$key] }}" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                        </div>
                        @if($key === 'qbittorrent')
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.username_label') }}</label>
                                    <input type="text" wire:model="services.{{ $key }}.username" placeholder="admin" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                                </div>
                                <div x-data="{ show: false }">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.password_label') }}</label>
                                    <div class="relative">
                                        <input :type="show ? 'text' : 'password'" wire:model="services.{{ $key }}.password" placeholder="••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition pr-10" />
                                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-400 hover:text-zinc-600 transition">
                                            <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                            <svg x-show="show" style="display:none;" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div x-data="{ show: false }">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.api_key_label') }}</label>
                                <div class="relative">
                                    <input :type="show ? 'text' : 'password'" wire:model="services.{{ $key }}.api_key" placeholder="••••••••••••••••••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition pr-10" />
                                    <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-400 hover:text-zinc-600 transition">
                                        <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                        <svg x-show="show" style="display:none;" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                    </button>
                                </div>
                            </div>
                        @endif
                        <div class="pt-2 flex items-center gap-3">
                            <button type="submit" class="cursor-pointer px-4 py-2 bg-core-primary text-white text-sm font-medium rounded-lg hover:bg-core-primary/90 transition shadow-md shadow-core-primary/20">
                                {{ __('messages.save_button') }}
                            </button>
                            <button type="button" 
                                    wire:click="test('{{ $key }}')"
                                    wire:loading.attr="disabled"
                                    class="cursor-pointer px-4 py-2 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 transition flex items-center gap-2">
                                <svg wire:loading wire:target="test('{{ $key }}')" class="animate-spin h-4 w-4 text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span wire:loading.remove wire:target="test('{{ $key }}')">{{ __('messages.test_connection_button') }}</span>
                                <span wire:loading wire:target="test('{{ $key }}')">{{ __('messages.testing_connection') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-zinc-800 dark:text-zinc-200 border-b border-zinc-200 dark:border-zinc-800 pb-2">{{ __('messages.media_server') }}</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Media Server Selection -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.media_server') }}</h3>
                        @if(!empty($services[$media_server_type]['base_url']))
                            <span class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-green-500/10 text-[10px] font-bold text-green-600 dark:text-green-500 uppercase tracking-widest">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Configured
                            </span>
                        @endif
                    </div>
                    <div class="flex bg-zinc-100 dark:bg-zinc-950 p-1 rounded-lg">
                        <button type="button" wire:click="$set('media_server_type', 'emby')" class="px-3 py-1 text-sm font-medium rounded-md transition {{ $media_server_type === 'emby' ? 'bg-core-primary text-white shadow' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200' }}">
                            Emby
                        </button>
                        <button type="button" wire:click="$set('media_server_type', 'jellyfin')" class="px-3 py-1 text-sm font-medium rounded-md transition {{ $media_server_type === 'jellyfin' ? 'bg-core-primary text-white shadow' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200' }}">
                            Jellyfin
                        </button>
                    </div>
                </div>
                
                @php $activeSrv = $media_server_type; @endphp

                <form wire:submit="save('{{ $activeSrv }}')" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.base_url_label') }}</label>
                        <input type="url" wire:model="services.{{ $activeSrv }}.base_url" placeholder="http://192.168.1.X:8096" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                    </div>
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.api_key_label') }}</label>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" wire:model="services.{{ $activeSrv }}.api_key" placeholder="••••••••••••••••••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition pr-10" />
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-400 hover:text-zinc-600 transition">
                                <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                <svg x-show="show" style="display:none;" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                            </button>
                        </div>
                    </div>
                    <div class="pt-2 flex items-center gap-3">
                        <button type="submit" class="cursor-pointer px-4 py-2 bg-core-primary text-white text-sm font-medium rounded-lg hover:bg-core-primary/90 transition shadow-md shadow-core-primary/20">
                            {{ __('messages.save_button') }}
                        </button>
                        <button type="button" 
                                wire:click="test('{{ $activeSrv }}')"
                                wire:loading.attr="disabled"
                                class="cursor-pointer px-4 py-2 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 transition flex items-center gap-2">
                            <svg wire:loading wire:target="test('{{ $activeSrv }}')" class="animate-spin h-4 w-4 text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            </svg>
                            <span wire:loading.remove wire:target="test('{{ $activeSrv }}')">{{ __('messages.test_connection_button') }}</span>
                            <span wire:loading wire:target="test('{{ $activeSrv }}')">{{ __('messages.testing_connection') }}</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Jellyseerr -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.jellyseerr') }}</h3>
                    @if(!empty($services['jellyseerr']['base_url']))
                        <span class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-green-500/10 text-[10px] font-bold text-green-600 dark:text-green-500 uppercase tracking-widest">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Configured
                        </span>
                    @endif
                </div>
                <form wire:submit="save('jellyseerr')" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.base_url_label') }}</label>
                        <input type="url" wire:model="services.jellyseerr.base_url" placeholder="http://192.168.1.X:5055" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                    </div>
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.api_key_label') }}</label>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" wire:model="services.jellyseerr.api_key" placeholder="••••••••••••••••••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition pr-10" />
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-400 hover:text-zinc-600 transition">
                                <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                <svg x-show="show" style="display:none;" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                            </button>
                        </div>
                    </div>
                    <div class="pt-2 flex items-center gap-3">
                        <button type="submit" class="cursor-pointer px-4 py-2 bg-core-primary text-white text-sm font-medium rounded-lg hover:bg-core-primary/90 transition shadow-md shadow-core-primary/20">
                            {{ __('messages.save_button') }}
                        </button>
                        <button type="button" 
                                wire:click="test('jellyseerr')"
                                wire:loading.attr="disabled"
                                class="cursor-pointer px-4 py-2 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 transition flex items-center gap-2">
                            <svg wire:loading wire:target="test('jellyseerr')" class="animate-spin h-4 w-4 text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            </svg>
                            <span wire:loading.remove wire:target="test('jellyseerr')">{{ __('messages.test_connection_button') }}</span>
                            <span wire:loading wire:target="test('jellyseerr')">{{ __('messages.testing_connection') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

