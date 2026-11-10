<?php

namespace App\Support;

use App\Support\LayoutMarkup\EmptyMap;
use stdClass;

/**
 * 레이아웃 오류 처리(errorHandling)의 빈 동작 항목 정리
 *
 * 편집기 [덮기]로 시작한 빈 동작(`{}`)을 고르지 않고 저장하면 런타임 findHandler 가 그 코드를 「처리됨」으로
 * 보아 같은 맵 default, 데이터 소스 onError, 템플릿 기본 처리를 가린다(#869, 설계 확정 2026-11-09). 편집기
 * 저장 직렬화(`stripInheritedFromLayoutContent`, layoutTreeUtils.ts)가 이 항목을 지우고, 서버는 같은 정책을
 * 저장 요청의 최종 방어선과 버전 복원에서 다시 적용한다. PHP 디코드는 이 자리의 `{}` 를 `[]` 로 읽을 수 있는데
 * 화면에서는 빈 배열도 참이므로 둘 다 빈 동작으로 본다.
 *
 * 정리 대상은 최상위 `errorHandling` 과 `data_sources[].errorHandling` 의 값뿐이다. 다른 자리의 빈 객체는
 * 건드리지 않는다(빈 객체 보존 규칙은 {@see LayoutEmptyObjects}).
 */
final class LayoutErrorHandlingPrune
{
    /**
     * 레이아웃 본문에서 빈 동작 오류 코드를 지우고, 코드가 모두 비면 errorHandling 키를 뺍니다.
     *
     * @param  array<string, mixed>  $content  레이아웃 본문
     * @return array<string, mixed> 정리된 본문
     */
    public static function prune(array $content): array
    {
        if (array_key_exists('errorHandling', $content)) {
            $next = self::pruneMap($content['errorHandling']);
            if ($next === null) {
                unset($content['errorHandling']);
            } else {
                $content['errorHandling'] = $next;
            }
        }

        if (isset($content['data_sources']) && is_array($content['data_sources'])) {
            foreach ($content['data_sources'] as $index => $source) {
                if (! is_array($source) || ! array_key_exists('errorHandling', $source)) {
                    continue;
                }
                $next = self::pruneMap($source['errorHandling']);
                if ($next === null) {
                    unset($content['data_sources'][$index]['errorHandling']);
                } else {
                    $content['data_sources'][$index]['errorHandling'] = $next;
                }
            }
        }

        return $content;
    }

    /**
     * errorHandling 맵에서 빈 동작 코드를 뺍니다. 맵이 아니면 그대로, 다 비면 null.
     *
     * @param  mixed  $map  errorHandling 값
     * @return mixed 정리된 맵, 또는 비었으면 null
     */
    private static function pruneMap(mixed $map): mixed
    {
        if (EmptyMap::is($map)) {
            return null;
        }
        if ($map instanceof stdClass) {
            $map = get_object_vars($map);
        }
        if (! is_array($map)) {
            return $map;
        }

        $kept = array_filter($map, static fn ($action) => ! self::isEmptyAction($action));

        return $kept === [] ? null : $kept;
    }

    /**
     * 오류 처리 동작이 비었는지 — 속성 없는 객체 또는 빈 배열.
     *
     * @param  mixed  $action  동작 값
     * @return bool 비었으면 true
     */
    private static function isEmptyAction(mixed $action): bool
    {
        // 저장 요청과 버전 원문은 빈 객체를 EmptyMap 표식으로 보존해 디코드한다 (#758).
        if (EmptyMap::is($action)) {
            return true;
        }
        if ($action instanceof stdClass) {
            return get_object_vars($action) === [];
        }

        return $action === [];
    }
}
