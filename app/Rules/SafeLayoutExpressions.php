<?php

namespace App\Rules;

use App\Support\TrustedScriptHosts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 JSON 표현식 저장측 심층 방어 규칙 (KVE-2026-1915)
 *
 * 클라이언트의 화이트리스트 AST 평가기(SafeExpressionEvaluator)가 표현식 실행의
 * 1차 방어입니다. 이 규칙은 저장 시점에 위험 토큰을 거부하는 서버측 보조 방어로,
 * 편집자가 저장한 레이아웃이 애초에 샌드박스 우회 표현식/원격 스크립트를 담지
 * 못하게 합니다.
 *
 * 차단 대상(레이아웃 JSON 전체를 재귀 순회하며 모든 문자열 값 검사):
 * - 프로토타입 체인 접근: `.constructor` / `.__proto__` / `.prototype`,
 *   `['constructor']` 등 문자열 리터럴 computed 접근 → Function 도달 경로
 * - 함수 생성/코드 실행: `Function(`, `eval(`, 동적 `import(`
 * - 원격 스크립트: `scripts[].src` 는 same-origin path-only(`/` 시작, `//`·scheme 금지),
 *   단 확장이 manifest(`trusted_script_hosts`)로 선언한 신뢰 호스트는 예외로 허용
 * - 외부 데이터 소스: `data_sources[].endpoint` 도 동일 (same-origin 또는 신뢰 호스트)
 *
 * 화살표 함수(`=>`)는 정상 표현식에서 광범위하게 사용되므로 차단하지 않습니다
 * (클라이언트 평가기가 인터프리터로 안전하게 실행).
 */
class SafeLayoutExpressions implements ValidationRule
{
    /**
     * 위험 표현식 토큰 패턴 (문자열 값 대상)
     *
     * @var array<string>
     */
    private const DANGEROUS_PATTERNS = [
        // 프로토타입/생성자 체인 접근 (dot)
        '/\.\s*(constructor|__proto__|prototype)\b/i',
        // 프로토타입/생성자 체인 접근 (문자열 리터럴 computed 키) — 중첩 배열 키
        // `[['constructor']]` 도 내부 `['constructor']` 가 매칭된다.
        '/\[\s*[\'"](constructor|__proto__|prototype)[\'"]\s*\]/i',
        // Object 리플렉션 static 호출 — 프로토타입/디스크립터를 읽어 Function 도달·프로토타입
        // 오염 경로. **호출 위치(뒤에 `(`)만** 매칭해 안내 문구의 단순 단어 언급은 오탐하지 않는다.
        // 리플렉션 인자 `getOwnPropertyDescriptor(x, 'constructor')` 는 이 메서드명이 반드시
        // 동반되므로 여기서 잡히고, 금지 프로퍼티를 그냥 문자열로 비교하는 정상 표현식
        // (`{{ mode === 'prototype' }}` — 런타임 평가기가 허용)은 차단하지 않는다.
        '/\b(getPrototypeOf|setPrototypeOf|getOwnPropertyDescriptors?|defineProperty|defineProperties)\s*\(/i',
        // 함수 생성자 / eval 호출
        '/\bFunction\s*\(/',
        '/\beval\s*\(/',
        // 동적 import() — 원격 ES 모듈 로드/코드 실행 경로 (런타임 AST 평가기와 저장측 패리티)
        '/\bimport\s*\(/',
        // 원시 __proto__ 식별자
        '/\b__proto__\b/',
        // legacy 접근자 4종 — 프로퍼티를 **문자열 인자**로 지목해 프로토타입을 읽고 쓴다.
        // Object 리플렉션 static 과 같은 능력을 모든 객체가 상속으로 제공하므로 같은 강도로
        // 막는다. 배포 레이아웃 전수에서 사용 0건이라 정상 표현식 회귀가 없다.
        '/\b__(lookup|define)(Getter|Setter)__\b/',
    ];

    // 주의: 문자열 조립 난독화(`['const' + 'ructor']`)는 정적 토큰 매칭으로 잡을 수 없다.
    // 그 형태의 최종 방어는 런타임 화이트리스트 인터프리터(SafeExpressionEvaluator)가
    // 담당한다 — 키를 1회 정규화(String 강제변환)한 뒤 금지 프로퍼티를 차단하므로,
    // 조립·배열·toString 강제변환 등 모든 우회 형태가 접근 시점에 거부된다. 이 저장측
    // 규칙은 리터럴·리플렉션 형태를 저장 단계에서 조기 차단하는 심층 방어 계층이다.

    /**
     * 신뢰 외부 스크립트 호스트 캐시 (검증 1회당 집계 1회).
     *
     * @var array<int, string>|null
     */
    private ?array $trustedHosts = null;

