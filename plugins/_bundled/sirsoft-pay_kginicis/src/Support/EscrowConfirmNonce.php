<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Support;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 에스크로 구매결정 확인창과 그 결과 수신을 일회용 증표로 묶습니다.
 *
 * 결과 수신 주소(PC returnUrl·모바일 P_NEXT_URL)는 PG 가 사용자 브라우저로 POST 하는 공개 주소라
 * 인증·서명이 없다. 거래번호(tid)만 맞으면 기록하던 종전 방식은 tid 를 아는 제3자가 남의 결제에
 * 구매확정·거절 기록을 남길 수 있었다. 그래서 확인창을 연 본인 요청에서 증표를 발급해 결과 수신
 * 주소의 **경로**에 싣고(PG 가 쿼리를 보존하는지와 무관), 수신 때 한 번만 꺼내 tid 를 대조한다.
 */
class EscrowConfirmNonce
{
    /**
     * 증표 유효 시간(초) — 확인창을 열고 구매결정을 마칠 때까지.
     */
    private const TTL_SECONDS = 1800;

    /**
     * 증표 소비 잠금 유지 시간(초) — 읽고 지우는 동안만 쥔다(프로세스가 죽어도 이 시간 뒤 풀린다).
     */
    private const CONSUME_LOCK_SECONDS = 10;

    /**
     * @param  CacheInterface  $cache  확장 캐시 (코어 캐시 드라이버 래퍼)
     */
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 확인창 문맥을 기억하고 증표를 발급합니다.
     *
     * @param  string  $tid  에스크로 결제 거래번호
     * @param  string  $orderNumber  주문번호
     * @param  int  $userId  확인창을 연 회원 ID
     * @return string 일회용 증표 (경로에 실을 수 있는 영숫자 40자)
     */
    public function issue(string $tid, string $orderNumber, int $userId): string
    {
        $nonce = Str::random(40);

        $this->cache->put($this->key($nonce), [
            'tid' => $tid,
            'order_number' => $orderNumber,
            'user_id' => $userId,
        ], self::TTL_SECONDS);

        return $nonce;
    }

    /**
     * 증표의 문맥을 한 번만 꺼냅니다(꺼내면 지워져 재사용할 수 없다).
     *
     * 읽기와 지우기 사이가 비어 있으면, 같은 증표로 동시에 들어온 두 수신이 둘 다 문맥을 읽어 두 번
     * 기록할 수 있다. 그래서 증표별 잠금을 즉시 시도해(기다리지 않음) 잡은 쪽만 읽고 지운다. 잠금을
     * 못 잡았다면 다른 수신이 이 증표를 소비하고 있으므로 null 이다.
     *
     * @param  string  $nonce  결과 수신 경로의 증표
     * @return array{tid: string, order_number: string, user_id: int}|null 문맥 (없거나 만료·재사용·소비 중이면 null)
     */
    public function consume(string $nonce): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $nonce) !== 1) {
            return null;
        }

        $lock = Cache::lock(self::lockKey($nonce), self::CONSUME_LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        try {
            $key = $this->key($nonce);
            $context = $this->cache->get($key);
            $this->cache->forget($key);
        } finally {
            $lock->release();
        }

        if (! is_array($context) || ! isset($context['tid'], $context['order_number'])) {
            return null;
        }

        return [
            'tid' => (string) $context['tid'],
            'order_number' => (string) $context['order_number'],
            'user_id' => (int) ($context['user_id'] ?? 0),
        ];
    }

    /**
     * 증표 소비 잠금 키를 만듭니다.
     *
     * @param  string  $nonce  증표
     * @return string 잠금 키 (증표 원문 대신 해시를 싣는다)
     */
    public static function lockKey(string $nonce): string
    {
        return 'sirsoft-pay_kginicis:escrow-nonce:'.sha1($nonce);
    }

    /**
     * 캐시 키를 만듭니다.
     *
     * @param  string  $nonce  증표
     * @return string 캐시 키
     */
    private function key(string $nonce): string
    {
        return 'escrow-confirm:'.$nonce;
    }
}
