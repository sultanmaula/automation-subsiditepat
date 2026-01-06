<div data-barcode-scanner class="flex items-center gap-3">
    <x-filament::button
        type="button"
        color="primary"
        size="sm"
        icon="heroicon-o-camera"
        data-barcode-scan-button="true"
        data-state-path="{{ $statePath ?? 'data.scan_barcode' }}"
        aria-label="Scan barcode"
        title="Scan barcode"
    />
    <div
        data-barcode-modal
        class="p-4"
        style="position: fixed; inset: 0; z-index: 50; display: none; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.6);"
        aria-hidden="true"
    >
        <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-lg">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-800"></span>
                <button
                    type="button"
                    class="text-xs text-gray-500"
                    data-barcode-close
                    aria-label="Close"
                >
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                </button>
            </div>
            <div class="relative overflow-hidden rounded-md bg-gray-900">
                <video data-barcode-video class="h-64 w-full object-cover" playsinline></video>
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="h-40 w-80 rounded-md border-2 border-amber-400 shadow-[0_0_20px_rgba(251,191,36,0.7)]"></div>
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500" data-barcode-status></p>
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            if (window.__workshopBarcodeScanner) {
                return;
            }
            window.__workshopBarcodeScanner = true;

            function findComponentId(el) {
                const root = el.closest('[wire\\:id]');
                return root ? root.getAttribute('wire:id') : null;
            }

            async function startScanner(root, statePath) {
                const modal = root.querySelector('[data-barcode-modal]');
                const video = root.querySelector('[data-barcode-video]');
                const status = root.querySelector('[data-barcode-status]');

                if (!('BarcodeDetector' in window)) {
                    modal.style.display = 'flex';
                    status.textContent = '';
                    return;
                }

                modal.style.display = 'flex';
                status.textContent = '';

                let stream;
                let active = true;
                const detector = new BarcodeDetector({
                    formats: ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e'],
                });
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                async function requestStream(constraints) {
                    return navigator.mediaDevices.getUserMedia({
                        video: constraints,
                        audio: false,
                    });
                }

                try {
                    stream = await requestStream({
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                    });
                } catch (error) {
                    try {
                        stream = await requestStream({
                            facingMode: 'user',
                            width: { ideal: 640 },
                            height: { ideal: 480 },
                        });
                    } catch (fallbackError) {
                        try {
                            stream = await requestStream({
                                width: { ideal: 640 },
                                height: { ideal: 480 },
                            });
                        } catch (finalError) {
                            const name = finalError && finalError.name ? finalError.name : 'UnknownError';
                            const message = finalError && finalError.message ? finalError.message : 'Unknown error';
                            status.textContent = 'Gagal akses kamera: ' + name + ' - ' + message;
                            return;
                        }
                    }
                }

                video.srcObject = stream;
                await video.play();
                status.textContent = '';

                const componentId = findComponentId(root);

                function drawCrop() {
                    const videoWidth = video.videoWidth || 0;
                    const videoHeight = video.videoHeight || 0;

                    if (!videoWidth || !videoHeight) {
                        return false;
                    }

                    const cropWidth = Math.floor(videoWidth * 0.85);
                    const cropHeight = Math.floor(videoHeight * 0.55);
                    const cropX = Math.floor((videoWidth - cropWidth) / 2);
                    const cropY = Math.floor((videoHeight - cropHeight) / 2);

                    canvas.width = cropWidth;
                    canvas.height = cropHeight;
                    ctx.drawImage(video, cropX, cropY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight);
                    return true;
                }

                async function scanLoop() {
                    if (!active) {
                        return;
                    }

                    try {
                        if (!drawCrop()) {
                            requestAnimationFrame(scanLoop);
                            return;
                        }

                        const barcodes = await detector.detect(canvas);
                        if (barcodes.length > 0) {
                            const code = barcodes[0].rawValue;
                            if (componentId && window.Livewire) {
                                window.Livewire.find(componentId).set(statePath, code);
                            }
                            stopScanner();
                            return;
                        }
                    } catch (error) {
                        // Ignore detection errors and keep scanning.
                    }

                    requestAnimationFrame(scanLoop);
                }

                function stopScanner() {
                    active = false;
                    modal.style.display = 'none';
                    if (stream) {
                        stream.getTracks().forEach((track) => track.stop());
                    }
                }

                root.__stopScanner = stopScanner;
                scanLoop();
            }

            document.addEventListener('click', function (event) {
                const button = event.target.closest('[data-barcode-scan-button]');
                if (!button) {
                    return;
                }

                const root = button.closest('[data-barcode-scanner]');
                if (!root) {
                    return;
                }

                const statePath = button.getAttribute('data-state-path') || 'data.scan_barcode';
                startScanner(root, statePath);
            });

            document.addEventListener('click', function (event) {
                const closeButton = event.target.closest('[data-barcode-close]');
                if (!closeButton) {
                    return;
                }

                const root = closeButton.closest('[data-barcode-scanner]');
                if (!root || !root.__stopScanner) {
                    return;
                }

                root.__stopScanner();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                const openModal = document.querySelector('[data-barcode-modal]:not(.hidden)');
                if (!openModal) {
                    return;
                }

                const root = openModal.closest('[data-barcode-scanner]');
                if (!root || !root.__stopScanner) {
                    return;
                }

                root.__stopScanner();
            });
        })();
    </script>
@endonce
