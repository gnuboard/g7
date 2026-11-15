<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 사업자 정보 표시가 꺼져 있으면 공개 설정에서 사업자 칸을 빼는 리스너
 *
 * 코어는 활성 확장의 공개 설정을 모아 사용자 화면(페이지 소스의 공개 설정)에 싣는다. 운영자가
 * 환경설정 → 기본 정보에서 「사업자 정보 표시」를 끄면 그 값이 응답에 남지 않게 한다.
 */
class StripBusinessInfoWhenHiddenListener implements HookListenerInterface
{
    /** 이 모듈 식별자 */
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 의존성을 주입받습니다.
     *
     * @param  EcommerceSettingsService  $settingsService  이커머스 환경설정 서비스
     */
    public function __construct(
        protected EcommerceSettingsService $settingsService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.module_settings.filter_frontend_settings' => [
                'method' => 'filterFrontendSettings',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(filterFrontendSettings)을 사용
    }

    /**
     * 이 모듈의 공개 설정에서 사업자 칸을 정리합니다. (filter 훅)
     *
     * @param  mixed  $settings  공개 설정 (카테고리 => 값)
     * @param  string  $identifier  설정 주인 확장 식별자
     * @return mixed 정리된 공개 설정 (다른 확장의 설정은 그대로)
     */
    public function filterFrontendSettings(mixed $settings, string $identifier = ''): mixed
    {
        if ($identifier !== self::MODULE_IDENTIFIER || ! is_array($settings)) {
            return $settings;
        }

        return $this->settingsService->stripBusinessInfoWhenHidden($settings);
    }
}
