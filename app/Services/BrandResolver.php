<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BrandResolver
{
    private ?Collection $brands = null;

    public function cleanName(mixed $value): ?string
    {
        $name = trim((string) $value);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name === '' ? null : $name;
    }

    public function key(mixed $value): string
    {
        $name = $this->cleanName($value);

        if ($name === null) {
            return '';
        }

        return mb_strtolower(trim(Str::ascii($name)));
    }

    public function findByName(mixed $value): ?Brand
    {
        $key = $this->key($value);

        if ($key === '') {
            return null;
        }

        return $this->all()
            ->first(fn (Brand $brand): bool => $this->key($brand->name) === $key);
    }

    /**
     * @return array{0: Brand, 1: bool}
     */
    public function firstOrCreateByName(mixed $value): array
    {
        $name = $this->cleanName($value);

        if ($name === null) {
            throw new \InvalidArgumentException('El nombre de la marca es obligatorio.');
        }

        $existing = $this->findByName($name);
        if ($existing instanceof Brand) {
            return [$existing, false];
        }

        try {
            $brand = Brand::query()->create([
                'name' => $name,
                'status' => 'active',
            ]);
            $this->remember($brand);

            return [$brand, true];
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $this->forget();
            $brand = $this->findByName($name);

            if ($brand instanceof Brand) {
                return [$brand, false];
            }

            throw $exception;
        }
    }

    public function all(): Collection
    {
        if ($this->brands === null) {
            $this->brands = Brand::query()->select(['id', 'name', 'status'])->get();
        }

        return $this->brands;
    }

    public function forget(): void
    {
        $this->brands = null;
    }

    private function remember(Brand $brand): void
    {
        if ($this->brands === null) {
            return;
        }

        $this->brands = $this->brands
            ->reject(fn (Brand $cached): bool => (int) $cached->id === (int) $brand->id)
            ->push($brand);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '1555', '2067'], true);
    }
}
