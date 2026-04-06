<?php

use Livewire\Component;

new class extends Component {};

?>

<div class="fixed top-4 right-4 z-50 flex flex-col gap-3 w-80 pointer-events-none"
     x-data="{ 
        toasts: [],
        add(e) {
            const id = Date.now().toString();
            this.toasts.push({ 
                id, 
                title: e.detail.title ?? e.detail.message, 
                message: e.detail.title ? (e.detail.message ?? '') : '', 
                type: e.detail.type ?? 'success' 
            });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id) }, 4000);
        }
     }"
     @notify.window="add($event)"
     @toast.window="add($event)">

    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="true"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-x-8 scale-95"
             x-transition:enter-end="opacity-100 translate-x-0 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-x-0 scale-100"
             x-transition:leave-end="opacity-0 translate-x-8 scale-95"
             class="pointer-events-auto flex items-start gap-3 p-4 rounded-2xl shadow-xl border
                    bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700/50"
             :class="{
                 'shadow-green-500/10': toast.type === 'success',
                 'shadow-red-500/10': toast.type === 'error',
                 'shadow-yellow-500/10': toast.type === 'warning',
                 'shadow-blue-500/10': toast.type === 'info',
             }">

            <!-- Icon -->
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                 :class="{
                     'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400': toast.type === 'success',
                     'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400': toast.type === 'error',
                     'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400': toast.type === 'warning',
                     'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400': toast.type === 'info',
                 }">
                <svg x-show="toast.type === 'success'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="toast.type === 'error'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <svg x-show="toast.type === 'warning'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <svg x-show="toast.type === 'info'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100" x-text="toast.title"></p>
                <p x-show="toast.message" class="text-xs text-zinc-500 mt-0.5 truncate" x-text="toast.message"></p>

                <!-- Progress bar -->
                <div class="mt-2 h-0.5 rounded-full overflow-hidden"
                     :class="{
                         'bg-green-100 dark:bg-green-900/30': toast.type === 'success',
                         'bg-red-100 dark:bg-red-900/30': toast.type === 'error',
                         'bg-yellow-100 dark:bg-yellow-900/30': toast.type === 'warning',
                         'bg-blue-100 dark:bg-blue-900/30': toast.type === 'info',
                     }">
                    <div class="h-full rounded-full animate-[shrink_4s_linear_forwards]"
                         :class="{
                             'bg-green-500': toast.type === 'success',
                             'bg-red-500': toast.type === 'error',
                             'bg-yellow-500': toast.type === 'warning',
                             'bg-blue-500': toast.type === 'info',
                         }">
                    </div>
                </div>
            </div>

            <!-- Close button -->
            <button @click="toasts = toasts.filter(t => t.id !== toast.id)"
                    class="cursor-pointer text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition mt-0.5">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </template>
</div>
