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
    ];

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

        // Feedback notification dispatch
        $this->dispatch('notify', title: __('messages.settings_saved'), message: __('messages.settings_saved_msg', ['service' => $serviceName]), type: 'success');
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

<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('messages.settings_title') }}</h2>
        <p class="text-sm text-zinc-500">{{ __('messages.settings_subtitle') }}</p>
    </div>

    @php
        $ports = ['sonarr' => '8989', 'radarr' => '7878', 'prowlarr' => '9696', 'qbittorrent' => '8080'];
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach(['sonarr' => 'Sonarr', 'radarr' => 'Radarr', 'qbittorrent' => 'qBittorrent', 'prowlarr' => 'Prowlarr'] as $key => $label)
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">{{ $label }}</h3>
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
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.password_label') }}</label>
                                <input type="password" wire:model="services.{{ $key }}.password" placeholder="••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
                            </div>
                        </div>
                    @else
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('messages.api_key_label') }}</label>
                            <input type="password" wire:model="services.{{ $key }}.api_key" placeholder="••••••••••••••••••••••••" class="w-full bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 rounded-lg px-4 py-2 text-zinc-900 dark:text-white focus:ring-2 focus:ring-core-primary outline-none transition" />
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
