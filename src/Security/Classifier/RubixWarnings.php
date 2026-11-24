<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Classifier;

/**
 * Keeps rubix performance hints from becoming errors.
 *
 * rubix emits an `E_USER_WARNING` recommending the Tensor C extension whenever
 * it is missing, which is the normal case. Laravel's error handler promotes any
 * warning to an `ErrorException`, so the firewall would fail over a suggestion
 * that has nothing to do with correctness. Only that message is swallowed; every
 * other warning propagates untouched.
 */
final class RubixWarnings
{
    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function guard(callable $call): mixed
    {
        set_error_handler(static function (int $errno, string $message): bool {
            return $errno === E_USER_WARNING && str_contains($message, 'Tensor');
        }, E_USER_WARNING);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
