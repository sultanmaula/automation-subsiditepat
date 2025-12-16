<?php

namespace App\Support;

class RegionData
{
    protected static ?array $cache = null;

    public static function provinces(): array
    {
        return collect(self::load())
            ->mapWithKeys(fn(array $province) => [
                self::normalizeCode($province['code'] ?? '') => $province['name'] ?? '-',
            ])
            ->toArray();
    }

    public static function regencies(string $provinceCode): array
    {
        $province = self::findProvince($provinceCode);

        if (! $province) {
            return [];
        }

        return collect($province['regencies'] ?? [])
            ->mapWithKeys(fn(array $regency) => [
                self::normalizeCode($regency['code'] ?? '') => $regency['name'] ?? '-',
            ])
            ->toArray();
    }

    public static function districts(string $provinceCode, string $regencyCode): array
    {
        $regency = self::findRegency($provinceCode, $regencyCode);

        if (! $regency) {
            return [];
        }

        return collect($regency['districts'] ?? [])
            ->mapWithKeys(fn(array $district) => [
                self::normalizeCode($district['code'] ?? '') => $district['name'] ?? '-',
            ])
            ->toArray();
    }

    public static function findRegion(string $provinceCode, string $regencyCode, string $districtCode): ?array
    {
        $province = self::findProvince($provinceCode);
        $regency = $province ? self::findRegency($provinceCode, $regencyCode) : null;
        $district = $regency ? self::findDistrict($provinceCode, $regencyCode, $districtCode) : null;

        if (! $province || ! $regency || ! $district) {
            return null;
        }

        return [
            'province_code' => self::normalizeCode($province['code'] ?? ''),
            'province_name' => $province['name'] ?? '',
            'regency_code' => self::normalizeCode($regency['code'] ?? ''),
            'regency_name' => $regency['name'] ?? '',
            'district_code' => self::normalizeCode($district['code'] ?? ''),
            'district_name' => $district['name'] ?? '',
        ];
    }

    protected static function findProvince(string $provinceCode): ?array
    {
        $provinceCode = self::normalizeCode($provinceCode);

        return collect(self::load())
            ->first(fn(array $province) => self::normalizeCode($province['code'] ?? '') === $provinceCode);
    }

    protected static function findRegency(string $provinceCode, string $regencyCode): ?array
    {
        $province = self::findProvince($provinceCode);

        if (! $province) {
            return null;
        }

        $regencyCode = self::normalizeCode($regencyCode);

        return collect($province['regencies'] ?? [])
            ->first(fn(array $regency) => self::normalizeCode($regency['code'] ?? '') === $regencyCode);
    }

    protected static function findDistrict(string $provinceCode, string $regencyCode, string $districtCode): ?array
    {
        $regency = self::findRegency($provinceCode, $regencyCode);

        if (! $regency) {
            return null;
        }

        $districtCode = self::normalizeCode($districtCode);

        return collect($regency['districts'] ?? [])
            ->first(fn(array $district) => self::normalizeCode($district['code'] ?? '') === $districtCode);
    }

    protected static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = resource_path('data/regions.json');

        if (! file_exists($path)) {
            return self::$cache = [];
        }

        $json = file_get_contents($path);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return self::$cache = [];
        }

        return self::$cache = $decoded;
    }

    protected static function normalizeCode(string $code): string
    {
        $digitsOnly = preg_replace('/\D+/', '', $code);

        return str_pad((string) ($digitsOnly ?? ''), 2, '0', STR_PAD_LEFT);
    }
}
