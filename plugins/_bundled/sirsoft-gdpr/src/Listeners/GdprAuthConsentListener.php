<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 회원가입 동의 처리 리스너
 *
 * 회원가입 시 폼 동의 기록 (`core.auth.record_consents` 훅) — 사용자가 폼에서
 * 입력한 GDPR 쿠키 동의(`agree_cookie_analytics` 등)를 새 회원 user_id 로
 * status upsert + history INSERT (source=`register`).
 *
 * 게스트→회원 자동 승계는 의도적으로 제공하지 않음. GDPR Art.6/ePrivacy Art.5(3)
 * 관점에서 게스트(디바이스 단위)와 회원(주체 단위)은 별도 동의 모델이며, 글로벌
 * CMP 대부분도 자동 승계를 기본 동작으로 제공하지 않음. 회원가입 시점의 폼 동의로
 * Art.7(1) 입증 책임은 충족되며, 게스트 시절 동의 이력은 세션 기준으로 영구 보존됨.
 */
class GdprAuthConsentListener implements HookListenerInterface
{
    /**
     * 회원가입 폼에서 동의를 식별하는 접두사 (sirsoft-marketing 패턴 일관)
     */
    private const REGISTER_FIELD_PREFIX = 'agree_';

    /**
     * GdprAuthConsentListener 생성자
     *
     * @param GdprConsentService $consentService 동의 서비스
     * @param CookieCategoryService $categoryService 쿠키 카테고리 서비스 (키 화이트리스트)
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
        private readonly CookieCategoryService $categoryService,
    ) {}

    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int}>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync => true : 현재 HTTP 요청 컨텍스트(request()->ip()) 에 의존하므로
            //   큐 디스패치 시 컨텍스트 소실 + Carbon 직렬화 mismatch 회귀 차단.
            'core.auth.record_consents' => [
                'method' => 'recordRegisterConsents',
                'priority' => 10,
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 요구 메서드.
     *
     * @param mixed ...$args
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드 분기
    }

    /**
     * 회원가입 시 폼 데이터에서 GDPR 쿠키 동의를 추출하여 기록.
     *
     * @param User $user 가입 완료된 사용자
     * @param array $data 요청 데이터
     * @param string|null $agreedAt ISO8601 문자열 동의 일시 (코어 AuthService 가 toIso8601String 으로 직렬화 후 전달, 현재 미사용)
     * @param string|null $ip IP 주소 (현재 미사용, Service 가 request()->ip() 직접 사용)
     * @return void
     */
    public function recordRegisterConsents(User $user, array $data, ?string $agreedAt = null, ?string $ip = null): void
    {
        $allowedKeys = $this->categoryService->getAllConsentKeys();
        $consents = $this->extractConsents($data, $allowedKeys);

        if (empty($consents)) {
            return;
        }

        $this->consentService->updateConsents(
            userId: $user->id,
            sessionId: null,
            consents: $consents,
            source: 'register',
        );
    }

    /**
     * 폼 데이터에서 동의 키만 추출.
     *
     * 두 형식 모두 지원:
     * - agree_cookie_analytics (sirsoft-marketing 컨벤션)
     * - cookie_analytics (직접)
     *
     * @param array $data 요청 데이터
     * @param array<int, string> $allowedKeys 허용된 동의 키 목록
     * @return array<string, bool> [consent_key => bool]
     */
    private function extractConsents(array $data, array $allowedKeys): array
    {
        $consents = [];

        foreach ($allowedKeys as $key) {
            $prefixedKey = self::REGISTER_FIELD_PREFIX.$key;

            if (array_key_exists($prefixedKey, $data)) {
                $consents[$key] = (bool) $data[$prefixedKey];

                continue;
            }

            if (array_key_exists($key, $data)) {
                $consents[$key] = (bool) $data[$key];
            }
        }

        return $consents;
    }
}
