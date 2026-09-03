@php
    $expiryTs  = $sale->created_at->timestamp + 900; // +15 menit
    $pollUrl   = route('workshop.sale.payment-status', $sale->id);
    $isPending = $sale->payment_status !== 'settlement';
@endphp

<div
    x-data="{
        expiryTs: {{ $expiryTs }},
        pollUrl: '{{ $pollUrl }}',
        isPending: {{ $isPending ? 'true' : 'false' }},
        expiryDisplay: '',
        countdown: '',
        expired: false,
        paid: false,
        pad(n) { return String(n).padStart(2, '0'); },
        init() {
            const exp = new Date(this.expiryTs * 1000);
            this.expiryDisplay = this.pad(exp.getHours()) + ':' + this.pad(exp.getMinutes()) + ':' + this.pad(exp.getSeconds());

            if (!this.isPending) return;

            const countTimer = setInterval(() => {
                const rem  = Math.max(0, exp - Date.now());
                const mins = Math.floor(rem / 60000);
                const secs = Math.floor((rem % 60000) / 1000);
                this.countdown = '(' + this.pad(mins) + ':' + this.pad(secs) + ')';
                if (rem === 0) { this.expired = true; clearInterval(countTimer); }
            }, 1000);

            let attempts = 0;
            const pollTimer = setInterval(async () => {
                if (++attempts > 300 || this.expired) return clearInterval(pollTimer);
                try {
                    const res  = await fetch(this.pollUrl);
                    const data = await res.json();
                    if (data.payment_status === 'settlement') {
                        this.paid = true;
                        this.isPending = false;
                        clearInterval(pollTimer);
                        clearInterval(countTimer);
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } catch (e) {}
            }, 3000);
        }
    }"
    style="display:flex;flex-direction:column;align-items:center;gap:1rem;padding:1rem 0;"
>
    {{-- Countdown --}}
    @if($isPending)
    <div style="font-size:12px;color:#6b7280;text-align:center;">
        <span x-show="!expired && !paid">
            Berlaku hingga: <strong x-text="expiryDisplay"></strong>
            &nbsp;·&nbsp;
            <span x-text="countdown" :style="expired ? 'color:#dc2626;font-weight:700' : 'color:#d97706;font-weight:700'"></span>
        </span>
        <span x-show="expired" style="color:#dc2626;font-weight:600;">QRIS Expired</span>
    </div>
    @endif

    {{-- QR Image --}}
    @if($sale->qris_qr_url)
    <div style="position:relative;display:inline-block;">
        <img
            src="{{ $sale->qris_qr_url }}"
            alt="QRIS {{ $sale->sale_number }}"
            :style="expired || paid ? 'opacity:0.3;filter:blur(3px) grayscale(1);' : ''"
            style="width:220px;height:220px;border-radius:8px;border:1px solid #e5e7eb;display:block;"
        >
        <div x-show="paid" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
            <span style="background:#16a34a;color:#fff;font-size:16px;font-weight:bold;padding:8px 18px;border-radius:8px;letter-spacing:2px;transform:rotate(-15deg);display:inline-block;box-shadow:0 2px 8px rgba(0,0,0,.2);">LUNAS</span>
        </div>
        <div x-show="expired && !paid" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
            <span style="background:#dc2626;color:#fff;font-size:13px;font-weight:bold;padding:6px 14px;border-radius:8px;transform:rotate(-15deg);display:inline-block;">EXPIRED</span>
        </div>
    </div>
    @else
    <div style="font-size:12px;color:#999;">QR code tidak tersedia</div>
    @endif

    {{-- Status --}}
    <div style="display:flex;align-items:center;gap:6px;font-size:14px;font-weight:600;">
        @if(!$isPending)
            <span style="color:#16a34a;">✓ Pembayaran Diterima</span>
        @else
            <template x-if="paid">
                <span style="color:#16a34a;">✓ Pembayaran Diterima</span>
            </template>
            <template x-if="!paid && !expired">
                <span style="display:flex;align-items:center;gap:6px;">
                    <span style="display:inline-block;width:14px;height:14px;border:2px solid #f59e0b;border-top-color:transparent;border-radius:50%;animation:qris-spin 0.8s linear infinite;"></span>
                    <span style="color:#d97706;">Menunggu pembayaran...</span>
                </span>
            </template>
            <template x-if="expired">
                <span style="color:#dc2626;">✕ QRIS Expired — buat transaksi baru</span>
            </template>
        @endif
    </div>

    {{-- Link --}}
    @if($sale->qris_checkout_url)
    <a href="{{ $sale->qris_checkout_url }}" target="_blank"
       style="font-size:12px;color:#9333ea;text-decoration:underline;">
        Buka halaman pembayaran ↗
    </a>
    @endif
</div>

<style>
@keyframes qris-spin { to { transform: rotate(360deg); } }
</style>
