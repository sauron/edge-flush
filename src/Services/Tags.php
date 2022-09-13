<?php

namespace A17\EdgeFlush\Services;

use A17\EdgeFlush\EdgeFlush;
use A17\EdgeFlush\Models\Tag;
use A17\EdgeFlush\Models\Url;
use A17\EdgeFlush\Jobs\StoreTags;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use A17\EdgeFlush\Support\Helpers;
use SebastianBergmann\Timer\Timer;
use A17\EdgeFlush\Support\Constants;
use A17\EdgeFlush\Jobs\InvalidateTags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use A17\EdgeFlush\Behaviours\MakeTag;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use A17\EdgeFlush\Behaviours\ControlsInvalidations;

class Tags
{
    use ControlsInvalidations, MakeTag;

    protected Collection $tags;

    public Collection $processedTags;

    public function __construct()
    {
        $this->tags = collect();

        $this->processedTags = collect();
    }

    public function addTag(Model $model): void
    {
        if (
            $this->wasNotProcessed($model) &&
            EdgeFlush::enabled() &&
            filled($tag = $this->makeModelName($model))
        ) {
            $this->tags[$tag] = $tag;
        }
    }

    protected function getAllTagsForModel(
        string|null $modelString
    ): Collection|null {
        if (filled($modelString)) {
            return Tag::where('model', $modelString)->get();
        }

        return null;
    }

    public function getTags(): Collection
    {
        return collect($this->tags)
            ->reject(function (string $tag) {
                return $this->tagIsExcluded($tag);
            })
            ->values();
    }

    public function getTagsHash(Response $response, Request $request): string
    {
        $tag = $this->makeEdgeTag($models = $this->getTags());

        if (
            EdgeFlush::cacheControl()->isCachable($response) &&
            EdgeFlush::storeTagsServiceIsEnabled()
        ) {
            StoreTags::dispatch(
                $models,
                [
                    'cdn' => $tag,
                ],
                $this->getCurrentUrl($request),
            );
        }

        return $tag;
    }

    public function makeEdgeTag(Collection|null $models = null): string
    {
        $models ??= $this->getTags();

        $tag = str_replace(
            ['%environment%', '%sha1%'],
            [
                app()->environment(),
                sha1(
                    collect($models)
                        ->sort()
                        ->join(', '),
                ),
            ],
            config('edge-flush.tags.format'),
        );

        return $tag;
    }

    protected function tagIsExcluded(string $tag): bool
    {
        /**
         * @param callable(string $pattern): boolean $pattern
         */
        return collect(
            config('edge-flush.tags.excluded-model-classes'),
        )->contains(fn(string $pattern) => EdgeFlush::match($pattern, $tag));
    }

    protected function tagIsNotExcluded(string $tag): bool
    {
        return !$this->tagIsExcluded($tag);
    }

