<div
    x-data="{ show: @entangle('show').live }"
    x-show="show"
    x-cloak
    style="z-index: 99999"
    class="fixed inset-0 flex items-center justify-center"
    aria-modal="true"
    role="dialog"
>
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-gray-950/75 backdrop-blur-sm"></div>

    {{-- Modal panel --}}
    <div class="relative mx-4 w-full max-w-md rounded-xl bg-white p-8 shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="mb-6 text-center">
            <div class="bg-danger-100 dark:bg-danger-500/20 mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full">
                <x-heroicon-o-lock-closed class="text-danger-600 dark:text-danger-400 h-7 w-7" />
            </div>
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">Change your password</h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                You are using the default password. You must set a new password before continuing.
            </p>
        </div>

        <form wire:submit="save" class="space-y-5">
            <div>
                <label for="fpc-password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    New password
                </label>
                <input
                    id="fpc-password"
                    type="password"
                    wire:model="password"
                    autocomplete="new-password"
                    class="focus:ring-primary-500 focus:border-primary-500 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 placeholder-gray-400 shadow-sm focus:ring-2 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    placeholder="Minimum 8 characters"
                />
                @error('password')
                    <p class="text-danger-600 dark:text-danger-400 mt-1 text-xs">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label
                    for="fpc-password-confirm"
                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    Confirm new password
                </label>
                <input
                    id="fpc-password-confirm"
                    type="password"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
                    class="focus:ring-primary-500 focus:border-primary-500 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 placeholder-gray-400 shadow-sm focus:ring-2 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    placeholder="Repeat new password"
                />
                @error('password_confirmation')
                    <p class="text-danger-600 dark:text-danger-400 mt-1 text-xs">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="bg-primary-600 hover:bg-primary-500 focus:ring-primary-500 w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus:ring-2 focus:ring-offset-2 focus:outline-none dark:focus:ring-offset-gray-900"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60 cursor-not-allowed"
            >
                <span wire:loading.remove wire:target="save">Update password</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </form>
    </div>
</div>
