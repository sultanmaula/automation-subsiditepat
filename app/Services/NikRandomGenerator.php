<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class NikRandomGenerator
{
    public function generate(string $provinceCode, string $regencyCode, string $districtCode, ?Carbon $birthDate = null, ?bool $isFemale = null, ?int $sequence = null): string
    {
        $province = $this->normalizeCode($provinceCode);
        $regency = $this->normalizeCode($regencyCode);
        $district = $this->normalizeCode($districtCode);

        $birthDate ??= $this->randomBirthDate();
        $isFemale ??= (bool) random_int(0, 1);
        $sequence ??= random_int(1, 8);

        $day = $birthDate->day + ($isFemale ? 40 : 0);
        $datePart = sprintf('%02d%02d%02d', $day, $birthDate->month, $birthDate->year % 100);

        return sprintf(
            '%s%s%s%s%04d',
            $province,
            $regency,
            $district,
            $datePart,
            $sequence
        );
    }

    protected function randomBirthDate(): Carbon
    {
        $yearsBack = random_int(17, 60);
        $daysBack = random_int(0, 364);

        return Carbon::now()->subYears($yearsBack)->subDays($daysBack);
    }

    protected function normalizeCode(string $value): string
    {
        $digitsOnly = preg_replace('/\D+/', '', $value);

        return str_pad((string) ($digitsOnly ?? ''), 2, '0', STR_PAD_LEFT);
    }
}
