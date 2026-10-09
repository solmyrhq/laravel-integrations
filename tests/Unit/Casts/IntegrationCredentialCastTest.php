<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Casts;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Integrations\Casts\IntegrationCredentialCast;
use Integrations\Enums\HealthStatus;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestCredentials;
use Integrations\Tests\Fixtures\TestDataProvider;
use Integrations\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

class IntegrationCredentialCastTest extends TestCase
{
    private IntegrationCredentialCast $cast;

    private Integration $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new IntegrationCredentialCast;
        $this->model = new Integration;
    }

    public function test_set_null_returns_null(): void
    {
        $this->assertNull($this->cast->set($this->model, 'credentials', null, []));
    }

    public function test_set_array_round_trips_through_get(): void
    {
        $encrypted = $this->cast->set($this->model, 'credentials', ['api_key' => 'secret'], []);

        $this->assertIsString($encrypted);

        $decoded = $this->cast->get($this->model, 'credentials', $encrypted, []);

        $this->assertSame(['api_key' => 'secret'], $decoded);
    }

    public function test_set_data_instance_round_trips_through_get_as_array(): void
    {
        $encrypted = $this->cast->set($this->model, 'credentials', new TestCredentials('secret'), []);

        $this->assertIsString($encrypted);

        $decoded = $this->cast->get($this->model, 'credentials', $encrypted, []);

        $this->assertSame(['api_key' => 'secret'], $decoded);
    }

    public function test_set_string_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got string/');

        $this->cast->set($this->model, 'credentials', 'pre-encrypted-blob', []);
    }

    public function test_set_integer_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got int/');

        $this->cast->set($this->model, 'credentials', 42, []);
    }

    public function test_set_bool_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got bool/');

        $this->cast->set($this->model, 'credentials', true, []);
    }

    public function test_set_non_data_object_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got stdClass/');

        $this->cast->set($this->model, 'credentials', new stdClass, []);
    }

    public function test_reading_typed_credentials_does_not_make_them_dirty_on_save(): void
    {
        $integration = $this->createTypedIntegration();

        $this->assertInstanceOf(TestCredentials::class, $integration->credentials);
        $storedCiphertext = $integration->getRawOriginal('credentials');

        $integration->recordSuccess();

        $this->assertTrue($integration->wasChanged('consecutive_failures'));
        $this->assertFalse($integration->wasChanged('credentials'));
        $this->assertSame($storedCiphertext, Integration::query()->whereKey($integration->id)->toBase()->value('credentials'));
    }

    public function test_assigning_identical_credentials_is_not_a_change(): void
    {
        $integration = $this->createTypedIntegration();

        $integration->credentials = ['api_key' => 'secret-123'];

        $this->assertFalse($integration->isDirty('credentials'));
    }

    public function test_changed_credentials_are_saved(): void
    {
        $integration = $this->createTypedIntegration();

        $this->assertInstanceOf(TestCredentials::class, $integration->credentials);

        $integration->update(['credentials' => ['api_key' => 'rotated-456']]);

        $this->assertTrue($integration->wasChanged('credentials'));

        $integration->refresh();
        $this->assertInstanceOf(TestCredentials::class, $integration->credentials);
        $this->assertSame('rotated-456', $integration->credentials->api_key);
    }

    public function test_credentials_encrypted_with_a_previous_key_are_re_encrypted_once_with_the_current_key(): void
    {
        $cipher = config('app.cipher');
        $oldKey = Encrypter::generateKey($cipher);
        $currentKeyOnly = new Encrypter(Crypt::getKey(), $cipher);
        Crypt::swap((new Encrypter(Crypt::getKey(), $cipher))->previousKeys([$oldKey]));

        $integration = $this->createTypedIntegration();
        $oldCiphertext = (new Encrypter($oldKey, $cipher))->encryptString('{"api_key":"secret-123"}');
        Integration::query()->whereKey($integration->id)->toBase()->update(['credentials' => $oldCiphertext]);
        $integration->refresh();

        $this->assertInstanceOf(TestCredentials::class, $integration->credentials);

        $integration->recordSuccess();

        $this->assertTrue($integration->wasChanged('credentials'));
        $stored = Integration::query()->whereKey($integration->id)->toBase()->value('credentials');
        $this->assertIsString($stored);
        $this->assertSame('{"api_key":"secret-123"}', $currentKeyOnly->decryptString($stored));

        $integration->update(['consecutive_failures' => 2]);

        $this->assertTrue($integration->wasChanged('consecutive_failures'));
        $this->assertFalse($integration->wasChanged('credentials'));
        $this->assertSame([$oldKey], Crypt::getPreviousKeys());
    }

    public function test_compare_treats_undecryptable_values_as_different(): void
    {
        $encrypted = $this->cast->set($this->model, 'credentials', ['api_key' => 'secret'], []);

        $this->assertFalse($this->cast->compare($this->model, 'credentials', 'not-a-ciphertext', $encrypted));
    }

    private function createTypedIntegration(): Integration
    {
        app(IntegrationManager::class)->register('typed', TestDataProvider::class);

        $integration = Integration::create([
            'provider' => 'typed',
            'name' => 'Typed Integration',
            'credentials' => ['api_key' => 'secret-123'],
            'metadata' => ['region' => 'eu-west-1'],
            'health_status' => HealthStatus::Degraded,
            'consecutive_failures' => 3,
        ]);

        return $integration->refresh();
    }
}
