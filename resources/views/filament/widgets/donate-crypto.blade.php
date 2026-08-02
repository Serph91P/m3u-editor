<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div class="flex-shrink-0">
                <img
                    src="{{ asset('images/crypto-icons/crypto-coins.svg') }}"
                    alt="Crypto currencies"
                    class="h-12 w-12"
                />
            </div>

            <div class="flex-1">
                <h2 class="grid flex-1 text-base leading-6 font-semibold text-gray-950 dark:text-white">
                    Donate Crypto
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">We accept donations in various cryptocurrencies.</p>
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::modal icon="heroicon-o-qr-code" alignment="center" width="4xl">
                    <x-slot name="trigger">
                        <x-filament::button color="gray"> Donate now ₿ </x-filament::button>
                    </x-slot>

                    <x-slot name="heading">
                        <div class="flex items-center gap-3">
                            <img
                                src="{{ asset('/images/crypto-icons/crypto-coins.svg') }}"
                                alt="Crypto currencies"
                                class="h-8 w-8"
                            />
                            Donate Cryptocurrency
                        </div>
                    </x-slot>

                    <div
                        class="space-y-6"
                        x-init="
                            $nextTick(() => {
                                setTimeout(() => {
                                    if (typeof window.generateQRCodes === 'function') {
                                        window.generateQRCodes();
                                    }
                                }, 150);
                            })
                        "
                        x-intersect="
                            $nextTick(() => {
                                setTimeout(() => {
                                    if (typeof window.generateQRCodes === 'function') {
                                        window.generateQRCodes();
                                    }
                                }, 50);
                            })
                        "
                    >
                        <div class="text-center">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Have some spare coin? You can send it our way! We accept donations in various
                                cryptocurrencies. Simply scan the QR code or copy the address for your preferred
                                currency.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            @foreach (config('dev.crypto_addresses') as $currency)
                                <div class="space-y-4 rounded-lg bg-gray-50 p-6 dark:bg-gray-800">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="h-8 w-auto overflow-hidden">
                                                <img
                                                    src="{{ $currency['icon'] }}"
                                                    alt="{{ $currency['name'] }} icon"
                                                    class="h-full w-full"
                                                />
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900 dark:text-white">
                                                    {{ $currency['name'] }} ({{ $currency['symbol'] }})
                                                </h3>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-center">
                                        <div
                                            class="qr-code overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                                            data-text="{{ $currency['address'] }}"
                                            data-size="175"
                                        ></div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300">Address:</label>
                                        <div class="rounded border bg-white p-2 dark:bg-gray-700">
                                            <code class="font-mono text-xs break-all text-gray-600 dark:text-gray-300">{{ $currency['address'] }}</code>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-yellow-600 dark:text-yellow-400" />
                                <div class="text-sm text-yellow-800 dark:text-yellow-200">
                                    <p class="mb-1 font-medium">Important Notes:</p>
                                    <ul class="list-inside list-disc space-y-1 text-xs">
                                        <li>Double-check the address before sending</li>
                                        <li>Only send the corresponding cryptocurrency to each address</li>
                                        <li>Transactions are irreversible</li>
                                        <li>Network fees may apply</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::modal>
            </div>
        </div>
    </x-filament::section>

    @push('scripts')
        <script>
            // This script is now mainly for fallback, as we use x-init in the modal for direct triggering
            document.addEventListener('DOMContentLoaded', function () {
                // Use MutationObserver as fallback to detect when modal content is added to DOM
                const observer = new MutationObserver(function (mutations) {
                    mutations.forEach(function (mutation) {
                        if (mutation.type === 'childList') {
                            mutation.addedNodes.forEach(function (node) {
                                if (node.nodeType === Node.ELEMENT_NODE) {
                                    // Check if this is the modal or contains QR codes
                                    const qrCodes = node.querySelectorAll ? node.querySelectorAll('.qr-code') : [];
                                    if (qrCodes.length > 0 || node.classList?.contains('qr-code')) {
                                        // Small delay to ensure DOM is fully ready
                                        setTimeout(() => {
                                            if (typeof window.generateQRCodes === 'function') {
                                                window.generateQRCodes(null);
                                            }
                                        }, 100);
                                    }
                                }
                            });
                        }
                    });
                });

                // Start observing changes to the document body
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                });
            });
        </script>
    @endpush
</x-filament-widgets::widget>
