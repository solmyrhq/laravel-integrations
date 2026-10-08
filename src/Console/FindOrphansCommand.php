<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Integrations\Console\Concerns\ParsesLimitOption;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationMapping;
use Integrations\Support\ModelKey;
use ReflectionClass;

/**
 * Finds local rows of a mapped model that have no external ID.
 *
 * Compares keys in PHP rather than joining: `internal_id` is a VARCHAR holding
 * keys of any type, so joining it to an integer primary key needs a cast, and
 * on MySQL that fails outright when the two columns' collations differ.
 */
class FindOrphansCommand extends Command
{
    use ParsesLimitOption;

    protected $signature = 'integrations:find-orphans
                            {model : Fully-qualified model class, e.g. "App\\Models\\ZendeskTicket"}
                            {--integration= : Only check mappings for this integration id}
                            {--limit=50 : Maximum rows to list}';

    protected $description = 'List rows of a mapped model that have no external ID mapping.';

    private const CHUNK = 1000;

    private const DEFAULT_LIMIT = 50;

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->error('The model argument must be a fully-qualified Eloquent model class.');

            return self::FAILURE;
        }

        // is_subclass_of() accepts an abstract base, which collectOrphans()
        // then can't instantiate.
        if ((new ReflectionClass($modelClass))->isAbstract()) {
            $this->error("{$modelClass} is abstract. Pass the concrete model whose rows you want to check.");

            return self::FAILURE;
        }

        $integrationId = $this->resolveIntegrationId();
        if ($integrationId === false) {
            return self::FAILURE;
        }

        $limit = $this->parseLimit(self::DEFAULT_LIMIT);
        if ($limit === false) {
            return self::FAILURE;
        }

        $orphans = $this->collectOrphans($modelClass, $integrationId, $limit ?? self::DEFAULT_LIMIT);

        if ($orphans === []) {
            $this->info('No orphaned '.class_basename($modelClass).' rows: every one has a mapping.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Created'], $orphans);
        $this->newLine();
        $this->line(count($orphans).' row(s) with no external ID.');
        $this->line('Each is unreachable upstream. Restore its integration_mappings row, or merge it into the row that kept the mapping.');
        $this->warnIfLimitReached(count($orphans), $limit ?? self::DEFAULT_LIMIT, 'orphaned rows');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<array{0: string, 1: string}>
     */
    private function collectOrphans(string $modelClass, ?int $integrationId, int $limit): array
    {
        $model = new $modelClass;
        $morphClass = $model->getMorphClass();
        $keyName = $model->getKeyName();
        $createdAtColumn = $model->usesTimestamps() ? $model->getCreatedAtColumn() : null;

        // Only the columns the listing prints. Selecting `*` reads whole rows,
        // so on a model that stores payloads inline (file contents, raw API
        // responses) this loads every byte of every chunk for a report that
        // shows an id and a date. That exhausts memory rather than running
        // slowly, and the model most likely to have orphans is also the one
        // most likely to be too heavy to read that way.
        $columns = $createdAtColumn === null ? [$keyName] : [$keyName, $createdAtColumn];

        $orphans = [];

        $modelClass::query()
            ->select($columns)
            ->orderBy($keyName)
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$orphans, $morphClass, $integrationId, $limit, $createdAtColumn): bool {
                $keys = $rows->map(fn (Model $row): string => ModelKey::toString($row->getKey()))->all();

                $mapped = $this->mappedKeys($morphClass, array_values($keys), $integrationId);

                $orphans = [...$orphans, ...$this->unmappedRows($rows, $mapped, $createdAtColumn)];

                return count($orphans) < $limit;
            }, $keyName);

        return array_slice($orphans, 0, $limit);
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @param  array<string, true>  $mapped
     * @return list<array{0: string, 1: string}>
     */
    private function unmappedRows(Collection $rows, array $mapped, ?string $createdAtColumn): array
    {
        $unmapped = [];

        foreach ($rows as $row) {
            $key = ModelKey::toString($row->getKey());

            if (! array_key_exists($key, $mapped)) {
                $unmapped[] = [$key, $this->createdAtLabel($row, $createdAtColumn)];
            }
        }

        return $unmapped;
    }

    private function createdAtLabel(Model $row, ?string $createdAtColumn): string
    {
        $createdAt = $createdAtColumn === null ? null : $row->getAttribute($createdAtColumn);

        return $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '-';
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function mappedKeys(string $morphClass, array $keys, ?int $integrationId): array
    {
        if ($keys === []) {
            return [];
        }

        $query = IntegrationMapping::query()
            ->where('internal_type', $morphClass)
            ->whereIn('internal_id', $keys);

        if ($integrationId !== null) {
            $query->where('integration_id', $integrationId);
        }

        $mapped = [];
        foreach ($query->pluck('internal_id') as $internalId) {
            if (is_string($internalId)) {
                $mapped[$internalId] = true;
            }
        }

        return $mapped;
    }

    /**
     * @return int|null|false null when unfiltered, false when the option is invalid
     */
    private function resolveIntegrationId(): int|null|false
    {
        $option = $this->option('integration');

        if (! is_string($option) || $option === '') {
            return null;
        }

        if (! ctype_digit($option) || (int) $option <= 0) {
            $this->error('The --integration option must be a positive integer id.');

            return false;
        }

        if (Integration::query()->whereKey((int) $option)->doesntExist()) {
            $this->error("No integration with id {$option}.");

            return false;
        }

        return (int) $option;
    }
}
