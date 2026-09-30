<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Tenancy;

use HomeSide\AiAgents\Tenancy\GenericTenantResolver;
use HomeSide\AiAgents\Tenancy\NullTenantResolver;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class GenericTenantResolverTest extends TestCase
{
    /**
     * With tenancy disabled the resolver is a full no-op.
     */
    public function test_disabled_tenancy_resolves_nothing_and_leaves_queries_untouched(): void
    {
        config()->set('ai-agents.tenant.enabled', false);

        $resolver = new GenericTenantResolver;

        $this->assertFalse($resolver->enabled());
        $this->assertNull($resolver->resolveAccessible(userId: 1, tenantId: 'abc'));
        $this->assertNull($resolver->modelClass());

        $query = TestUser::query();
        $originalSql = $query->toSql();

        $this->assertSame($originalSql, $resolver->scopeQuery($query, 'abc')->toSql());
    }

    /**
     * An explicit tenant id is honoured when it is not authorised.
     */
    public function test_explicit_tenant_unauthorised_returns_null(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');
        config()->set('ai-agents.tenant.members_table', 'test_members');
        config()->set('ai-agents.tenant.members_user_key', 'user_id');
        config()->set('ai-agents.tenant.members_tenant_key', 'household_id');

        DB::table('test_members')->insert(['user_id' => 1, 'household_id' => 'h-1']);

        $resolver = new GenericTenantResolver;

        $this->assertNull($resolver->resolveAccessible(userId: 1, tenantId: 'h-OTHER'));
    }

    /**
     * Membership in the requested tenant authorises the explicit tenant id.
     */
    public function test_explicit_tenant_with_membership_is_returned(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.members_table', 'test_members');
        config()->set('ai-agents.tenant.members_tenant_key', 'household_id');

        DB::table('test_members')->insert(['user_id' => 1, 'household_id' => 'h-1']);

        $resolver = new GenericTenantResolver;

        $this->assertSame('h-1', $resolver->resolveAccessible(userId: 1, tenantId: 'h-1'));
    }

    /**
     * Without an explicit tenant, the user's active-tenant column is the
     * candidate — and it still passes the membership check.
     */
    public function test_falls_back_to_user_active_tenant_with_membership(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');
        config()->set('ai-agents.tenant.user_column', 'active_household_id');
        config()->set('ai-agents.tenant.members_table', 'test_members');
        config()->set('ai-agents.tenant.members_tenant_key', 'household_id');

        DB::table('test_users')->insert(['id' => 7, 'name' => 'Ana', 'email' => 'ana@example.com', 'active_household_id' => 'h-9']);
        DB::table('test_members')->insert(['user_id' => 7, 'household_id' => 'h-9']);

        $resolver = new GenericTenantResolver;

        $this->assertSame('h-9', $resolver->resolveAccessible(userId: 7, tenantId: null));
    }

    /**
     * The user's active tenant must be rejected when membership is gone —
     * this is the cross-tenant IDOR the resolver exists to close.
     */
    public function test_active_tenant_without_membership_is_rejected(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.user_column', 'active_household_id');
        config()->set('ai-agents.tenant.members_table', 'test_members');
        config()->set('ai-agents.tenant.members_tenant_key', 'household_id');

        DB::table('test_users')->insert(['id' => 8, 'name' => 'Bo', 'email' => 'bo@example.com', 'active_household_id' => 'h-stale']);

        $resolver = new GenericTenantResolver;

        $this->assertNull($resolver->resolveAccessible(userId: 8, tenantId: null));
    }

    /**
     * Without a members table configured, the resolver trusts the resolved
     * candidate: the host opted out of membership checks.
     */
    public function test_without_members_table_the_candidate_is_trusted(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.members_table', null);

        $resolver = new GenericTenantResolver;

        $this->assertSame('t-1', $resolver->resolveAccessible(userId: 1, tenantId: 't-1'));
    }

    /**
     * With tenancy enabled but no tenant id anywhere, the result is null:
     * the caller falls through to the user/system scopes.
     */
    public function test_enabled_tenancy_without_candidate_returns_null(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.members_table', 'test_members');

        DB::table('test_users')->insert(['id' => 9, 'name' => 'Cy', 'email' => 'cy@example.com']);

        $resolver = new GenericTenantResolver;

        $this->assertNull($resolver->resolveAccessible(userId: 9, tenantId: null));
    }

    /**
     * scopeQuery with a tenant id filters by the configured foreign key.
     */
    public function test_scope_query_filters_by_tenant_when_given(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');

        $resolver = new GenericTenantResolver;
        $query = $resolver->scopeQuery(TestUser::query(), 'h-5');

        $this->assertStringContainsString('household_id', $query->toSql());
    }

    /**
     * scopeQuery with null targets tenant-less rows (the global scope).
     */
    public function test_scope_query_with_null_targets_tenant_less_rows(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');

        $resolver = new GenericTenantResolver;
        $query = $resolver->scopeQuery(TestUser::query(), null);

        $this->assertStringContainsString('household_id', strtolower($query->toSql()));
        $this->assertStringContainsString('null', strtolower($query->toSql()));
    }

    /**
     * The null resolver behaves identically to the generic one with tenancy
     * off: the container swap must be transparent to callers.
     */
    public function test_null_resolver_contract_matches_disabled_generic(): void
    {
        $resolver = new NullTenantResolver;
        $query = TestUser::query();

        $this->assertFalse($resolver->enabled());
        $this->assertNull($resolver->resolveAccessible(1, 't-1'));
        $this->assertSame($query->toSql(), $resolver->scopeQuery($query, 't-1')->toSql());
        $this->assertInstanceOf(Builder::class, $resolver->scopeQuery($query, 't-1'));
    }
}