    /**
     * 검증 수행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $this->walk($value, $fail);
    }

    /**
     * 레이아웃 JSON 트리를 재귀 순회하며 문자열 값을 검사합니다.
     *
     * @param  array<mixed>  $node  현재 노드
     * @param  Closure  $fail  검증 실패 콜백
     */
    private function walk(array $node, Closure $fail): void
    {
        foreach ($node as $key => $item) {
            // 백틱 템플릿 리터럴을 포함한 위험 표현식 토큰 검사
            if (is_string($item)) {
                $this->assertSafeExpression($item, $fail);

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            // scripts[].src same-origin 검증
            if ($key === 'scripts') {
                $this->assertSameOriginList($item, 'src', $fail);
            }

            // data_sources[].endpoint same-origin 검증
            if ($key === 'data_sources') {
                $this->assertSameOriginList($item, 'endpoint', $fail);
            }

            $this->walk($item, $fail);
        }
    }

    /**
     * 문자열 표현식에 위험 토큰이 포함되어 있으면 검증을 실패시킵니다.
     *
     * @param  string  $value  검사 대상 문자열
     * @param  Closure  $fail  검증 실패 콜백
     */
    private function assertSafeExpression(string $value, Closure $fail): void
    {
        // 백틱 템플릿 리터럴은 차단하지 않는다 — 레이아웃이 내비게이션 경로 조립 등에
        // 정상적으로 사용하며(예: `/mypage/${$args[0]}`), `${...}` 는 클라이언트
        // 평가기가 인터프리터로 안전하게 해석한다. constructor/Function 등 실제 위험
        // 토큰만 아래에서 거부한다.
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $fail(__('validation.layout.dangerous_expression', ['snippet' => mb_substr($value, 0, 80)]));

                return;
            }
        }
    }

    /**
     * scripts/data_sources 배열의 지정 필드가 same-origin path-only 인지 검증합니다.
     *
     * @param  array<mixed>  $list  scripts 또는 data_sources 배열
     * @param  string  $field  검사할 필드명 (src | endpoint)
     * @param  Closure  $fail  검증 실패 콜백
     */
    private function assertSameOriginList(array $list, string $field, Closure $fail): void
    {
        foreach ($list as $entry) {
            if (! is_array($entry) || ! isset($entry[$field]) || ! is_string($entry[$field])) {
                continue;
            }

            $url = trim($entry[$field]);

            if ($url === '') {
                continue;
            }

            // 표현식 바인딩(`{{...}}`)은 런타임 해석 대상이라 여기서 판정하지 않는다.
            if (str_starts_with($url, '{{')) {
                continue;
            }

            // same-origin path 이거나, 확장이 선언한 신뢰 호스트면 허용. 그 외 외부 origin 차단.
            if (! $this->isSameOriginPath($url)
                && ! TrustedScriptHosts::isTrustedUrl($url, $this->trustedHosts())) {
                $fail(__('validation.layout.external_resource_url', ['url' => $url]));

                return;
            }
        }
    }

    /**
     * 신뢰 외부 스크립트 호스트 목록을 반환합니다 (검증 1회당 1회 집계 후 캐시).
     *
     * @return array<int, string> 신뢰 호스트명 목록
     */
    private function trustedHosts(): array
    {
        return $this->trustedHosts ??= TrustedScriptHosts::hosts();
    }

    /**
     * same-origin path-only URL 인지 판정합니다.
     *
     * 허용: `/` 로 시작하는 경로. 차단: `//`(protocol-relative), scheme 포함 절대 URL.
     *
     * @param  string  $url  검사 대상 URL
     * @return bool same-origin path 이면 true
     */
    private function isSameOriginPath(string $url): bool
    {
        $normalized = self::normalizeForOriginCheck($url);

        // protocol-relative (`//evil.com/...`) 차단
        if (str_starts_with($normalized, '//')) {
            return false;
        }

        // scheme 포함 절대 URL(`https://`, `javascript:`, `data:` 등) 차단
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $normalized) === 1) {
            return false;
        }

        // path-only: `/` 로 시작해야 same-origin 절대 경로
        return str_starts_with($normalized, '/');
    }

    /**
     * origin 판정 전에 URL 을 브라우저 URL 파서와 동일하게 정규화합니다.
     *
     * 문자열 접두 검사만으로는 authority 우회를 막지 못합니다. 브라우저(WHATWG URL)는
     * 파싱 전에 ASCII tab·개행을 제거하고, special scheme(http/https)에서 백슬래시를
     * 슬래시와 동등하게 처리하기 때문입니다. 따라서 `/\/evil.com/x.js` ·
     * `/{tab}/evil.com/x.js` 는 `//` 로 시작하지 않는데도 실제로는
     * `https://evil.com/x.js` 로 해석됩니다.
     *
     * 정규화 후 판정하면 경로 중간의 백슬래시·탭(`/js/a\b.js`)은 authority 를 만들지
     * 않으므로 그대로 통과합니다(과차단 없음).
     *
     * 클라이언트(`TemplateApp.isAllowedScriptSrc`)·정적 검사
     * (`layout-scripts-src-same-origin`)와 3층 동형이어야 합니다.
     *
     * 구현 SSoT 는 `TrustedScriptHosts::normalizeForOriginCheck` 입니다 — 같은 저장측
     * 판정 안에서 same-origin 검사(이 규칙)와 신뢰 호스트 검사(`TrustedScriptHosts`)가
     * 이어 붙으므로, 두 검사가 서로 다른 정규화를 쓰면 한 URL 이 "path 도 아니고
     * 외부 호스트도 아닌" 상태로 빠져나간다. 위임으로 그 갈림을 구조적으로 막는다.
     *
     * @param  string  $url  원본 URL
     * @return string 정규화된 URL
     */
    public static function normalizeForOriginCheck(string $url): string
    {
        return TrustedScriptHosts::normalizeForOriginCheck($url);
    }
}
