<?php

namespace App\Http\Controllers;

use App\Models\Workshop\Sale;
use App\Services\AutoGoPayService;
use Illuminate\Http\Request;

class AutoGoPayWebhookController extends Controller
{
    public function __invoke(Request $request, AutoGoPayService $service)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Signature', '');

        // Log untuk debugging
        \Log::info('AutoGoPay Webhook Request', [
            'method' => $request->method(),
            'payload' => $payload,
            'signature' => $signature,
            'headers' => $request->headers->all(),
        ]);

        // Verify signature untuk semua request (GET dan POST)
        if (! $service->verifyWebhookSignature($payload, $signature)) {
            \Log::error('AutoGoPay Webhook Signature Invalid', [
                'payload' => $payload,
                'signature' => $signature,
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // Handle GET request untuk verification (setelah signature valid)
        if ($request->isMethod('get')) {
            return response()->json(['message' => 'ok']);
        }

        $data          = json_decode($payload, true);
        $transactionId = $data['transaction']['id'] ?? null;

        // Jika tidak ada transaction ID, kemungkinan ini adalah verification request
        // Return 200 OK agar AutoGoPay bisa verify endpoint
        if (! $transactionId) {
            return response()->json(['message' => 'ok']);
        }

        Sale::where('qris_transaction_id', $transactionId)
            ->where('payment_status', 'pending')
            ->update([
                'payment_status' => 'settlement',
                'status'         => 'paid',
            ]);

        return response()->json(['message' => 'ok']);
    }
}
