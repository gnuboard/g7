<?php

namespace App\Support\LayoutMarkup;

use App\Support\TrustedScriptHosts;

/**
 * 한 `.g7.html` 문서를 검증·lint 할 때 필요한, 문서 밖의 문맥.
 *
 * 컴파일 자체(토큰→트리→노드/문서)는 소스와 레지스트리만 있으면 되지만, S6 의 강검증·lint 은
 * 문서가 **어디에 속하는지**를 알아야 하는 것들이 있다:
 *
 *  - 플러그인 완전 레이아웃 금지 lint 는 이 문서가 플러그인 소속인지(sourceKind) 알아야 한다.
 *  - gdpr_consent_log 예외는 소유 확장 식별자(identifier)로 판정한다.
 *  - 미등록 핸들러 lint 는 대상 템플릿이 등록한 핸들러 목록(templateHandlers)을 알아야 한다.
 *  - nesting lint 는 대상 템플릿의 nesting.json accepts 맵(nestingRules)을 알아야 한다.
 *  - 외부 JS 로더 허용 호스트는 확장·템플릿 선언 집계 ∪ 문서 소유 확장의 선언 ∪ 운영자 설정이다.
 *    설치 경로는 이 검사를 하지 않는다(JSON 설치가 하지 않았던 것과 같다 — 판정은 런타임 게이트 몫).
 *
 * 이 값들은 문서마다 다르지 않고 **설치 단위(확장/템플릿)마다** 정해지므로, 커맨드가 한 번
 * 구성해 여러 문서에 재사용한다. 단위 테스트는 필요한 필드만 채운 최소 문맥을 만든다.
 */
final class CompileContext
{
    /**
     * @param  string|null  $sourceKind  소유 소스 종류 (`template`·`module`·`plugin`·null)
     * @param  string|null  $identifier  소유 확장·템플릿 식별자 (`sirsoft-gdpr` 등)
     * @param  array<int, string>  $templateHandlers  대상 템플릿이 등록한 핸들러 이름 목록
     * @param  array<string, array<int, string>>  $nestingRules  컨테이너 name → 허용 자식 name 목록
     * @param  array<int, string>|null  $scriptSrcAllowlist  외부 JS 로더 허용 호스트 고정값 (null 이면 선언 집계 ∪ 운영자 설정)
     * @param  array<int, string>  $declaredScriptHosts  문서 소유 확장(과 오버라이드 대상 확장)이 매니페스트로 선언한 호스트
     * @param  bool  $checksScriptSrc  외부 JS 로더 호스트를 검사하는지 (설치 경로는 false)
     * @param  array<string, int>  $toleratedComponents  편집기 전용 — 저장본에 이미 있던 미등록 템플릿 컴포넌트 이름 → 허용 개수 (#712)
     */
    public function __construct(
        public readonly ?string $sourceKind = null,
        public readonly ?string $identifier = null,
        public readonly array $templateHandlers = [],
        public readonly array $nestingRules = [],
        public readonly ?array $scriptSrcAllowlist = null,
        public readonly array $declaredScriptHosts = [],
        public readonly bool $checksScriptSrc = true,
        public readonly array $toleratedComponents = [],
    ) {}

    /**
     * 외부 JS 로더 허용 호스트 목록을 반환합니다.
     *
     * 문맥에 고정값이 있으면 그것을, 없으면 활성 확장·템플릿의 선언 집계 ∪ 문서 소유 확장의 선언 ∪
     * 운영자 설정(`template.layout_markup.script_src_allowlist`)을 쓴다. 소유 확장의 선언을 따로 더하는
     * 것은, 아직 활성화되지 않은 확장의 레이아웃을 점검할 때도 그 확장이 선언한 호스트가 허용돼야 해서다.
     *
     * @return array<int, string> 허용 호스트 목록 (소문자, 중복 제거)
     */
    public function scriptSrcAllowlist(): array
    {
        if ($this->scriptSrcAllowlist !== null) {
            return $this->scriptSrcAllowlist;
        }

        $operator = config('template.layout_markup.script_src_allowlist', []);
        $hosts = array_merge(
            TrustedScriptHosts::hosts(),
            $this->declaredScriptHosts,
            is_array($operator) ? $operator : [],
        );

        $normalized = [];
        foreach ($hosts as $host) {
            if (is_string($host) && ($host = strtolower(trim($host))) !== '') {
                $normalized[$host] = true;
            }
        }

        return array_keys($normalized);
    }
}
