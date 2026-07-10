<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait SerializesPaymentCallbacks
{
    protected function acquireOrderCallbackLock(string $context, string $orderNumber): mixed
    {
        $key = $this->callbackLockKey($context, $orderNumber);
        $lock = Cache::lock($key, $this->callbackLockTtlSeconds());

        try {
            $lock->block($this->callbackLockWaitSeconds());
        } catch (LockTimeoutException $e) {
            Log::warning('KG Inicis: callback lock timeout', [
                'context' => $context,
                'order_number' => $orderNumber,
                'lock_key' => $key,
            ]);

            throw $e;
        }

        return $lock;
    }

    protected function releaseOrderCallbackLock(mixed $lock): void
    {
        if (is_object($lock) && method_exists($lock, 'release')) {
            $lock->release();
        }
    }

    protected function callbackLockKey(string $context, string $orderNumber): string
    {
        return 'sirsoft-pay_kginicis:callback:'
            . $context
            . ':'
            . sha1(trim($orderNumber));
    }

    protected function callbackLockTtlSeconds(): int
    {
        return 30;
    }

    protected function callbackLockWaitSeconds(): int
    {
        return 10;
    }
}
