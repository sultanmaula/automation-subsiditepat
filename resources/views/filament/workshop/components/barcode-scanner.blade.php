<div data-barcode-scanner class="wsbs-root">
    <x-filament::button
        type="button"
        color="primary"
        size="sm"
        icon="heroicon-o-camera"
        data-barcode-scan-button="true"
        data-state-path="{{ $statePath ?? 'data.scan_barcode' }}"
        aria-label="Scan barcode dari kamera"
        title="Scan barcode dari kamera"
    >
        Scan Kamera
    </x-filament::button>

    <div data-barcode-modal class="wsbs-overlay" aria-hidden="true">
        <div class="wsbs-card">
            <div class="wsbs-header">
                <div class="wsbs-header-title">
                    <x-filament::icon icon="heroicon-o-viewfinder-circle" class="wsbs-header-icon" />
                    <span>Scan Barcode Produk</span>
                </div>
                <button type="button" class="wsbs-close" data-barcode-close aria-label="Tutup">
                    <x-filament::icon icon="heroicon-o-x-mark" class="wsbs-close-icon" />
                </button>
            </div>

            <div class="wsbs-viewport">
                <video data-barcode-video class="wsbs-video" playsinline muted></video>

                <div class="wsbs-frame" data-barcode-frame>
                    <span class="wsbs-corner wsbs-corner-tl"></span>
                    <span class="wsbs-corner wsbs-corner-tr"></span>
                    <span class="wsbs-corner wsbs-corner-bl"></span>
                    <span class="wsbs-corner wsbs-corner-br"></span>
                    <span class="wsbs-laser" data-barcode-laser></span>
                    <x-filament::icon icon="heroicon-o-check-circle" class="wsbs-success-icon" data-barcode-success-icon />
                </div>

                <button type="button" class="wsbs-torch" data-barcode-torch hidden aria-label="Nyalakan senter">
                    <x-filament::icon icon="heroicon-o-bolt" class="wsbs-torch-icon" />
                </button>
            </div>

            <div class="wsbs-status" data-barcode-status>
                <span class="wsbs-spinner" data-barcode-spinner></span>
                <span data-barcode-status-text>Meminta akses kamera...</span>
            </div>
        </div>
    </div>
</div>

