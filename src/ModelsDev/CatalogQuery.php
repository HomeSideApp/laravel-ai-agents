<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\ModelsDev;

use HomeSide\AiAgents\Models\ModelsDevModel;
use HomeSide\AiAgents\Models\ModelsDevProvider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side query service over the models.dev reference catalog.
 *
 * The host's UI calls it to render pickers with server-side filtering and
 * pagination — the full catalog is never shipped to the client:
 *
 *   $catalog = app(CatalogQuery::class);
 *
 *   $catalog->providers(search: 'oai');          // LengthAwarePaginator
 *   $catalog->models(
 *       providerSlug: 'openai',
 *       search: 'gpt',
 *       toolCall: true,
 *       maxInputCost: 5.0,
 *   );
 *
 * Every filter is nullable and optional: passing nothing returns the full
 * paginated surface. All filtering happens in SQL so memory stays flat.
 */
class CatalogQuery
{
    /**
     * Paginated provider list for the picker's first level.
     *
     * Each row counts its models so the UI can render "OpenAI (42)".
     *
     * @param  string|null  $search  Case-insensitive LIKE over name/slug.
     * @param  int  $perPage  Page size (max 100).
     * @return LengthAwarePaginator<int, ModelsDevProvider>
     */
    public function providers(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $search = $this->normalizeTerm($search);

        $query = ModelsDevProvider::query()
            ->withCount('models');

        if ($search !== null) {
            $term = $this->likeTerm($search);

            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        return $query->orderBy('name')
            ->orderBy('slug')
            ->paginate(min(max($perPage, 1), 100));
    }

    /**
     * Paginated model list with every catalog filter models.dev exposes.
     *
     * @param  string|null  $providerSlug  Restrict to one provider.
     * @param  string|null  $search  Case-insensitive LIKE over model_id,
     *                               name, description and family.
     * @param  bool|null  $toolCall  Require/require-absent tool calling.
     * @param  bool|null  $reasoning  Require/require-absent reasoning.
     * @param  bool|null  $structuredOutput  Require/require-absent structured output.
     * @param  bool|null  $attachments  Require/require-absent file attachments.
     * @param  bool|null  $openWeights  Require/require-absent open weights.
     * @param  'text'|'image'|'audio'|'video'|null  $withModality  Input modality filter.
     * @param  float|null  $maxInputCost  USD per 1M input tokens; models
     *                                    without pricing count as 0 (free).
     * @param  bool  $freeOnly  Only models without input pricing.
     * @param  'name'|'cheapest_input'|'cheapest_output'|'newest'|null  $orderBy
     * @param  int  $perPage  Page size (max 100).
     * @return LengthAwarePaginator<int, ModelsDevModel> Models with their
     *                                                   provider eager-loaded.
     */
    public function models(
        ?string $providerSlug = null,
        ?string $search = null,
        ?bool $toolCall = null,
        ?bool $reasoning = null,
        ?bool $structuredOutput = null,
        ?bool $attachments = null,
        ?bool $openWeights = null,
        ?string $withModality = null,
        ?float $maxInputCost = null,
        bool $freeOnly = false,
        ?string $orderBy = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $search = $this->normalizeTerm($search);

        $query = ModelsDevModel::query()
            ->with('provider')
            ->when($providerSlug !== null && $providerSlug !== '', function (Builder $query) use ($providerSlug): void {
                $query->whereHas('provider', fn (Builder $p) => $p->where('slug', $providerSlug));
            });

        if ($search !== null) {
            $term = $this->likeTerm($search);

            $query->where(function (Builder $inner) use ($term): void {
                $inner->whereRaw('LOWER(model_id) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(family, \'\')) LIKE ?', [$term]);
            });
        }

        $query
            ->when($toolCall !== null, fn (Builder $q) => $q->where('tool_call', $toolCall))
            ->when($reasoning !== null, fn (Builder $q) => $q->where('reasoning', $reasoning))
            ->when($structuredOutput !== null, fn (Builder $q) => $q->where('structured_output', $structuredOutput))
            ->when($attachments !== null, fn (Builder $q) => $q->where('attachment', $attachments))
            ->when($openWeights !== null, fn (Builder $q) => $q->where('open_weights', $openWeights))
            ->when($withModality !== null, fn (Builder $q) => $q->where('modalities_input', 'like', '%"'.$withModality.'"%'))
            ->when($maxInputCost !== null, function (Builder $q) use ($maxInputCost): void {
                // Priced models compare their cost; unpriced ones are free
                // and always match, mirroring models.dev's "free" bucket.
                $q->where(function (Builder $inner) use ($maxInputCost): void {
                    $inner->whereNull('cost_input')
                        ->orWhere('cost_input', '<=', $maxInputCost);
                });
            })
            ->when($freeOnly, fn (Builder $q) => $q->whereNull('cost_input'));

        $this->applyOrder($query, $orderBy);

        return $query->paginate(min(max($perPage, 1), 100));
    }

    /**
     * Trim a filter term; empty strings become null ("no filter") and the
     * rest are lower-cased for case-insensitive matching.
     */
    private function normalizeTerm(?string $term): ?string
    {
        if ($term === null) {
            return null;
        }

        $term = trim($term);

        return $term === '' ? null : mb_strtolower($term);
    }

    /**
     * Escape LIKE wildcards and wrap the term for an exact-substring match.
     */
    private function likeTerm(string $term): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
    }

    /**
     * Apply the ordering to a model query.
     *
     * @param  Builder<ModelsDevModel>  $query
     */
    private function applyOrder(Builder $query, ?string $orderBy): void
    {
        match ($orderBy) {
            'cheapest_input' => $query->orderByRaw('COALESCE(cost_input, 0) ASC'),
            'cheapest_output' => $query->orderByRaw('COALESCE(cost_output, 0) ASC'),
            'newest' => $query->orderByDesc('release_date')->orderByDesc('created_at'),
            default => $query->orderBy('name')->orderBy('model_id'),
        };
    }
}
