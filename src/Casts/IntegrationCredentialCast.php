<?php

declare(strict_types=1);

namespace Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Integrations\IntegrationManager;
use Integrations\Support\JsonEquivalence;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Data;
use Throwable;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Handles encryption at rest and optional typed casting via Spatie LaravelData.
 *
 * When the provider's credentialDataClass() returns a Data class, the decrypted
 * JSON is cast to that class. Otherwise, returns a plain array.
 *
 * @implements CastsAttributes<Data|array<string, mixed>|null, mixed>
 */
class IntegrationCredentialCast implements CastsAttributes, ComparesCastableAttributes
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

        if (! is_string($value)) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (DecryptException $e) {
            report($e);

            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($decrypted, true);

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
            return app(IntegrationManager::class)->resolveCredentialDataClass($provider);
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
            throw new InvalidArgumentException(sprintf(
                'IntegrationCredentialCast::set() expects null, an array, or a %s instance; got %s. If you pre-encrypted with Crypt::encryptString(), pass the plain array instead. The cast handles encryption.',
                Data::class,
                get_debug_type($value),
            ));
        }

        return Crypt::encryptString(json_encode($arrayValue, JSON_THROW_ON_ERROR));
    }

    /**
     * Compares the decrypted JSON, so re-encrypting unchanged credentials is not a change.
     */
    #[Override]
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if (! is_string($firstValue) || ! is_string($secondValue)) {
            return $firstValue === $secondValue;
        }

        try {
            return JsonEquivalence::areEquivalent($this->decryptWithCurrentKeyOnly($firstValue), Crypt::decryptString($secondValue));
        } catch (DecryptException) {
            return false;
        }
    }

    private function decryptWithCurrentKeyOnly(string $payload): string
    {
        $encrypter = app('encrypter');

        if ($encrypter instanceof Encrypter && $encrypter->getPreviousKeys() !== []) {
            return (clone $encrypter)->previousKeys([])->decryptString($payload);
        }

        return Crypt::decryptString($payload);
    }
}
