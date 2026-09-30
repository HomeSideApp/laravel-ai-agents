<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Minimal stand-in for the host application's user model.
 *
 * Uses a plain integer key on purpose: it proves the package works with
 * non-UUID user identifiers (the package must support int|string ids).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TestUser extends Model
{
    protected $table = 'test_users';

    protected $fillable = ['name', 'email'];
}
