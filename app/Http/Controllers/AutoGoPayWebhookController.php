<?php

namespace App\Http\Controllers;

use App\Models\Workshop\Sale;
use App\Services\AutoGoPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AutoGoPayWebhookController extends Controller
{
    /** Status transaksi yang dianggap lunas. */
    private const PAID_STATUSES = ['PAID', 'SETTLEMENT', 'SETTLED', 'SUCCESS', 'CAPTURE'];


    public function __invoke(Request $request, AutoGoPayService $service)
    {
        $payload   = $request->getContent();
        $signature = $this->extractSignature($request);

        $log = Log::channel('autogopay');
        $log->info('Webhook request', [
            'method'     => $request->method(),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query'      => $request->query(),
            'payload'    => $payload,
            'signature'  => $signature,
            'headers'    => collect($request->headers->all())
                ->except(['cookie', 'authorization'])
                ->all(),
        ]);

        // Probe pendaftaran callback URL: GET/HEAD atau POST tanpa body.
        // Tidak ada apa pun untuk diverifikasi, jadi cukup balas 200.
        if ($request->isMethodSafe() || $payload === '') {
            $log->info('Webhook probe accepted', ['method' => $request->method()]);

            return response()->json(['message' => 'ok']);
        }

        if (! $service->verifyWebhookSignature($payload, $signature)) {
            $log->warning('Webhook signature invalid', [
                'payload'   => $payload,
                'signature' => $signature,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $data  = json_decode($payload, true) ?: [];
        $event = $data['event'] ?? $request->header('X-Callback-Event');

        // Probe pendaftaran callback URL: AutoGoPay mengirim challenge dan
        // menunggu challenge itu dikembalikan beserta HMAC-nya sebagai bukti
        // kita memegang API key yang benar.
        if ($event === 'verification.challenge' || isset($data['challenge'])) {
            return $this->respondToChallenge($data, $service, $log);
        }

        $transaction = $data['transaction'] ?? [];

        // AutoGoPay mengirim transaction.transaction_id; 'id' hanya cadangan.
        $transactionId = $transaction['transaction_id'] ?? $transaction['id'] ?? null;
        $status        = strtoupper((string) ($transaction['status'] ?? ''));

        if (! $transactionId) {
            $log->warning('Webhook tanpa transaction id', ['event' => $event, 'payload' => $payload]);

            return response()->json(['message' => 'ok']);
        }

        if (! in_array($status, self::PAID_STATUSES, true)) {
            $log->info('Webhook diabaikan, status belum lunas', [
                'transaction_id' => $transactionId,
                'status'         => $status,
            ]);

            return response()->json(['message' => 'ok']);
        }

        // 'expired' ikut diselamatkan: kalau customer membayar tepat di batas 15
        // menit, penanda expired lokal bisa menang duluan dari callback. Vonis
        // AutoGoPay lebih sahih daripada timer kita sendiri.
        $updated = Sale::where('qris_transaction_id', $transactionId)
            ->whereIn('payment_status', ['pending', 'expired'])
            ->update([
                'payment_status' => 'settlement',
                'status'         => 'paid',
            ]);

        $log->info('Webhook processed', [
            'transaction_id' => $transactionId,
            'status'         => $status,
            'retry_attempt'  => $request->header('X-Retry-Attempt'),
            'updated_rows'   => $updated,
        ]);

        return response()->json(['message' => 'ok']);
    }

    private function respondToChallenge(array $data, AutoGoPayService $service, $log)
    {
        $challenge = (string) ($data['challenge'] ?? '');
        $signature = $service->sign($challenge);

        $log->info('Webhook challenge answered', [
            'challenge' => $challenge,
            'signature' => $signature,
        ]);

        // Dijawab dalam beberapa bentuk sekaligus karena spesifikasi AutoGoPay
        // tidak dipublikasikan: challenge mentah, HMAC-nya, dan penanda sukses.
        return response()->json([
            'success'   => true,
            'message'   => 'ok',
            'event'     => 'verification.challenge',
            'challenge' => $challenge,
            'signature' => $signature,
            'hash'      => $signature,
        ])->header('X-Signature', $signature);
    }

    private function extractSignature(Request $request): ?string
    {
        foreach (AutoGoPayService::SIGNATURE_HEADERS as $header) {
            $value = $request->header($header);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $request->query('signature');
    }
}
