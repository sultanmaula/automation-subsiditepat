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

        if (! $service->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $data          = json_decode($payload, true);
        $transactionId = $data['transaction']['id'] ?? null;

        if (! $transactionId) {
            return response()->json(['message' => 'Missing transaction id'], 400);
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
