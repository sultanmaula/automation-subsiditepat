<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#111827">
    <title>Layar Pembayaran</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background:#111827; color:#f9fafb;
            min-height:100dvh; padding:1rem;
        }

        .head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
        .head h1 { font-size:1rem; font-weight:700; letter-spacing:.02em; }
        .dot { width:8px; height:8px; border-radius:50%; background:#22c55e; display:inline-block; margin-right:6px; }
        .dot.off { background:#ef4444; }
        .head small { color:#9ca3af; font-size:.75rem; }

        /* ---- daftar transaksi ---- */
        .card {
            width:100%; text-align:left; display:block;
            background:#1f2937; border:1px solid #374151; border-radius:.75rem;
            padding:.9rem 1rem; margin-bottom:.6rem; color:inherit;
            font:inherit; cursor:pointer;
        }
        .card:active { background:#374151; }
        .card-top { display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; }
        .amount { font-size:1.35rem; font-weight:700; letter-spacing:-.01em; }
        .sale-no { font-size:.7rem; color:#9ca3af; font-family:ui-monospace, monospace; }
        .cust { font-size:.8rem; color:#d1d5db; margin-top:.15rem; }
        .badge {
            font-size:.65rem; font-weight:700; padding:.2rem .5rem; border-radius:999px;
            text-transform:uppercase; letter-spacing:.05em; white-space:nowrap;
        }
        .badge.wait { background:#78350f; color:#fcd34d; }
        .badge.paid { background:#14532d; color:#86efac; }

        .empty { text-align:center; color:#6b7280; padding:4rem 1rem; font-size:.9rem; line-height:1.7; }
        .empty .big { font-size:2.5rem; display:block; margin-bottom:.5rem; }

        /* ---- layar QR ---- */
        .sheet { position:fixed; inset:0; background:#111827; display:flex; flex-direction:column; padding:1rem; z-index:10; }
        .back { background:none; border:none; color:#9ca3af; font:inherit; font-size:.9rem; padding:.5rem 0; text-align:left; cursor:pointer; }
        .sheet-body { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.9rem; }
        .qr-wrap { position:relative; background:#fff; padding:.9rem; border-radius:1rem; line-height:0; }
        .qr-wrap img { width:min(72vw, 320px); height:min(72vw, 320px); display:block; }
        .qr-total { font-size:2rem; font-weight:800; letter-spacing:-.02em; }
        .qr-sub { font-size:.8rem; color:#9ca3af; font-family:ui-monospace, monospace; }
        .count { font-size:1rem; font-weight:700; color:#fcd34d; font-variant-numeric:tabular-nums; }
        .count.low { color:#f87171; }
        .status { font-size:1rem; font-weight:600; display:flex; align-items:center; gap:.5rem; }
        .spin { width:15px; height:15px; border:2px solid #f59e0b; border-top-color:transparent; border-radius:50%; animation:sp .8s linear infinite; }
        @keyframes sp { to { transform:rotate(360deg); } }

        /* Panel pengganti QR. Bukan lapisan di atas gambar: gambarnya benar-benar
           dilepas dari DOM, karena URL-nya milik AutoGoPay dan akan mati. */
        .panel {
            width:calc(min(72vw, 320px) + 1.8rem);
            height:calc(min(72vw, 320px) + 1.8rem);
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:.5rem; line-height:1.35; text-align:center; padding:1rem;
        }
        .panel.done { background:#16a34a; }
        .panel.dead { background:#dc2626; }
        .panel.warn { background:#b45309; }
        .panel .mark { font-size:3.75rem; line-height:1; color:#fff; }
        .panel .word { font-size:1.35rem; font-weight:800; letter-spacing:.08em; color:#fff; }
        .panel .sub  { font-size:.85rem; font-weight:500; letter-spacing:0; color:rgba(255,255,255,.92); }

        .hint { font-size:.75rem; color:#6b7280; text-align:center; padding-bottom:.5rem; }
    </style>
</head>
<body>

<div
    x-data="display()"
    x-init="start()"
>
    {{-- ================= DAFTAR ================= --}}
    <div x-show="!active">
        <div class="head">
            <h1>Layar Pembayaran</h1>
            <small><span class="dot" :class="{ 'off': !online }"></span><span x-text="online ? 'Terhubung' : 'Terputus'"></span></small>
        </div>

        <template x-if="sales.length === 0">
            <div class="empty">
                <span class="big">🧾</span>
                Belum ada transaksi QRIS<br>yang menunggu pembayaran.
            </div>
        </template>

        <template x-for="s in sales" :key="s.id">
            <button class="card" @click="open(s)">
                <div class="card-top">
                    <span class="amount" x-text="rupiah(s.total)"></span>
                    <span class="badge" :class="s.payment_status === 'settlement' ? 'paid' : 'wait'"
                          x-text="s.payment_status === 'settlement' ? 'Lunas' : 'Menunggu'"></span>
                </div>
                <div class="cust" x-show="s.customer_name" x-text="s.customer_name"></div>
                <div class="sale-no" x-text="s.sale_number"></div>
            </button>
        </template>
    </div>

    {{-- ================= LAYAR QR ================= --}}
    <div class="sheet" x-show="active" x-cloak>
        <button class="back" @click="close()">‹ Kembali</button>

        <div class="sheet-body" x-show="active">
            <div class="qr-total" x-text="active ? rupiah(active.total) : ''"></div>

            <template x-if="showQr">
                <div class="qr-wrap">
                    <img :src="active?.qr_url" x-on:error="qrBroken = true" alt="QRIS">
                </div>
            </template>

            <template x-if="!showQr">
                <div class="qr-wrap panel" :class="panelTone">
                    <span class="mark" x-text="panelMark"></span>
                    <span class="word" x-text="panelWord"></span>
                    <span class="sub" x-show="qrBroken && !paid && !expired">Minta kasir buat ulang QRIS</span>
                </div>
            </template>

            <div class="count" :class="{ 'low': remaining < 120 }" x-show="!paid && !expired" x-text="countdown"></div>

            <div class="status">
                <template x-if="paid">
                    <span style="color:#4ade80">Pembayaran diterima</span>
                </template>
                <template x-if="!paid && !expired">
                    <span style="display:flex;align-items:center;gap:.5rem;color:#fbbf24">
                        <span class="spin"></span> Menunggu pembayaran…
                    </span>
                </template>
                <template x-if="expired && !paid">
                    <span style="color:#f87171">Kedaluwarsa — minta kasir buat ulang</span>
                </template>
            </div>

            <div class="qr-sub" x-text="active?.sale_number"></div>
        </div>

        <div class="hint" x-show="!paid && !expired">Arahkan layar ke customer untuk dipindai</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js" defer></script>
<script>
    function display() {
        return {
            sales: [], active: null, online: true,
            paid: false, expired: false, qrBroken: false,
            remaining: 0, countdown: '',
            wakeLock: null,

            // QR dirender hanya selama masih ada gunanya dipindai.
            get showQr() { return !!this.active && !this.paid && !this.expired && !this.qrBroken; },
            get panelTone() { return this.paid ? 'done' : (this.expired ? 'dead' : 'warn'); },
            get panelMark() { return this.paid ? '✓' : (this.expired ? '✕' : '⚠'); },
            get panelWord() { return this.paid ? 'LUNAS' : (this.expired ? 'EXPIRED' : 'QR GAGAL DIMUAT'); },

            rupiah(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); },

            start() {
                this.fetchSales();
                setInterval(() => this.fetchSales(), 3000);
                setInterval(() => this.tick(), 1000);
            },

            async fetchSales() {
                try {
                    const res  = await fetch('{{ route('workshop.display.data') }}', { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) throw new Error(res.status);
                    const data = await res.json();
                    this.online = true;
                    this.sales  = data;

                    if (this.active) {
                        const fresh = data.find(s => s.id === this.active.id);
                        if (fresh) {
                            this.active = fresh;
                            if (fresh.payment_status === 'settlement' && !this.paid) this.celebrate();
                        } else if (!this.paid) {
                            // hilang dari daftar = sudah kedaluwarsa
                            this.expired = true;
                        }
                    }
                } catch (e) {
                    this.online = false;
                }
            },

            tick() {
                if (!this.active || this.paid) return;
                this.remaining = Math.max(0, (this.active.expires_at || 0) - Math.floor(Date.now() / 1000));
                const m = String(Math.floor(this.remaining / 60)).padStart(2, '0');
                const s = String(this.remaining % 60).padStart(2, '0');
                this.countdown = m + ':' + s;
                if (this.remaining === 0) this.expired = true;
            },

            open(sale) {
                this.active   = sale;
                this.paid     = sale.payment_status === 'settlement';
                this.expired  = false;
                this.qrBroken = false;
                this.tick();
                this.keepAwake();
            },

            close() {
                this.active = null;
                this.paid = this.expired = this.qrBroken = false;
                if (this.wakeLock) { this.wakeLock.release().catch(() => {}); this.wakeLock = null; }
            },

            celebrate() {
                this.paid = true;
                // Pegawai memegang HP sambil melihat customer, bukan layar —
                // getar dan bunyi lebih dapat diandalkan daripada perubahan visual.
                if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
                this.beep();
                setTimeout(() => { if (this.paid) this.close(); }, 6000);
            },

            beep() {
                try {
                    const ctx  = new (window.AudioContext || window.webkitAudioContext)();
                    const osc  = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain); gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.25, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
                    osc.start(); osc.stop(ctx.currentTime + 0.4);
                } catch (e) {}
            },

            async keepAwake() {
                try {
                    if ('wakeLock' in navigator) this.wakeLock = await navigator.wakeLock.request('screen');
                } catch (e) {}
            },
        };
    }
</script>
<style>[x-cloak] { display:none !important; }</style>
</body>
</html>
