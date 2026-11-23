<?php

namespace Plugins\Sirsoft\Marketing\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 마케팅 동의 조회 필터 리스너
 *
 * 다른 확장이 이 플러그인 클래스를 직접 참조하지 않고 회원의 동의 여부를 물을 수 있게 한다.
 * 필터 `sirsoft-marketing.consent.is_consented($default, int $userId, string $consentKey)` 에 답한다 —
 * 플러그인이 꺼져 있으면 구독자가 없어 `$default`(보통 null)가 그대로 돌아오므로, 묻는 쪽은 null 을 「미동의」로 본다.
 */
class ConsentQueryListener implements HookListenerInterface
{
    /**
     * @param  MarketingConsentService  $service  마케팅 동의 서비스
     */
    public function __construct(
        private MarketingConsentService $service,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type: string}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-marketing.consent.is_consented' => [
                'method' => 'isConsented',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 회원이 동의 항목에 동의했는지 답합니다.
     *
     * @param  mixed  $default  앞선 구독자의 답 (없으면 null)
     * @param  mixed  $userId  회원 ID
     * @param  mixed  $consentKey  동의 키 (marketing_consent, email_subscription 등)
     * @return bool|null 동의 여부 (인자가 잘못되면 앞선 답을 그대로)
     */
    public function isConsented(mixed $default = null, mixed $userId = null, mixed $consentKey = null): ?bool
    {
        if (! is_numeric($userId) || (int) $userId <= 0 || ! is_string($consentKey) || $consentKey === '') {
            return is_bool($default) ? $default : null;
        }

        return $this->service->isConsented((int) $userId, $consentKey);
    }
}
