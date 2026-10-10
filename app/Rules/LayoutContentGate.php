<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 content 저장측 보안 게이트 (SSoT)
 *
 * 레이아웃 content 가 서버에 들어오는 모든 경로 — 저장·편집기 저장·확장 저장·미리보기 생성·
 * 마크업 변환 — 가 같은 두 규칙을 거쳐야 한다. 한 경로만 빠지면 그 경로가 그대로 우회로가 된다
 * (KVE-2026-2350: 미리보기 생성 요청 2종이 두 규칙 없이 content 를 저장해 미리보기 URL 로 렌더했다).
 *
 * - {@see SafeLayoutExpressions}: 샌드박스 우회·호스트 capability 그래프 토큰·원격 스크립트
 * - {@see NoExternalUrls}: sink 위치의 외부 URL, 트리 전체의 실행 스킴
 */
final class LayoutContentGate
{
    /**
     * content 배열 규칙에 부착할 보안 규칙 목록을 돌려줍니다.
     *
     * 매 호출마다 새 인스턴스를 만든다 — 규칙이 검증 1회 단위 캐시(신뢰 호스트·자산 호스트)를 가진다.
     *
     * @return array<int, ValidationRule> 보안 규칙 인스턴스
     */
    public static function rules(): array
    {
        return [new SafeLayoutExpressions, new NoExternalUrls];
    }

    /**
     * FormRequest 규칙을 거치지 않는 경로(마크업 변환 결과 등)에서 content 를 검사합니다.
     *
     * @param  array<string, mixed>  $content  레이아웃 content (컴파일 산출 JSON 포함)
     * @return array<int, string> 위반 메시지 목록 (비어 있으면 통과)
     */
    public static function violations(array $content): array
    {
        $messages = [];

        foreach (self::rules() as $rule) {
            $rule->validate('content', $content, function (string $message) use (&$messages) {
                $messages[] = $message;
            });
        }

        return array_values(array_unique($messages));
    }

    /**
     * 위반 메시지와 그 위반을 낸 값(소스 위치 단서)을 함께 돌려줍니다 (7.1.0 P16).
     *
     * 값은 규칙이 기록한 첫 위반 값이다 — 마크업 편집기가 그 값이 적힌 줄을 찾아 오류 패널·마커에 싣는다.
     * 기록이 없는 위반은 값이 null 이다(위치 없이 문구만).
     *
     * @param  array<string, mixed>  $content  레이아웃 content (컴파일 산출 JSON 포함)
     * @return array<int, array{message: string, value: string|null}> 위반 목록 (메시지 중복 제거, 발견 순)
     */
    public static function violationDetails(array $content): array
    {
        $details = [];

        foreach (self::rules() as $rule) {
            $messages = [];
            $rule->validate('content', $content, function (string $message) use (&$messages) {
                $messages[] = $message;
            });

            $values = method_exists($rule, 'offendingValues') ? $rule->offendingValues() : [];

            foreach ($messages as $message) {
                $details[$message] ??= ['message' => $message, 'value' => $values[$message] ?? null];
            }
        }

        return array_values($details);
    }
}
