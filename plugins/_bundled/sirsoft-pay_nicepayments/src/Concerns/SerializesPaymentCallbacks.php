<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 같은 결제번호의 콜백을 한 번에 하나씩 처리하도록 직렬화하는 캐시 락입니다.
 *
 * 형제 PG 플러그인(kginicis·nhnkcp·tosspayments)의 같은 이름 트레이트와 같은 규약이다 — 키는
 * `sirsoft-pay_nicepayments:callback:{문맥}:{sha1(결제번호)}`, TTL 30초, 대기 10초.
 */
trait SerializesPaymentCallbacks
{
    /**
     * 결제번호 단위 콜백 락을 획득합니다. (대기 시간 안에 못 얻으면 예외)
     *
     * @param  string  $context  콜백 문맥 (락 키 구분자)
     * @param  string  $orderNumber  주문번호 또는 클레임 결제번호
     * @return Lock 획득한 락
     *
     * @throws LockTimeoutException 대기 시간 안에 락을 얻지 못했을 때
     */
    protected function acquireOrderCallbackLock(string $context, string $orderNumber): Lock
    {
        $key = $this->callbackLockKey($context, $orderNumber);
        $lock = Cache::lock($key, $this->callbackLockTtlSeconds());

        try {
            $lock->block($this->callbackLockWaitSeconds());
        } catch (LockTimeoutException $e) {
            Log::warning('NicePayments: callback lock timeout', [
                'context' => $context,
                'order_number' => $orderNumber,
                'lock_key' => $key,
            ]);

            throw $e;
        }

        return $lock;
    }

    /**
     * 획득한 콜백 락을 해제합니다. (null 이면 아무것도 하지 않음)
     *
     * @param  mixed  $lock  획득한 락 (획득 전이면 null)
     * @return void 반환값 없음
     */
    protected function releaseOrderCallbackLock(mixed $lock): void
    {
        if (is_object($lock) && method_exists($lock, 'release')) {
            $lock->release();
        }
    }

    /**
     * 콜백 락 키를 만듭니다.
     *
     * @param  string  $context  콜백 문맥
     * @param  string  $orderNumber  주문번호 또는 클레임 결제번호
     * @return string 락 키
     */
    protected function callbackLockKey(string $context, string $orderNumber): string
    {
        return 'sirsoft-pay_nicepayments:callback:'
            .$context
            .':'
            .sha1(trim($orderNumber));
    }

    /**
     * 락 보유 시간(초)을 반환합니다. — 승인 API 왕복보다 길어야 한다.
     *
     * @return int 초
     */
    protected function callbackLockTtlSeconds(): int
    {
        return 30;
    }

    /**
     * 락 대기 시간(초)을 반환합니다.
     *
     * @return int 초
     */
    protected function callbackLockWaitSeconds(): int
    {
        return 10;
    }
}
