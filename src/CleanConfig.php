<?php

namespace Spatie\LaravelQueuedDbCleanup;

use Closure;
use Illuminate\Support\Facades\DB;

class CleanConfig
{
    public string $sql;

    public array $sqlBindings;

    public int $deleteChunkSize = 1000;

    public string $lockName = '';

    public int $pass = 1;

    public int $rowsDeletedInThisPass = 0;

    public int $totalRowsDeleted = 0;

    public ?string $stopWhen = null;

    public string $lockCacheStore;

    public int $releaseLockAfterSeconds;

    public function __construct()
    {
        $this->lockCacheStore = config('queued-db-cleanup.lock.cache_store');

        $this->releaseLockAfterSeconds = config('queued-db-cleanup.lock.release_lock_after_seconds');
    }

    /**
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param int $chunkSize
     */
    public function usingQuery($query, int $chunkSize)
    {
        $this->sql = $query->limit($chunkSize)->getGrammar()->compileDelete($query->toBase());

        $this->sqlBindings = $query->getBindings();

        $this->deleteChunkSize = $chunkSize;

        $this->lockName = $this->convertQueryToLockName($query);

        if ($this->stopWhen === null) {
            $this->stopWhen(function (CleanConfig $cleanConfig) {
                return $cleanConfig->rowsDeletedInThisPass < $this->deleteChunkSize;
            });
        }


    }

    public function executeDeleteQuery(): int
    {
        return DB::delete($this->sql, $this->sqlBindings);
    }

    public function stopWhen(callable $callable)
    {
        $wrapper = new SerializableClosure($callable);

        $this->stopWhen = serialize($wrapper);
    }

    public function shouldContinueCleaning(): bool
    {
        /** @var SerializableClosure $wrapper */
        $wrapper = unserialize($this->stopWhen);

        $stopWhen = $wrapper->getClosure();

        return ! $stopWhen($this);
    }

    public function rowsDeletedInThisPass(int $rowsDeleted): self
    {
        $this->rowsDeletedInThisPass = $rowsDeleted;

        $this->totalRowsDeleted += $rowsDeleted;

        return $this;
    }

    public function incrementPass(): self
    {
        $this->pass++;

        $this->rowsDeletedInThisPass = 0;

        return $this;
    }

    /** @var \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder */
    protected function convertQueryToLockName($query): string
    {
        return md5($query->toSql() . print_r($query->getBindings(), true));
    }
}
