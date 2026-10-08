<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Integrations\Console\Concerns\ParsesLimitOption;
use Integrations\Models\Builders\IntegrationSyncItemBuilder;
use Integrations\Models\IntegrationSyncItem;
use Throwable;

class ListFailedItemsCommand extends Command
{
    use ParsesLimitOption;

    protected $signature = 'integrations:list-failed-items
                            {--integration= : Only show items for this integration id}
                            {--since= : Only show items created on or after this date}
                            {--limit=50 : Maximum items to list}';

    protected $description = 'List sync items that exhausted their retries and need operator attention.';

    private const DEFAULT_LIMIT = 50;

    public function handle(): int
    {
        // Just the columns the table below prints: checkpoint_value holds a
        // provider cursor payload that nothing here reads.
        $query = IntegrationSyncItem::query()
            ->failed()
            ->select(['id', 'integration_id', 'event_class', 'external_id', 'error', 'attempts', 'created_at'])
            ->orderByDesc('id');

        if (! $this->applyIntegrationFilter($query) || ! $this->applySinceFilter($query)) {
            return self::FAILURE;
        }

        $limit = $this->parseLimit(self::DEFAULT_LIMIT);
        if ($limit === false) {
            return self::FAILURE;
        }

        $limit ??= self::DEFAULT_LIMIT;
        $items = $query->limit($limit)->get();

        if ($items->isEmpty()) {
            $this->info('No failed sync items.');

            return self::SUCCESS;
        }

        $rows = $items->map(fn (IntegrationSyncItem $item): array => [
            (string) $item->id,
            (string) $item->integration_id,
            class_basename($item->event_class),
            $item->external_id ?? '-',
            Str::limit($item->error ?? '', 60),
            (string) $item->attempts,
            $item->created_at?->format('Y-m-d H:i:s') ?? '-',
        ])->all();

        $this->table(
            ['ID', 'Integration', 'Event', 'External ID', 'Error', 'Attempts', 'Created'],
            $rows,
        );

        $this->newLine();
        $this->line('Retry: php artisan queue:retry <uuid>   (find the uuid with php artisan queue:failed)');
        $this->line('Skip:  php artisan integrations:skip-sync-item <id>');
        $this->warnIfLimitReached($items->count(), $limit, 'failed items');

        return self::SUCCESS;
    }

    /**
     * @param  IntegrationSyncItemBuilder<IntegrationSyncItem>  $query
     */
    private function applyIntegrationFilter(IntegrationSyncItemBuilder $query): bool
    {
        $integrationOption = $this->option('integration');
        if (! is_string($integrationOption) || $integrationOption === '') {
            return true;
        }

        if (! ctype_digit($integrationOption) || (int) $integrationOption <= 0) {
            $this->error('The --integration option must be a positive integer id.');

            return false;
        }

        $query->forIntegration((int) $integrationOption);

        return true;
    }

    /**
     * @param  IntegrationSyncItemBuilder<IntegrationSyncItem>  $query
     */
    private function applySinceFilter(IntegrationSyncItemBuilder $query): bool
    {
        $sinceOption = $this->option('since');
        if (! is_string($sinceOption) || $sinceOption === '') {
            return true;
        }

        try {
            $since = Carbon::parse($sinceOption);
        } catch (Throwable) {
            $this->error("Invalid --since value '{$sinceOption}'. Use a parseable date or datetime.");

            return false;
        }

        $query->where('created_at', '>=', $since);

        return true;
    }
}
