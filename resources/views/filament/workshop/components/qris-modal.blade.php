@php
    // Selalu ikut kolom qris_expires_at supaya QR hasil "Bayar Ulang" tidak
    // langsung tampil expired (dulu dihitung dari created_at + 900).
    $expiryTs  = ($sale->qris_expires_at ?? $sale->created_at->addMinutes(15))->timestamp;
    $pollUrl   = route('workshop.sale.payment-status', $sale->id);
    $isPending = $sale->payment_status !== 'settlement';

    // Dihitung di server supaya QR yang sudah mati tidak sempat berkedip
    // sedetik sebelum timer sisi klien menyusul.
    $isExpired = $sale->payment_status === 'expired' || $expiryTs <= now()->timestamp;
@endphp

<div
    x-data="{
        expiryTs: {{ $expiryTs }},
        pollUrl: '{{ $pollUrl }}',
        isPending: {{ $isPending ? 'true' : 'false' }},
        expiryDisplay: '',
        countdown: '',
        expired: {{ $isExpired && $isPending ? 'true' : 'false' }},
        paid: {{ $isPending ? 'false' : 'true' }},
        qrBroken: false,
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

    {{-- QR Image.
         qris_qr_url menunjuk ke server AutoGoPay, bukan storage kita, dan mati
         setelah beberapa jam. Karena itu gambarnya dilepas dari DOM begitu
         tidak relevan lagi — jangan sekadar ditutupi lapisan LUNAS, nanti yang
         tersisa cuma ikon gambar rusak. --}}
    @if($sale->qris_qr_url && $isPending)
    <template x-if="!paid && !expired && !qrBroken">
        <img
            src="{{ $sale->qris_qr_url }}"
            alt="QRIS {{ $sale->sale_number }}"
            x-on:error="qrBroken = true"
            style="width:220px;height:220px;border-radius:8px;border:1px solid #e5e7eb;display:block;"
        >
    </template>

    <template x-if="paid || expired || qrBroken">
        <div
            :style="'width:220px;height:220px;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;padding:12px;background:' + (paid ? '#16a34a' : (expired ? '#dc2626' : '#b45309'))"
        >
            <span style="font-size:44px;line-height:1;color:#fff" x-text="paid ? '✓' : (expired ? '✕' : '⚠')"></span>
            <span style="font-size:16px;font-weight:800;letter-spacing:2px;color:#fff"
                  x-text="paid ? 'LUNAS' : (expired ? 'EXPIRED' : 'QR GAGAL DIMUAT')"></span>
            <span x-show="qrBroken && !paid && !expired"
                  style="font-size:11px;color:rgba(255,255,255,.92);letter-spacing:0">Buat ulang QRIS</span>
        </div>
    </template>
    @elseif($sale->qris_qr_url)
    {{-- Sudah lunas sejak halaman dimuat: tidak ada alasan memuat QR sama sekali. --}}
    <div style="width:220px;height:220px;border-radius:8px;background:#16a34a;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;">
        <span style="font-size:44px;line-height:1;color:#fff">✓</span>
        <span style="font-size:16px;font-weight:800;letter-spacing:2px;color:#fff">LUNAS</span>
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
