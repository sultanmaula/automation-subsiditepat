<?php

namespace App\Http\Controllers;

use App\Models\NikInputHistory;
use Illuminate\Http\JsonResponse;

class NikInputController extends Controller
{
    public function deleteLastMonth(): array
    {
        $data = NikInputHistory::where('input_month', now()->subMonth()->format('Y-m'));

        $count = $data->count();
        $data->delete();

        return [
            'success' => true,
            'count' => $count,
            'message' => "Berhasil menghapus {$count} data bulan lalu.",
        ];
    }

    public function deleteLastMonthApi(): JsonResponse
    {
        return response()->json($this->deleteLastMonth());
    }
}
