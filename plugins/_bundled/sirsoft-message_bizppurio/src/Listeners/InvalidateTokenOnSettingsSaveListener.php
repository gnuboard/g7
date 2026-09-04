<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;

/**
 * 비즈뿌리오 설정 저장 시 인증 토큰 캐시를 무효화하는 listener.
 *
 * BizppurioTokenService 는 발급받은 토큰을 23시간(TTL) 캐시한다(만료 24h 대비
 * 여유). 캐시가 계정/비밀번호 변경과 무관하게 살아있으면, 관리자가 자격증명을
 * 바꿔도 최대 23시간 동안 옛 자격증명으로 발급된 토큰이 계속 재사용된다 —
 * 발송 자체는 그동안 정상 동작하므로 변경 반영 여부를 즉시 확인할 방법이
 * 없고, 토큰이 만료되어서야 뒤늦게 실패가 드러난다.
 *
 * 저장 시점에 무조건 캐시를 비워 다음 토큰 획득(발송 또는 "연결 확인" 버튼)이
 * 항상 최신 자격증명으로 재인증하게 한다. 대상 필드(bizppurio_id/password)
 * 변경 여부와 무관하게 매 저장마다 초기화한다 — 부분 필드만 골라 비교하는
 * 것보다 단순하고, 불필요한 재발급 1회의 비용은 무시할 수 있는 수준이다.
 *
 * @since 1.0.0
 */
class InvalidateTokenOnSettingsSaveListener implements HookListenerInterface
{
    /**
     * 본 플러그인 식별자.
     */
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * @param  BizppurioTokenService  $tokenService  비즈뿌리오 인증 토큰 서비스
     */
    public function __construct(
        private readonly BizppurioTokenService $tokenService,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.after_save' => [
                'method' => 'invalidateToken',
                'priority' => 10,
                'type' => 'action',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='invalidateToken' 를 명시하므로
     * 이 메서드는 미사용. HookListenerInterface 추상 메서드 충족 목적으로만 정의한다.
     *
     * @param  mixed  ...$args  사용 안 함
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 invalidateToken() 메서드 (action 훅)
    }

    /**
     * 본 플러그인 설정 저장 직후 토큰 캐시를 무효화한다.
     *
     * @param  string  $identifier  저장된 플러그인 식별자
     * @param  array<string, mixed>  $settings  저장된 설정(사용 안 함)
     * @param  bool  $result  저장 성공 여부
     */
    public function invalidateToken(string $identifier, array $settings, bool $result): void
    {
        if ($identifier !== self::IDENTIFIER || ! $result) {
            return;
        }

        $this->tokenService->forget();
    }
}