@once
    <style>
        .wsbs-root {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }

        .wsbs-overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 13, 25, 0.72);
            backdrop-filter: blur(3px);
            opacity: 0;
            transition: opacity 0.18s ease;
        }

        .wsbs-overlay.is-open {
            display: flex;
            opacity: 1;
        }

        .wsbs-card {
            width: 100%;
            max-width: 30rem;
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 20px 45px -12px rgba(0, 0, 0, 0.45);
            padding: 1rem;
            transform: scale(0.94) translateY(6px);
            opacity: 0;
            transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.18s ease;
        }

        .wsbs-overlay.is-open .wsbs-card {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        html.dark .wsbs-card {
            background: #1c1917;
        }

        .wsbs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .wsbs-header-title {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #292524;
        }

        html.dark .wsbs-header-title {
            color: #f5f5f4;
        }

        .wsbs-header-icon {
            width: 1.1rem;
            height: 1.1rem;
            color: #7c3aed;
            flex-shrink: 0;
        }

        .wsbs-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 9999px;
            color: #78716c;
            background: transparent;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .wsbs-close:hover {
            background: #f1f5f9;
            color: #1c1917;
        }

        html.dark .wsbs-close:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #f5f5f4;
        }

        .wsbs-close-icon {
            width: 1rem;
            height: 1rem;
        }

        .wsbs-viewport {
            position: relative;
            overflow: hidden;
            border-radius: 0.75rem;
            background: #0c0a09;
            aspect-ratio: 4 / 3;
        }

        .wsbs-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .wsbs-frame {
            position: absolute;
            inset: 0;
            margin: auto;
            width: 82%;
            height: 55%;
            pointer-events: none;
        }

        .wsbs-corner {
            position: absolute;
            width: 1.75rem;
            height: 1.75rem;
            border: 3px solid #fbbf24;
            transition: border-color 0.25s ease;
            filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.65));
        }

        .wsbs-frame.is-success .wsbs-corner {
            border-color: #4ade80;
            filter: drop-shadow(0 0 8px rgba(74, 222, 128, 0.75));
        }

        .wsbs-corner-tl {
            top: 0;
            left: 0;
            border-right: none;
            border-bottom: none;
            border-top-left-radius: 0.5rem;
        }

        .wsbs-corner-tr {
            top: 0;
            right: 0;
            border-left: none;
            border-bottom: none;
            border-top-right-radius: 0.5rem;
        }

        .wsbs-corner-bl {
            bottom: 0;
            left: 0;
            border-right: none;
            border-top: none;
            border-bottom-left-radius: 0.5rem;
        }

        .wsbs-corner-br {
            bottom: 0;
            right: 0;
            border-left: none;
            border-top: none;
            border-bottom-right-radius: 0.5rem;
        }

        .wsbs-laser {
            position: absolute;
            left: 4%;
            right: 4%;
            top: 0;
            height: 2px;
            border-radius: 9999px;
            background: linear-gradient(90deg, transparent, #fbbf24, transparent);
            box-shadow: 0 0 8px 1px rgba(251, 191, 36, 0.8);
            animation: wsbs-scan 2.1s ease-in-out infinite;
        }

        .wsbs-frame.is-success .wsbs-laser {
            opacity: 0;
        }

        @keyframes wsbs-scan {
            0% { top: 4%; opacity: 0; }
            10% { opacity: 1; }
            50% { top: 92%; opacity: 1; }
            60% { opacity: 0; }
            100% { top: 4%; opacity: 0; }
        }

        .wsbs-success-icon {
            position: absolute;
            inset: 0;
            margin: auto;
            width: 3rem;
            height: 3rem;
            color: #4ade80;
            filter: drop-shadow(0 0 10px rgba(74, 222, 128, 0.7));
            opacity: 0;
            transform: scale(0.6);
            transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .wsbs-frame.is-success .wsbs-success-icon {
            opacity: 1;
            transform: scale(1);
        }

        .wsbs-torch {
            position: absolute;
            bottom: 0.6rem;
            right: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 9999px;
            background: rgba(12, 10, 9, 0.55);
            color: #f5f5f4;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .wsbs-torch.is-on {
            background: #fbbf24;
            color: #1c1917;
        }

        .wsbs-torch-icon {
            width: 1.15rem;
            height: 1.15rem;
        }

        .wsbs-status {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            margin-top: 0.65rem;
            font-size: 0.75rem;
            color: #78716c;
            min-height: 1.1rem;
        }

        html.dark .wsbs-status {
            color: #a8a29e;
        }

        .wsbs-status.is-error {
            color: #dc2626;
        }

        .wsbs-status.is-success {
            color: #16a34a;
        }

        .wsbs-spinner {
            width: 0.85rem;
            height: 0.85rem;
            border-radius: 9999px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            opacity: 0.6;
            animation: wsbs-spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        .wsbs-spinner[hidden] {
            display: none;
        }

        @keyframes wsbs-spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <script>
        (function () {
            if (window.__workshopBarcodeScanner) {
                return;
            }
            window.__workshopBarcodeScanner = true;

            const ZXING_URL = '/vendor/zxing/zxing.min.js';

            function findComponentId(el) {
                const root = el.closest('[wire\\:id]');
                return root ? root.getAttribute('wire:id') : null;
            }

            function loadZxing() {
                if (window.ZXing) {
                    return Promise.resolve();
                }

                if (window.__zxingLoadPromise) {
                    return window.__zxingLoadPromise;
                }

                window.__zxingLoadPromise = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = ZXING_URL;
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Gagal memuat pustaka pemindai'));
                    document.head.appendChild(script);
                });

                return window.__zxingLoadPromise;
            }

            function setStatus(root, text, state) {
                const status = root.querySelector('[data-barcode-status]');
                const text_ = root.querySelector('[data-barcode-status-text]');
                const spinner = root.querySelector('[data-barcode-spinner]');

                status.classList.remove('is-error', 'is-success');
                if (state) {
                    status.classList.add(state);
                }

                text_.textContent = text;
                spinner.hidden = state === 'is-error' || state === 'is-success';
            }

            async function startScanner(root, statePath) {
                const overlay = root.querySelector('[data-barcode-modal]');
                const video = root.querySelector('[data-barcode-video]');
                const frame = root.querySelector('[data-barcode-frame]');
                const torchButton = root.querySelector('[data-barcode-torch]');

                overlay.classList.add('is-open');
                overlay.setAttribute('aria-hidden', 'false');
                frame.classList.remove('is-success');
                torchButton.hidden = true;
                torchButton.classList.remove('is-on');
                setStatus(root, 'Meminta akses kamera...');

                let stream = null;
                let active = true;
                let successHandled = false;
                let zxingReader = null;
                let torchOn = false;

                function closeScanner() {
                    if (!active) {
                        return;
                    }

                    active = false;
                    overlay.classList.remove('is-open');
                    overlay.setAttribute('aria-hidden', 'true');
                    frame.classList.remove('is-success');
                    torchButton.onclick = null;

                    if (zxingReader) {
                        try {
                            zxingReader.reset();
                        } catch (error) {
                            // Ignore cleanup errors.
                        }
                    }

                    if (stream) {
                        stream.getTracks().forEach((track) => track.stop());
                    }
                }

                // Wire up the close button / Escape key immediately, so the
                // user can dismiss the modal even while the camera permission
                // prompt is still pending or fails outright.
                root.__stopScanner = closeScanner;

                async function requestStream(constraints) {
                    return navigator.mediaDevices.getUserMedia({
                        video: constraints,
                        audio: false,
                    });
                }

                try {
                    stream = await requestStream({
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    });
                } catch (error) {
                    try {
                        stream = await requestStream({ facingMode: 'user' });
                    } catch (fallbackError) {
                        try {
                            stream = await requestStream(true);
                        } catch (finalError) {
                            if (!active) {
                                return;
                            }
                            const name = finalError && finalError.name ? finalError.name : 'UnknownError';
                            const message = finalError && finalError.message ? finalError.message : 'Unknown error';
                            setStatus(root, 'Gagal akses kamera: ' + name + ' - ' + message, 'is-error');
                            return;
                        }
                    }
                }

                if (!active) {
                    // The user closed the modal while the permission prompt was pending.
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }

                video.srcObject = stream;
                await video.play();

                if (!active) {
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }

                const componentId = findComponentId(root);
                const videoTrack = stream.getVideoTracks()[0];

                try {
                    const capabilities = videoTrack.getCapabilities ? videoTrack.getCapabilities() : {};
                    if (capabilities && capabilities.torch) {
                        torchButton.hidden = false;
                    }
                } catch (error) {
                    // Torch capability not available on this device.
                }

                torchButton.onclick = async function () {
                    torchOn = !torchOn;
                    try {
                        await videoTrack.applyConstraints({ advanced: [{ torch: torchOn }] });
                        torchButton.classList.toggle('is-on', torchOn);
                    } catch (error) {
                        torchOn = !torchOn;
                    }
                };

                function applyResult(code) {
                    if (!active || !code || successHandled) {
                        return;
                    }

                    successHandled = true;
                    active = false;
                    frame.classList.add('is-success');
                    setStatus(root, 'Barcode terdeteksi!', 'is-success');

                    if (navigator.vibrate) {
                        try {
                            navigator.vibrate(60);
                        } catch (error) {
                            // Ignore devices without vibration support.
                        }
                    }

                    window.setTimeout(function () {
                        if (componentId && window.Livewire) {
                            window.Livewire.find(componentId).set(statePath, code);
                        }
                        closeScanner();
                    }, 380);
                }

                if ('BarcodeDetector' in window) {
                    setStatus(root, 'Arahkan kamera ke barcode');
                    scanWithNativeDetector(video, applyResult, () => active);
                    return;
                }

                setStatus(root, 'Menyiapkan pemindai...');

                try {
                    await loadZxing();
                } catch (error) {
                    setStatus(root, 'Gagal memuat pemindai. Gunakan input manual.', 'is-error');
                    return;
                }

                if (!active) {
                    return;
                }

                try {
                    zxingReader = new window.ZXing.BrowserMultiFormatReader();
                    zxingReader.decodeFromStream(stream, video, (result) => {
                        if (result) {
                            applyResult(result.getText());
                        }
                    });
                    setStatus(root, 'Arahkan kamera ke barcode');
                } catch (error) {
                    setStatus(root, 'Pemindai tidak didukung di perangkat ini. Gunakan input manual.', 'is-error');
                }
            }

            function scanWithNativeDetector(video, applyResult, isActive) {
                const detector = new window.BarcodeDetector({
                    formats: ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code'],
                });
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

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
                    if (!isActive()) {
                        return;
                    }

                    try {
                        if (!drawCrop()) {
                            requestAnimationFrame(scanLoop);
                            return;
                        }

                        const barcodes = await detector.detect(canvas);
                        if (barcodes.length > 0) {
                            applyResult(barcodes[0].rawValue);
                            return;
                        }
                    } catch (error) {
                        // Ignore detection errors and keep scanning.
                    }

                    requestAnimationFrame(scanLoop);
                }

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

                const openOverlay = document.querySelector('[data-barcode-modal].is-open');
                if (!openOverlay) {
                    return;
                }

                const root = openOverlay.closest('[data-barcode-scanner]');
                if (!root || !root.__stopScanner) {
                    return;
                }

                root.__stopScanner();
            });
        })();
    </script>
@endonce
