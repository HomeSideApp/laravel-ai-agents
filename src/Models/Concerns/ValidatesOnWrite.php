<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models\Concerns;

use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds validated write helpers to package models.
 *
 * The host does not have to remember which invariants each table enforces
 * (SSRF-safe URLs, scope exclusivity, unique configuration keys, ...):
 * `createValidated()` / `updateValidated()` run the model's rules plus its
 * security hooks before anything touches the database. Plain `create()` /
 * `save()` remain untouched for hosts that prefer full manual control.
 *
 * Ownership is expressed through the virtual `scope` attribute, which is
 * resolved into physical columns by the model (never mass-assigned raw):
 *
 *   ['scope' => ['user' => $userId]]     → user_id
 *   ['scope' => ['tenant' => $tenantId]] → the configured tenant FK column
 *   ['scope' => 'global'] (or omitted)   → no owner columns
 *
 * Implementations define `rules()` and may override `validateSecurity()`
 * (cross-field/database checks) and `applyDerivedAttributes()` (attributes
 * that need post-create handling, e.g. accessors).
 */
trait ValidatesOnWrite
{
    /**
     * Validate the attributes and create a record.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException When validation or a security hook fails.
     */
    /**
     * Validate the attributes and create a record.
     *
     * @param  array<string, mixed>  $attributes
     * @return static The created model instance.
     *
     * @throws ValidationException When validation or a security hook fails.
     */
    public static function createValidated(array $attributes): static
    {
        $attributes = static::resolveScope($attributes);

        static::validateAttributes($attributes);

        /** @var static $model */
        $model = static::create($attributes);

        // Re-hydrate so the returned instance reflects the persisted row,
        // including database defaults (prompt_version, is_default, ...).
        $model->refresh();

        static::applyDerivedAttributes($model, $attributes);

        return $model;
    }

    /**
     * Validate the attributes and update the record.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException When validation or a security hook fails.
     */
    /**
     * Validate the attributes and update the record.
     *
     * Only the supplied attributes are validated — the model's current values
     * backfill the rest, so a partial update does not fail "required" rules
     * for fields the row already has.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool Whether the save succeeded.
     *
     * @throws ValidationException When validation or a security hook fails.
     */
    public function updateValidated(array $attributes): bool
    {
        $attributes = static::resolveScope($attributes);

        // Backfill current values so "required" rules see the effective
        // record state, not just the delta being written.
        $merged = $this->mergeCurrentForValidation($attributes);

        static::validateAttributes($merged, $this);

        $this->fill($attributes);
        $saved = $this->save();

        static::applyDerivedAttributes($this, $attributes);

        return $saved;
    }

    /**
     * Merge the attributes being written over the model's current values.
     *
     * Gives validation the full picture of the resulting row while keeping
     * the write limited to the caller's delta.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mergeCurrentForValidation(array $attributes): array
    {
        $current = $this->exists
            ? $this->fresh()?->getAttributes() ?? $this->getAttributes()
            : $this->getAttributes();

        /** @var array<string, mixed> $merged */
        $merged = array_merge($current, $attributes);

        return $merged;
    }

    /**
     * Validation rules for the model's writable attributes.
     *
     * @return array<string, mixed>
     */
    abstract protected static function rules(): array;

    /**
     * Resolve the virtual `scope` attribute into physical owner columns.
     *
     * Supported shapes:
     * - `'global'` (or null)            → clears user_id and the tenant column
     * - `['user' => $id]`               → user_id
     * - `['tenant' => $id]`             → the configured tenant FK column
     *
     * When `scope` is present it takes precedence over any raw owner columns
     * in the payload. When `scope` is absent the raw columns pass through
     * untouched (advanced/direct use).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> The attributes without the `scope` key,
     *                              with owner columns resolved.
     *
     * @throws ValidationException When the shape is invalid or tenant
     *                             support is disabled while requesting a
     *                             tenant scope.
     */
    protected static function resolveScope(array $attributes): array
    {
        if (! array_key_exists('scope', $attributes)) {
            return $attributes;
        }

        $scope = $attributes['scope'];
        unset($attributes['scope']);

        $foreignKey = static::scopeForeignKey();

        // 'global' (or null): explicitly clear both owner columns.
        if ($scope === 'global' || $scope === null) {
            $attributes['user_id'] = null;

            if ($foreignKey !== null) {
                $attributes[$foreignKey] = null;
            }

            return $attributes;
        }

        // ['user' => $id]
        if (is_array($scope) && array_keys($scope) === ['user']) {
            $attributes['user_id'] = $scope['user'];

            return $attributes;
        }

        // ['tenant' => $id]
        if (is_array($scope) && array_keys($scope) === ['tenant']) {
            if ($foreignKey === null) {
                throw ValidationException::withMessages([
                    'scope' => 'Tenant support is disabled (ai-agents.tenant.isolation); the "tenant" scope cannot be used.',
                ]);
            }

            $attributes[$foreignKey] = $scope['tenant'];

            return $attributes;
        }

        throw ValidationException::withMessages([
            'scope' => "Invalid scope. Use 'global', ['user' => id] or ['tenant' => id].",
        ]);
    }

    /**
     * The configured tenant foreign key column, or null when isolation is not
     * `column` (in `database` mode the FK column does not exist).
     */
    protected static function scopeForeignKey(): ?string
    {
        if (TenantIsolation::fromConfig() !== TenantIsolation::Column) {
            return null;
        }

        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Run rules and security hooks; throw on failure.
     *
     * The security hooks register through Validator::after() so their errors
     * participate in fails() — manually appending to the message bag before
     * evaluation would be ignored, because fails() only reports rule (and
     * after-callback) failures.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    protected static function validateAttributes(array $attributes, ?self $model = null): void
    {
        $validator = Validator::make($attributes, static::rules());

        $validator->after(function (ValidatorContract $v) use ($attributes, $model): void {
            static::validateSecurity($attributes, $v, $model);
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Cross-field or database-backed checks that do not fit simple rules.
     *
     * Add errors to the validator (never throw) so the host receives a
     * single ValidationException with every problem reported at once.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function validateSecurity(array $attributes, ValidatorContract $validator, ?self $model = null): void {}

    /**
     * Handle attributes that cannot be mass-assigned directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function applyDerivedAttributes(self $model, array $attributes): void {}
}
