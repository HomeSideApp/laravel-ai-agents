<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal stand-in for the host tenant model, used by column-isolation tests.
 */
class TestTeam extends Model
{
    protected $table = 'test_teams';

    protected $fillable = ['name'];
}
