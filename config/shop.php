<?php

return [

    'name'    => env('SHOP_NAME', 'JAMUS MOTOR'),
    'tagline' => env('SHOP_TAGLINE', 'Bengkel & Toko Sparepart'),

    /*
     * Tautan ulasan Google Maps yang dicetak sebagai QR di kaki nota.
     *
     * Default di bawah adalah URL pencarian Maps — aman dan pasti mendarat di
     * listing yang benar, tapi customer masih perlu menekan "Tulis ulasan".
     * Untuk langsung membuka kotak bintang, ambil tautan pendek dari Google
     * Business Profile (bentuknya https://g.page/r/xxxxxxxx/review) dan
     * pasang di SHOP_MAPS_REVIEW_URL.
     *
     * Dikosongkan = blok ulasan tidak dicetak sama sekali.
     */
    'maps_review_url' => env(
        'SHOP_MAPS_REVIEW_URL',
        'https://www.google.com/maps/search/?api=1&query=Jamus+Motor+Menganto+Mojowarno+Jombang',
    ),

];
