<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Casts;

use Integrations\Casts\IntegrationMetadataCast;
use Integrations\Enums\HealthStatus;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestDataProvider;
use Integrations\Tests\Fixtures\TestMetadata;
use Integrations\Tests\TestCase;

class IntegrationMetadataCastTest extends TestCase
{
    public function test_reading_typed_metadata_stored_with_different_formatting_does_not_make_it_dirty(): void
    {
        $integration = $this->createTypedIntegration();

        Integration::query()->whereKey($integration->id)->update(['metadata' => '{"region": "eu-west-1"}']);
        $integration->refresh();

        $this->assertInstanceOf(TestMetadata::class, $integration->metadata);

        $integration->recordSuccess();

        $this->assertTrue($integration->wasChanged('consecutive_failures'));
        $this->assertFalse($integration->wasChanged('metadata'));
    }

    public function test_changed_metadata_is_saved(): void
    {
        $integration = $this->createTypedIntegration();

        $this->assertInstanceOf(TestMetadata::class, $integration->metadata);

        $integration->update(['metadata' => ['region' => 'us-east-1']]);

        $this->assertTrue($integration->wasChanged('metadata'));

        $integration->refresh();
        $this->assertInstanceOf(TestMetadata::class, $integration->metadata);
        $this->assertSame('us-east-1', $integration->metadata->region);
    }

    public function test_compare_ignores_object_key_order(): void
    {
        $cast = new IntegrationMetadataCast;
        $model = new Integration;

        $this->assertTrue($cast->compare($model, 'metadata', '{"b": {"y": 1, "x": 2}, "a": [1, 2]}', '{"a":[1,2],"b":{"x":2,"y":1}}'));
        $this->assertFalse($cast->compare($model, 'metadata', '{"a": [1, 2]}', '{"a": [2, 1]}'));
        $this->assertFalse($cast->compare($model, 'metadata', '{"a": 1}', '{"a": "1"}'));
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
