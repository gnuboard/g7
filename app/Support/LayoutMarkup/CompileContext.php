<?php

namespace App\Support\LayoutMarkup;

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
 *  - 외부 JS 로더 화이트리스트는 config 에서 오지만, 테스트가 문맥으로 주입할 수 있게 여기에 둔다.
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
     * @param  array<int, string>|null  $scriptSrcAllowlist  외부 JS 로더 허용 호스트 (null 이면 config)
     */
    public function __construct(
        public readonly ?string $sourceKind = null,
        public readonly ?string $identifier = null,
        public readonly array $templateHandlers = [],
        public readonly array $nestingRules = [],
        public readonly ?array $scriptSrcAllowlist = null,
    ) {}

    /**
     * 외부 JS 로더 허용 호스트 목록을 반환합니다 (문맥 우선, 없으면 config).
     *
     * @return array<int, string> 허용 호스트 목록
     */
    public function scriptSrcAllowlist(): array
    {
        return $this->scriptSrcAllowlist ?? config('template.layout_markup.script_src_allowlist', []);
    }
}