    public function storeCacheTags(
        Collection $models,
        array $tags,
        string $url
    ): void {
        if (
            !EdgeFlush::enabled() ||
            !EdgeFlush::storeTagsServiceIsEnabled() ||
            !$this->domainAllowed($url)
        ) {
            return;
        }

        Helpers::debug(
            'STORE-TAGS' .
                json_encode([
                    'models' => $models,
                    'tags' => $tags,
                    'url' => $url,
                ]),
        );

        DB::transaction(function () use ($models, $tags, $url) {
            $url = $this->createUrl($url);

            $now = (string) now();

            collect($models)->each(function (string $model) use (
                $tags,
                $url,
                $now
            ) {
                $index = $this->makeTagIndex($url, $tags, $model);

                $this->dbStatement("
                        insert into edge_flush_tags (index, url_id, tag, model, created_at, updated_at)
                        select '{$index}', {$url->id}, '{$tags['cdn']}', '{$model}', '{$now}', '{$now}'
                        where not exists (
                            select 1
                            from edge_flush_tags
                            where index = '{$index}'
                        )
                        ");
            });
        }, 5);
    }

    public function dispatchInvalidationsForModel(
        Collection|string|Model $models
    ): void {
        if (blank($models)) {
            return;
        }

        Helpers::debug('INVALIDATING: CDN tags for models');

        $models =
            $models instanceof Model ? collect([$models]) : collect($models);

        InvalidateTags::dispatch((new Invalidation())->setModels($models));
    }

    public function invalidateTags(Invalidation $invalidation): void
    {
        if (!EdgeFlush::invalidationServiceIsEnabled()) {
            return;
        }

        if ($invalidation->isEmpty()) {
            $this->invalidateObsoleteTags();

            return;
        }

        config('edge-flush.invalidations.type') === 'batch'
            ? $this->markTagsAsObsolete($invalidation)
            : $this->dispatchInvalidations($invalidation);
    }

    protected function invalidateObsoleteTags(): void
    {
        /**
         * Filter purged urls from obsolete tags.
         * Making sure we invalidate the most busy pages first.
         */
        $rows = collect(
            DB::select(
                "
            select distinct edge_flush_urls.id, edge_flush_urls.hits, edge_flush_urls.url
            from edge_flush_urls
                     inner join edge_flush_tags on edge_flush_tags.url_id = edge_flush_urls.id
            where edge_flush_urls.was_purged_at is null
              and edge_flush_tags.obsolete = true
              and edge_flush_urls.is_valid = true
            order by edge_flush_urls.hits desc
            ",
            ),
        )->map(fn($row) => new Url((array) $row));

        $invalidation = (new Invalidation())->setUrls($rows);

        /**
         * Let's first calculate the number of URLs we are invalidating.
         * If it's above max, just flush the whole website.
         */
        if (true || $rows->count() >= EdgeFlush::cdn()->maxUrls()) {
            $this->invalidateEntireCache($invalidation);

            return;
        }

        /**
         * Let's dispatch invalidations only for what's configured.
         */
        $this->dispatchInvalidations($invalidation);
    }

    protected function markTagsAsObsolete(Invalidation $invalidation): void
    {
        if ($invalidation->type() !== 'tag') {
            return;
        }

        $type = $invalidation->type();

        $items = $invalidation->queryItemsList();

        $this->dbStatement("
            update edge_flush_tags eft
            set obsolete = true
            from (
                    select id
                    from edge_flush_tags
                    where obsolete = false
                      and {$type} in ({$items})
                    order by id
                    for update
                ) tags
            where eft.id = tags.id
        ");
    }

    protected function dispatchInvalidations(Invalidation $invalidation): void
    {
        if ($invalidation->isEmpty()) {
            return;
        }

        $invalidation = EdgeFlush::cdn()->invalidate($invalidation);

        if ($invalidation->success()) {
            // TODO: what happens here on Akamai?
            $this->markUrlsAsPurged($invalidation);
        }
    }

    protected function invalidateEntireCache(Invalidation $invalidation): void
    {
        Helpers::debug('INVALIDATING: entire cache...');

        $invalidation->setInvalidateAll(true);

        EdgeFlush::cdn()->invalidate(
            $invalidation->setPaths(
                collect(config('edge-flush.invalidations.batch.roots')),
            ),
        );

        $this->markUrlsAsPurged($invalidation);
    }

    /*
     * Optimized for speed, 2000 calls to EdgeFlush::tags()->addTag($model) are now only 8ms
     */
    protected function wasNotProcessed(Model $model): bool
    {
        $id = $model->getAttributes()[$model->getKeyName()] ?? null;

        if ($id === null) {
            return false; /// don't process models with no ID yet
        }

        $key = $model->getTable() . '-' . $id;

        if ($this->processedTags[$key] ?? false) {
            return false;
        }

        $this->processedTags[$key] = true;

        return true;
    }

    public function invalidateAll(): Invalidation
    {
        if (!$this->enabled()) {
            return $this->unsuccessfulInvalidation();
        }

        $count = 0;

        do {
            if ($count++ > 0) {
                sleep(2);
            }

            $success = EdgeFlush::cdn()
                ->invalidateAll()
                ->success();
        } while ($count < 3 && !$success);

        if (!$success) {
            return $this->unsuccessfulInvalidation();
        }

        $this->deleteAllTags();

        return $this->successfulInvalidation();
    }

    public function getCurrentUrl(Request $request): string
    {
        $result = $request->header('X-Edge-Flush-Warmed-Url') ?? url()->full();

        if (is_array($result)) {
            $result = $result[0] ?? '';
        }

        return $result;
    }

    protected function deleteAllTags(): void
    {
        // Purge all tags
        $this->dbStatement("
            update edge_flush_tags eft
            set obsolete = true
            from (
                    select id
                    from edge_flush_tags
                    where obsolete = false
                    order by id
                    for update
                ) tags
            where eft.id = tags.id
        ");

        // Purge all urls
        $now = (string) now();

        $this->dbStatement("
            update edge_flush_urls efu
            set was_purged_at = $now
            from (
                    select id
                    from edge_flush_urls
                    where is_valid = true
                    order by id
                    for update
                ) urls
            where efu.id = urls.id
        ");
    }

    public function domainAllowed(string|null $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $allowed = collect(config('edge-flush.domains.allowed'))->filter();

        $blocked = collect(config('edge-flush.domains.blocked'))->filter();

        if ($allowed->isEmpty() && $blocked->isEmpty()) {
            return true;
        }

        $domain = Helpers::parseUrl($url)['host'];

        return $allowed->contains($domain) && !$blocked->contains($domain);
    }

    public function getMaxInvalidations(): int
    {
        return min(
            EdgeFlush::cdn()->maxUrls(),
            config('edge-flush.invalidations.batch.size'),
        );
    }

    public function dbStatement(string $sql): bool
    {
        return DB::statement(DB::raw($sql));
    }

    public function enabled(): bool
    {
        return EdgeFlush::invalidationServiceIsEnabled();
    }

    /**
     * @param string $url
     * @return mixed
     */
    function createUrl(string $url)
    {
        $url = Helpers::sanitizeUrl($url);

        $url = Url::firstOrCreate(
            ['url_hash' => sha1($url)],
            [
                'url' => Str::limit($url, 255),
                'hits' => 1,
            ],
        );

        if (!$url->wasRecentlyCreated) {
            $url->was_purged_at = null;

            $url->incrementHits();
        }

        return $url;
    }

    public function makeTagIndex(Url $url, array $tags, string $model): string
    {
        $index = "{$url->id}-{$tags['cdn']}-{$model}";

        return sha1($index);
    }

    public function markUrlsAsPurged(Invalidation $invalidation): void
    {
        $list = $invalidation->queryItemsList('url');

        $time = (string) now();

        $invalidationId = $invalidation->id();

        if ($invalidation->invalidateAll()) {
            $sql = "
                update edge_flush_urls efu
                set was_purged_at = '{$time}',
                    invalidation_id = '{$invalidationId}'
                from (
                        select efu.id
                        from edge_flush_urls efu
                        where efu.is_valid = true
                          and was_purged_at is not null
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";
        } elseif ($invalidation->type() === 'tag') {
            $sql = "
                update edge_flush_urls efu
                set was_purged_at = '{$time}',
                    invalidation_id = '{$invalidationId}'
                from (
                        select efu.id
                        from edge_flush_urls efu
                        join edge_flush_tags eft on eft.url_id = efu.id
                        where efu.is_valid = true
                          and eft.url in ({$list})
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";
        } else {
            $sql = "
                update edge_flush_urls efu
                set was_purged_at = '{$time}',
                    invalidation_id = '{$invalidationId}'
                from (
                        select efu.id
                        from edge_flush_urls efu
                        where efu.is_valid = true
                          and efu.url in ({$list})
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";
        }

        $this->dbStatement($sql);
    }
}
