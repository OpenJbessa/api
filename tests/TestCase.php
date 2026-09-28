<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Index Redis réservés aux tests (phpunit.xml). Le développement utilise 0
     * et 1, la production n'a que ces deux-là.
     */
    private const REDIS_TEST_DATABASES = [2, 3];

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushRedis();
    }

    /**
     * Redis n'a pas d'équivalent de RefreshDatabase : sans cette remise à zéro,
     * le cache, les sessions et le stream d'un test fuiraient dans le suivant.
     *
     * Le store de cache est résolu ici, avant que le test ne puisse doubler la
     * façade Redis : il garde ainsi la vraie connexion.
     */
    private function flushRedis(): void
    {
        $databases = [
            (int) config('database.redis.default.database'),
            (int) config('database.redis.cache.database'),
        ];

        if (array_diff($databases, self::REDIS_TEST_DATABASES) !== []) {
            throw new RuntimeException(sprintf(
                'Refus de vider Redis : les tests visent les bases %s au lieu de %s. Vérifier phpunit.xml.',
                implode(', ', $databases),
                implode(', ', self::REDIS_TEST_DATABASES),
            ));
        }

        Redis::connection('default')->flushdb();
        Cache::store('redis')->flush();
    }
}
