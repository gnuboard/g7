<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Lang;

/**
 * 레이아웃·조각 영구 삭제 기록의 삭제 사유(`description_params.reason`)를 표시 시점에 번역합니다.
 *
 * 삭제 직전 기록은 사유를 코드(`uninstall`·`template_uninstall` 등)로 저장한다 — 번역문을 저장하면 기록 당시 언어로
 * 굳어 다른 언어로 보는 관리자에게도 그 언어로 보인다. 코드가 번역 키(`activity_log.deletion_reason.{code}`)에 있으면
 * 현재 언어 문구로 바꾸고, 없으면(코드 저장 전에 번역문으로 남은 행 등) 저장값을 그대로 보인다.
 *
 * 훅: core.activity_log.filter_description_params (Filter)
 *
 * @since 7.2.0
 */
class LayoutDeletionReasonDescriptionResolver implements HookListenerInterface
{
    /** 사유 코드를 번역할 설명 키 */
    private const DESCRIPTION_KEYS = [
        'activity_log.description.layout_force_deleted',
        'activity_log.description.layout_extension_force_deleted',
    ];

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * 삭제 사유 코드를 현재 언어 문구로 바꿉니다.
     *
     * @param  array  $params  현재 description_params
     * @param  string  $descriptionKey  번역 키
     * @param  array  $properties  활동 로그 properties
     * @return array 사유를 번역한 description_params
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        if (! in_array($descriptionKey, self::DESCRIPTION_KEYS, true)) {
            return $params;
        }

        $reason = $params['reason'] ?? null;
        if (! is_string($reason) || $reason === '') {
            return $params;
        }

        $key = 'activity_log.deletion_reason.'.$reason;
        if (Lang::has($key)) {
            $params['reason'] = __($key);
        }

        return $params;
    }
}
