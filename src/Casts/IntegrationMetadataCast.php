<?php

declare(strict_types=1);

namespace Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Integrations\IntegrationManager;
use Integrations\Support\JsonEquivalence;
use Override;
use Spatie\LaravelData\Data;
use Throwable;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Handles optional typed casting via Spatie LaravelData for metadata.
 *
 * When the provider's metadataDataClass() returns a Data class, the JSON is
 * cast to that class. Otherwise, returns a plain array.
 *
 * @implements CastsAttributes<Data|array<string, mixed>|null, mixed>
 */
class IntegrationMetadataCast implements CastsAttributes, ComparesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Data|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $provider = $attributes['provider'] ?? null;

        if (is_string($provider)) {
            $dataClass = $this->resolveDataClass($provider);

            if ($dataClass !== null && is_subclass_of($dataClass, Data::class)) {
                return $dataClass::from($decoded);
            }
        }

        return $decoded;
    }

    private function resolveDataClass(string $provider): ?string
    {
        try {
            return app(IntegrationManager::class)->resolveMetadataDataClass($provider);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Data) {
            $arrayValue = $value->toArray();
        } elseif (is_array($value)) {
            $arrayValue = $value;
        } else {
            return null;
        }

        return json_encode($arrayValue, JSON_THROW_ON_ERROR);
    }

    /**
     * Compares the decoded JSON, so MySQL's reformatting of a JSON column is not a change.
     */
    #[Override]
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if (! is_string($firstValue) || ! is_string($secondValue)) {
            return $firstValue === $secondValue;
        }

        return JsonEquivalence::areEquivalent($firstValue, $secondValue);
    }
}
