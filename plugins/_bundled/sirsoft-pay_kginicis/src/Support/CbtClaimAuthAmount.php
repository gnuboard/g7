<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Support;

use App\Contracts\Extension\CacheInterface;

/**
 * CBT(엔화) 클레임 추가결제의 **인증요청 금액**을 결제 건 단위로 기억합니다.
 *
 * CBT 승인 응답(/cbtapprove)에는 금액이 실리지 않는다(매뉴얼상 미사용). 금액의 권위는 인증요청
 * (cbtauth) 때 가맹점이 해시로 서명해 보낸 값인데, 그 값은 해시 발급 단계에서만 보인다. 그래서
 * 해시 발급 때 이 결제 건의 금액을 적어 두고, 승인 리스너가 응답에 금액이 없을 때 이 값을 승인액으로
 * 보고한다 — 결제창을 연 뒤 청구액이 바뀌었으면(신청서 수정·관리자 재산정) 대조가 불일치로
 * 잡아낸다. 기록이 없으면(캐시 만료 등) 호출부가 주문 경로와 같은 기준(결제 예정액)으로 판단한다.
 *
 * 같은 결제 건으로 결제창을 다시 열면 마지막 발급 금액으로 덮어쓴다.
 */
class CbtClaimAuthAmount
{
    /**
     * 기록 보존 시간(초) — CBT 결제창 체류·편의점 선택까지 넉넉히 덮는다.
     */
    private const TTL_SECONDS = 10800;

    /**
     * @param  CacheInterface  $cache  확장 캐시 (코어 캐시 드라이버 래퍼)
     */
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 해시를 발급한 인증요청 금액을 기록합니다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  int  $amount  인증요청 금액 (엔화 정수)
     * @return void 반환값 없음
     */
    public function remember(string $paymentNumber, int $amount): void
    {
        $this->cache->put($this->key($paymentNumber), $amount, self::TTL_SECONDS);
    }

    /**
     * 기록해 둔 인증요청 금액을 돌려줍니다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @return int|null 인증요청 금액 (기록이 없으면 null)
     */
    public function recall(string $paymentNumber): ?int
    {
        $value = $this->cache->get($this->key($paymentNumber));

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 캐시 키를 만듭니다.
     *
     * @param  string  $paymentNumber  클레임 결제번호
     * @return string 캐시 키
     */
    private function key(string $paymentNumber): string
    {
        return 'cbt-claim-auth-amount:'.sha1(trim($paymentNumber));
    }
}
