<?php

namespace App\Extension\Layout;

/**
 * 확장 업데이트 결과 요약 문구 — 업데이트 완료 알림(API 메시지)·CLI·core:update 가 같은 문장을 쓰게 한 곳에 둔다 (D2).
 *
 * 운영자가 고친 화면이 하나도 없고 건너뛴 화면도 없으면 요약을 붙이지 않는다(null).
 */
final class LayoutSyncSummary
{
    /**
     * 보고(`LayoutSyncReport::toArray()`)에서 요약 문구를 만듭니다.
     *
     * @param  array<string, mixed>|null  $layoutSync  보고 배열
     * @return string|null 요약 문구, 붙일 것이 없으면 null
     */
    public static function text(?array $layoutSync): ?string
    {
        if ($layoutSync === null) {
            return null;
        }

        $parts = [];

        // 한 문장에 수가 둘인 요약은 수마다 「수+명사」를 따로 단·복수로 만들어 넘긴다(영어 문구가 쓴다).
        // 한국어·일본어 문구는 종전처럼 숫자(`:applied`·`:conflicts`)를 쓴다.
        if (($layoutSync['modified_count'] ?? 0) > 0) {
            $applied = (int) ($layoutSync['applied_count'] ?? 0);
            $conflicts = (int) ($layoutSync['conflict_count'] ?? 0);
            $parts[] = __('extensions.layout_sync.summary', [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'applied_changes' => trans_choice('extensions.layout_sync.counted.changes', $applied),
                'conflict_places' => trans_choice('extensions.layout_sync.counted.places', $conflicts),
            ]);
        }

        if (($layoutSync['kept_no_base_count'] ?? 0) > 0) {
            $parts[] = trans_choice('extensions.layout_sync.no_base', (int) $layoutSync['kept_no_base_count']);
        }

        $deleted = (int) ($layoutSync['kept_deleted_by_operator_count'] ?? 0);
        $collided = (int) ($layoutSync['kept_name_collision_count'] ?? 0);
        if ($deleted + $collided > 0) {
            $parts[] = __('extensions.layout_sync.skipped', [
                'deleted' => $deleted,
                'collided' => $collided,
                'deleted_screens' => trans_choice('extensions.layout_sync.counted.screens', $deleted),
                'collided_screens' => trans_choice('extensions.layout_sync.counted.screens', $collided),
            ]);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * 업데이트 성공 문구에 레이아웃 처리 요약을 붙일 메시지 키·치환값을 만듭니다 — 모듈·플러그인·템플릿 업데이트 API 공용.
     *
     * 요약은 운영자가 고친 화면이 있을 때만 붙는다({@see text()}). 업데이트할 것이 없었으면(`success=false`) 붙이지 않는다.
     *
     * @param  string  $messageKey  성공 메시지 키
     * @param  array<string, mixed>  $messageParams  성공 메시지 치환값
     * @param  array<string, mixed>  $result  업데이트 결과 (`success`·`layout_sync`)
     * @return array{0: string, 1: array<string, mixed>} [메시지 키, 치환값]
     */
    public static function messageFor(string $messageKey, array $messageParams, array $result): array
    {
        $summary = ($result['success'] ?? true) === false ? null : self::text($result['layout_sync'] ?? null);

        if ($summary === null) {
            return [$messageKey, $messageParams];
        }

        return ['extensions.layout_sync.message', ['message' => trans_count($messageKey, $messageParams), 'summary' => $summary]];
    }
}
