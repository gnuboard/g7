<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Lang;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;

/**
 * 팝업 활동 로그 설명 해석기
 *
 * 활동 로그에는 팝업 id 만 저장하고, 화면에 보일 때 이름으로 바꿉니다. 이 플러그인의 설명 키만 처리하고
 * 다른 확장의 키는 그대로 돌려줍니다. 해석 순서는 기록 시점 스냅샷(`properties.name`) → DB 의 현재 관리용 제목 → 「ID: {id}」 입니다.
 */
class ActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * 이 플러그인 활동 로그 설명 키의 접두
     */
    private const KEY_PREFIX = 'sirsoft-popup::activity_log.description.';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
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
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 설명 파라미터의 팝업 id 를 사람이 읽을 이름으로 바꿉니다.
     *
     * @param  array  $params  앞선 필터가 넘긴 설명 파라미터
     * @param  string  $descriptionKey  활동 로그 설명 키
     * @param  array  $properties  로그 기록 시점 속성
     * @return array 이름을 채운 설명 파라미터
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties = []): array
    {
        if (! str_starts_with($descriptionKey, self::KEY_PREFIX)) {
            return $params;
        }

        // 이미지 교체·삭제 문구의 기기 키(pc·mobile)는 기기 이름으로 — 모르는 키는 그대로
        if (is_string($params['device'] ?? null) && Lang::has('sirsoft-popup::activity_log.devices.'.$params['device'])) {
            $params['device'] = (string) __('sirsoft-popup::activity_log.devices.'.$params['device']);
        }

        if (! empty($params['popup_title']) || ! array_key_exists('popup_id', $params)) {
            return $params;
        }

        $name = trim((string) ($properties['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) app(PopupRepositoryInterface::class)->findTitle((int) $params['popup_id']));
        }

        $params['popup_title'] = $name !== '' ? $name : 'ID: '.$params['popup_id'];

        return $params;
    }
}
