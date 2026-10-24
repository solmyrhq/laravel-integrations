<?php

declare(strict_types=1);

namespace Integrations\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;

/**
 * @property int $id
 * @property int $integration_id
 * @property string $delivery_id
 * @property string|null $event_type
 * @property string $payload
 * @property array<string, mixed> $headers
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook newModelQuery()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook newQuery()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook query()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook pending()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook failed()
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook forEventType(string $eventType)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook recent(int $hours = 24)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook staleProcessing(int $timeoutSeconds)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook stalePending(int $timeoutSeconds)
 * @method static Builders\IntegrationWebhookBuilder<static>|IntegrationWebhook retryable(int $maxAttempts)
 *
 * @property-read Integration|null $integration
 *
 * @mixin \Eloquent
 */
class IntegrationWebhook extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_webhooks';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'headers' => 'json',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    private ?int $claimedAttempt = null;

    public function markProcessing(): bool
    {
        $loaded = $this->getAttribute('attempts');
        $attempts = (is_int($loaded) ? $loaded : 0) + 1;

        $claimed = $this->transition(
            [['status', '=', 'pending'], ['attempts', '=', $attempts - 1]],
            ['status' => 'processing', 'attempts' => $attempts, 'error' => null, 'processed_at' => null],
        );

        if ($claimed) {
            $this->claimedAttempt = $attempts;
        }

        return $claimed;
    }

    public function resetToPending(): bool
    {
        return $this->transition(
            [['status', '=', 'processing']],
            ['status' => 'pending', 'error' => null, 'processed_at' => null],
        );
    }

    public function retry(): bool
    {
        return $this->transition(
            [['status', '=', 'failed'], ['attempts', '=', $this->attempts]],
            ['status' => 'pending', 'error' => null, 'processed_at' => null],
        );
    }

    public function reclaimPending(CarbonInterface $staleBefore): bool
    {
        return $this->transition([['status', '=', 'pending'], ['updated_at', '<', $staleBefore]], []);
    }

    public function markProcessed(): void
    {
        $this->finish(['status' => 'processed', 'error' => null, 'processed_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->finish(['status' => 'failed', 'error' => $error, 'processed_at' => now()]);
    }

    /**
     * @param  array<model-property<static>, mixed>  $attributes
     */
    private function finish(array $attributes): void
    {
        if ($this->claimedAttempt === null) {
            $this->fill($attributes)->save();

            return;
        }

        $this->transition([['status', '=', 'processing'], ['attempts', '=', $this->claimedAttempt]], $attributes);
    }

    /**
     * @param  list<array{model-property<static>, string, mixed}>  $conditions
     * @param  array<model-property<static>, mixed>  $attributes
     */
    private function transition(array $conditions, array $attributes): bool
    {
        $attributes['updated_at'] = now();

        $query = $this->newQuery()->where('id', $this->id);

        foreach ($conditions as [$column, $operator, $value]) {
            $query->where($column, $operator, $value);
        }

        if ($query->update($attributes) === 0) {
            return false;
        }

        $this->fill($attributes)->syncOriginalAttributes(array_keys($attributes));

        return true;
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationWebhookBuilder<IntegrationWebhook>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationWebhookBuilder
    {
        return new Builders\IntegrationWebhookBuilder($query);
    }
}
