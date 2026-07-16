<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class SerializesPaymentCallbacksTest extends PluginTestCase
{
    private object $traitSubject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitSubject = new class {
            use SerializesPaymentCallbacks {
                acquireOrderCallbackLock as public;
                releaseOrderCallbackLock as public;
                callbackLockKey as public;
            }

            protected function callbackLockWaitSeconds(): int
            {
                return 0;
            }

            protected function callbackLockTtlSeconds(): int
            {
                return 5;
            }
        };
    }

    public function test_same_context_and_order_are_serialized(): void
    {
        $orderNumber = 'ORD-KCP-LOCK-' . random_int(10000, 99999);
        $firstLock = $this->traitSubject->acquireOrderCallbackLock('authCallback', $orderNumber);

        try {
            $this->expectException(LockTimeoutException::class);

            $this->traitSubject->acquireOrderCallbackLock('authCallback', $orderNumber);
        } finally {
            $this->traitSubject->releaseOrderCallbackLock($firstLock);
        }
    }

    public function test_released_lock_allows_next_callback(): void
    {
        $orderNumber = 'ORD-KCP-LOCK-' . random_int(10000, 99999);
        $firstLock = $this->traitSubject->acquireOrderCallbackLock('authCallback', $orderNumber);
        $this->traitSubject->releaseOrderCallbackLock($firstLock);

        $secondLock = $this->traitSubject->acquireOrderCallbackLock('authCallback', $orderNumber);

        $this->assertNotNull($secondLock);
        $this->traitSubject->releaseOrderCallbackLock($secondLock);
    }

    public function test_lock_key_is_scoped_by_context_and_order(): void
    {
        $this->assertNotSame(
            $this->traitSubject->callbackLockKey('authCallback', 'ORD-KCP-LOCK-A'),
            $this->traitSubject->callbackLockKey('vbankNotify', 'ORD-KCP-LOCK-A'),
        );

        $this->assertNotSame(
            $this->traitSubject->callbackLockKey('authCallback', 'ORD-KCP-LOCK-A'),
            $this->traitSubject->callbackLockKey('authCallback', 'ORD-KCP-LOCK-B'),
        );
    }
}
