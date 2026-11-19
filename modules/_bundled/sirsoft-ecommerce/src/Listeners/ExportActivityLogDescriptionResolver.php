<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 내보내기 활동 로그 설명의 매개변수를 표시 시점 로케일로 해석합니다.
 *
 * 작업유형·파일명·거부 사유를 로그를 여는 사람의 언어로 바꾼다. 파일명은 완료 뒤에야 생기므로 요청·시작 로그는
 * 「작업유형 #번호」로 보이고, 삭제·만료 뒤에도 보이도록 properties 의 파일명 스냅샷을 먼저 쓴다.
 *
 * 훅: core.activity_log.filter_description_params (Filter) — 내보내기 설명 키만 처리한다.
 */
class ExportActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * 처리하는 설명 키 접두 — 이커머스의 다른 설명 키(주문·상품 …)는 손대지 않는다
     */
    private const PREFIX = 'sirsoft-ecommerce::activity_log.description.export_';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 매핑 배열
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
     * 훅 이벤트 처리 (기본 핸들러 — 사용하지 않음)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * description_params 에 작업유형·파일명·거부 사유 라벨을 채웁니다.
     *
     * @param  array  $params  현재 description_params
     * @param  string  $descriptionKey  번역 키
     * @param  array  $properties  활동 로그 properties
     * @return array 해석된 description_params
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        if (! str_starts_with($descriptionKey, self::PREFIX)) {
            return $params;
        }

        $type = (string) ($properties['type'] ?? $params['type'] ?? '');
        $typeKey = 'sirsoft-ecommerce::enums.export_type.'.$type;
        $params['type_label'] = $type !== '' && __($typeKey) !== $typeKey ? __($typeKey) : $type;

        $name = $properties['name'] ?? null;
        $id = $params['export_id'] ?? $properties['export_id'] ?? null;
        $params['file_name'] = $name ?: ($id ? $params['type_label'].' #'.$id : $params['type_label']);

        if (isset($params['reason'])) {
            $reasonKey = 'sirsoft-ecommerce::activity_log.export_denied_reasons.'.$params['reason'];
            $params['reason_label'] = __($reasonKey) !== $reasonKey ? __($reasonKey) : (string) $params['reason'];
        }

        return $params;
    }
}
