<?php

namespace App\Domain\Locking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class LockManager
{
    public function owner(string $process): string
    {
        return gethostname() . ':' . getmypid() . ':' . $process;
    }

    public function acquire(string $key, string $owner, int $ttlSeconds = 30): bool
    {
        $lockKey = $this->key($key);
        $acquired = (bool) Redis::set($lockKey, $owner, 'EX', $ttlSeconds, 'NX');
        if (! $acquired && Redis::get($lockKey) === $owner) {
            Redis::expire($lockKey, $ttlSeconds);
            $acquired = true;
        }
        if ($acquired) {
            DB::table('distributed_lock_audits')->insert([
                'id' => (string) str()->uuid(),
                'lock_key' => $key,
                'owner' => $owner,
                'operation' => 'acquire',
                'expires_at' => now()->addSeconds($ttlSeconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $acquired;
    }

    public function release(string $key, string $owner): void
    {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        Redis::eval($script, 1, $this->key($key), $owner);
        DB::table('distributed_lock_audits')->insert([
            'id' => (string) str()->uuid(),
            'lock_key' => $key,
            'owner' => $owner,
            'operation' => 'release',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function withLock(string $key, string $owner, callable $callback, int $ttlSeconds = 30): mixed
    {
        if (! $this->acquire($key, $owner, $ttlSeconds)) {
            throw new \RuntimeException('Unable to acquire lock: ' . $key);
        }

        try {
            return $callback();
        } finally {
            $this->release($key, $owner);
        }
    }

    public static function site(string $siteId): string
    {
        return 'site:' . $siteId;
    }

    public static function tenant(string $tenantId): string
    {
        return 'tenant:' . $tenantId;
    }

    public static function node(string $nodeId): string
    {
        return 'node:' . $nodeId;
    }

    public static function global(string $resource): string
    {
        return 'global:' . $resource;
    }

    private function key(string $key): string
    {
        return 'controlpanel:lock:' . $key;
    }
}

