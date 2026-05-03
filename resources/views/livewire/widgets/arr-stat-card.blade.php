<?php

use Livewire\Component;

new class extends Component {
    public string $serviceId = '';

    public string $label = '';

    public string $iconPath = '';

    public string $iconBgClass = '';

    public string $iconTextClass = '';

    public string $hoverBorderClass = '';

    public int|string $count = 0;

    public string $health = 'OK';
};

?>

<div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5 rounded-2xl shadow-sm {{ $hoverBorderClass }} transition duration-300 relative group">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl {{ $iconBgClass }} flex items-center justify-center {{ $iconTextClass }} group-hover:scale-110 transition-transform">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}" />
                </svg>
            </div>
            <div>
                <span class="text-[10px] font-black text-zinc-400 uppercase tracking-widest">{{ $serviceId }}</span>
                <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $label }}</h4>
            </div>
        </div>
        <div class="flex flex-col items-end">
            <span class="text-2xl font-black text-zinc-900 dark:text-white">{{ $count }}</span>
            <span class="text-[9px] font-bold {{ $health === 'OK' ? 'text-green-500' : 'text-yellow-500' }} uppercase tracking-tighter">{{ $health }}</span>
        </div>
    </div>
</div>
