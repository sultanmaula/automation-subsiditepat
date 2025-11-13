<div
    wire:loading.flex
    wire:target="callMountedTableAction"
    class="fixed inset-0 z-[1000] hidden items-center justify-center bg-gray-900/70 px-4"
>
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                <svg class="h-6 w-6 animate-spin-slow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m0-15L8.25 8.25M12 4.5l3.75 3.75M19.5 15a3.75 3.75 0 0 1-3.75 3.75h-7.5A3.75 3.75 0 0 1 4.5 15c0-1.75 1.25-3.21 2.938-3.61" />
                </svg>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-900">Sedang memproses data</p>
                <p class="text-xs text-gray-500">Mohon tunggu, kami sedang mengirim data ke Subsidi Tepat.</p>
            </div>
        </div>

        <div class="mt-5">
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="loading-bar h-full w-1/2 rounded-full bg-primary-500"></div>
            </div>
            <p class="mt-2 text-xs text-gray-500">Proses ini bisa memakan waktu beberapa detik.</p>
        </div>
    </div>

    @once
        <style>
            @keyframes loading-bar-slide {
                0% {
                    transform: translateX(-100%);
                }

                100% {
                    transform: translateX(200%);
                }
            }

            .loading-bar {
                animation: loading-bar-slide 1.4s linear infinite;
            }

            @keyframes spin-slow {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            .animate-spin-slow {
                animation: spin-slow 2s linear infinite;
            }
        </style>
    @endonce
</div>
