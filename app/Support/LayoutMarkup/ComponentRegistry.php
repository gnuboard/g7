<?php

namespace App\Support\LayoutMarkup;

use App\Enums\ComponentOwnerKind;
use App\Enums\LayoutMarkupErrorCode;

/**
 * 마크업 태그(`<data-grid>`)를 JSON 노드의 `name`+`type` 으로 해석하는 컴파일 시점 레지스트리.
 *
 * **소스는 3계통이다 — 대상 템플릿 + 활성 모듈 + 활성 플러그인**(D25, D20 개정). 확장이 자기
 * 컴포넌트를 제공할 수 있게 되면서 마크업 어휘가 템플릿 단독 소유에서 벗어났다. 좌표계는 소유자로
 * 갈린다:
 *
 *  - 템플릿 소유: `DataGrid` → `<data-grid>`
 *  - 확장 소유: `sirsoft-ecommerce.ProductCard` → `<sirsoft-ecommerce-product-card>`
 *
 * 확장 프리픽스 덕에 템플릿↔확장, 확장↔확장 이름 충돌은 구조적으로 불가능하다. 남는 충돌은
 * ① 템플릿이 확장 프리픽스와 겹치는 이름을 쓴 경우(`Gnuboard7HelloModuleMemoCard`) ② 서로 다른
 * 확장 식별자가 같은 태그 프리픽스로 정규화되는 경우(`a_b` 와 `a-b`) 둘뿐이고, 양쪽 다 거부한다.
 * 런타임은 나중 등록이 조용히 이기지만(ComponentRegistry.ts:456-458) 그 침묵 승자를 작성 시점
 * 오류로 끌어올린다(D24 침묵 차단 원칙의 연장).
 *
 * 태그 해석은 **전방 매핑**이다 — (소유자, 이름)에서 태그를 만들어 평면 맵에 넣고 정확히 조회한다.
 * 태그를 (확장 id, 이름)으로 **역파싱**하지 않으므로 D25 가 우려한 최장 일치 모호성이 해석 경로에
 * 생기지 않는다. 모호성은 서로 다른 두 정의가 **같은 태그를 만들어 낼 때**만 성립하며, 그것은
 * 맵 구축 시점에 결정적으로 검출된다(`AmbiguousComponentTag`).
 *
 * 런타임 매니페스트 검증(ComponentRegistry.ts:287-347)과 같은 강도로 검증하되, version/식별자는
 * 의도적으로 더 엄격하게 본다(D24 ⑧ — 런타임의 falsy 검사를 그대로 옮기면 오히려 느슨해진다).
 *
 * prop 선언(`props`)은 타입 판정 **힌트로만** 쓰고 화이트리스트로 쓰지 않는다 — 실측상 선언
 * 커버리지가 75~77% 에 그치고 미선언 목록 상위가 `Button.type`·`Input.value` 같은 정상
 * props 라, 화이트리스트로 강제하면 정상 마크업이 대량 거부된다(ComponentDefinition::propType 참조).
 */
final class ComponentRegistry
{
    /**
     * 런타임이 하드코딩으로 항상 선평가에서 제외하는 키 (DynamicRenderer.tsx:2239).
     *
     * 매니페스트의 컴포넌트별 `skipBindingKeys` 선언과 **합집합**으로 병합된다(:2246). 하드코딩
     * 4종만으로는 DataGrid 의 `subRowChildren`/`subRowCondition`, RichSelect 의 `children`/
     * `selectedChildren`, DropdownButton 의 `children` 이 누락되어 선평가 패리티가 깨진다.
     */
    public const DEFAULT_SKIP_BINDING_KEYS = ['cellChildren', 'expandChildren', 'expandContext', 'render'];

    /** 오타 제안을 시도할 최대 편집 거리 — 이보다 멀면 제안이 오히려 혼란을 준다 */
    private const SUGGESTION_MAX_DISTANCE = 3;

    /** @var array<string, ComponentDefinition> 태그명 → 정의 */
    private array $byTag = [];

    /** @var array<string, ComponentDefinition> 노드 name → 정의 */
    private array $byName = [];

    /**
     * @param  string  $templateId  대상 템플릿 식별자
     * @param  array<int, ComponentDefinition>  $definitions  컴포넌트 정의 목록 (오버라이드 적용 후)
     * @param  string|null  $path  대상 템플릿 매니페스트 경로 (오류 메시지용)
     *
     * @throws CompileError 두 정의가 같은 태그로 해석되는 경우
     */
    private function __construct(public readonly string $templateId, array $definitions, ?string $path = null)
    {
        foreach ($definitions as $definition) {
            if (isset($this->byTag[$definition->tag])) {
                throw CompileError::inFile(LayoutMarkupErrorCode::AmbiguousComponentTag, $path, [
                    'tag' => $definition->tag,
                    'first' => $this->byTag[$definition->tag]->name,
                    'second' => $definition->name,
                ]);
            }

            $this->byTag[$definition->tag] = $definition;
            $this->byName[$definition->name] = $definition;
        }
    }

    /**
     * 템플릿 매니페스트 파일 하나에서 레지스트리를 로드합니다.
     *
     * 확장 컴포넌트가 없는 경로(단위 테스트·확장 미설치 환경)용 편의 생성자다. 확장 컴포넌트를
     * 함께 해석하려면 `fromManifests()` 를 쓴다.
     *
     * @param  string  $path  템플릿 `components.json` 경로
     * @return self 레지스트리
     *
     * @throws CompileError 파일을 읽을 수 없거나 매니페스트가 스키마를 위반한 경우
     */
    public static function fromFile(string $path): self
    {
        return self::fromManifests(ComponentManifest::fromFile($path));
    }

    /**
     * 이미 디코드된 템플릿 매니페스트 배열에서 레지스트리를 만듭니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string|null  $path  오류 메시지에 실을 매니페스트 경로
     * @return self 레지스트리
     *
     * @throws CompileError 매니페스트가 스키마를 위반하거나 태그가 모호한 경우
     */
    public static function fromArray(array $manifest, ?string $path = null): self
    {
        return self::fromManifests(ComponentManifest::fromArray($manifest, $path));
    }

    /**
     * 템플릿 + 활성 확장 매니페스트를 병합해 레지스트리를 만듭니다.
     *
     * @param  ComponentManifest  $template  대상 템플릿의 매니페스트
     * @param  array<int, ComponentManifest>  $extensions  활성 모듈·플러그인의 매니페스트 목록
     * @return self 레지스트리
     *
     * @throws CompileError 소유자 종류가 어긋나거나, 확장 식별자 프리픽스가 겹치거나, 태그가 모호한 경우
     */
    public static function fromManifests(ComponentManifest $template, array $extensions = []): self
    {
        if ($template->ownerKind !== ComponentOwnerKind::Template) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $template->path, [
                'field' => ComponentOwnerKind::Template->ownerField(),
            ]);
        }

        self::assertExtensionKinds($extensions);

        $definitions = (new ComponentOverrideResolver)->resolve($template, $extensions);

        return new self($template->ownerId, $definitions, $template->path);
    }

    /**
     * 확장 자리에 온 매니페스트가 모두 확장 소유인지 확인합니다.
     *
     * @param  array<int, ComponentManifest>  $extensions  확장 매니페스트 목록
     * @return void 반환값 없음
     *
     * @throws CompileError 템플릿 매니페스트가 확장 자리에 온 경우
     */
    private static function assertExtensionKinds(array $extensions): void
    {
        foreach ($extensions as $extension) {
            if ($extension->ownerKind !== ComponentOwnerKind::Extension) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $extension->path, [
                    'field' => ComponentOwnerKind::Extension->ownerField(),
                ]);
            }
        }
    }

    /**
     * 태그명으로 컴포넌트를 찾습니다.
     *
     * @param  string  $tag  마크업 태그명 (소문자 kebab)
     * @return ComponentDefinition|null 정의, 미등록이면 null
     */
    public function resolveTag(string $tag): ?ComponentDefinition
    {
        return $this->byTag[$tag] ?? null;
    }

    /**
     * 노드 name 으로 정의를 찾습니다.
     *
     * @param  string  $name  노드 name (템플릿 소유는 `DataGrid`, 확장 소유는 `{식별자}.{이름}`)
     * @return ComponentDefinition|null 정의, 미등록이면 null
     */
    public function findByName(string $name): ?ComponentDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * 등록된 모든 태그명을 반환합니다.
     *
     * @return array<int, string> 태그명 목록
     */
    public function tags(): array
    {
        return array_keys($this->byTag);
    }

    /**
     * 미등록 태그에 가장 가까운 등록 태그를 제안합니다.
     *
     * 제안 없는 거부는 작성자를 같은 실수로 되돌리므로(CompileError 규약), 오타 거리 안에
     * 후보가 있으면 함께 알려 준다. 확장 컴포넌트도 같은 평면에 있으므로
     * `<sirsoft-ecommerce-produkt-card>` 는 `<sirsoft-ecommerce-product-card>` 를 제안받는다.
     *
     * @param  string  $tag  작성자가 쓴 태그명
     * @return string|null 제안 태그, 마땅한 후보가 없으면 null
     */
    public function suggestTag(string $tag): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (array_keys($this->byTag) as $candidate) {
            $distance = levenshtein($tag, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $bestDistance <= self::SUGGESTION_MAX_DISTANCE ? $best : null;
    }

    /**
     * 컴포넌트명을 마크업 태그명으로 변환합니다.
     *
     * `DataGrid` → `data-grid`, `H1` → `h1`, `RichSelect` → `rich-select`. 번들 템플릿 전수
     * (admin_basic 117종 / basic 65종 / hello_user 8종) 실측 결과 이 변환의 태그 충돌은 0건이다.
     *
     * 확장 컴포넌트(`식별자.이름`)는 `식별자.케밥` 이 된다 — 식별자는 그대로 둔다.
     *
     * @param  string  $name  컴포넌트명 (Pascal)
     * @return string 태그명 (소문자 kebab)
     */
    public static function toTag(string $name): string
    {
        // 확장 컴포넌트 `식별자.이름` — 식별자는 그대로 두고 이름만 kebab 으로 바꾼다.
        if (str_contains($name, '.')) {
            [$owner, $local] = explode('.', $name, 2);

            return $owner.'.'.self::toTag($local);
        }

        // 두 경계를 나눈다: 소문자·숫자 다음의 대문자(`DataGrid` → `Data-Grid`)와,
        // 연속 대문자 뒤에 오는 단어 시작(`HTMLEditor` → `HTML-Editor`).
        $tag = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
        $tag = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $tag) ?? $tag;

        return strtolower($tag);
    }

    /**
     * 마크업 태그명을 컴포넌트명으로 되돌립니다 — 템플릿 없이 정해지는 표기 규칙.
     *
     * `data-grid` → `DataGrid`, `h1` → `H1`, `div` → `Div`. 컴파일은 이 규칙만으로 이름을 정한다 —
     * JSON 시절 작성자가 `name` 을 직접 적던 것과 같이, 어떤 템플릿의 컴포넌트 목록도 보지 않는다.
     * 번들·설치 템플릿 4종 392개 컴포넌트 전부가 `fromTag(toTag(name)) === name` 을 만족한다.
     * 되돌릴 수 없는 이름(연속 대문자 등)은 역변환이 `<json>` 탈출구로 적는다.
     *
     * @param  string  $tag  태그명 (소문자 kebab)
     * @return string 컴포넌트명 (Pascal)
     */
    public static function fromTag(string $tag): string
    {
        // 확장 컴포넌트 `식별자.케밥` → `식별자.이름` (JSON 표기와 같다).
        if (str_contains($tag, '.')) {
            [$owner, $local] = explode('.', $tag, 2);

            return $owner.'.'.self::fromTag($local);
        }

        return implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $tag),
        ));
    }

    /**
     * 이름이 표기 규칙으로 왕복되는지 판정합니다 (역변환이 태그로 적을 수 있는가).
     *
     * @param  string  $name  컴포넌트명
     * @return bool 왕복되면 true
     */
    public static function isTagRoundTrippable(string $name): bool
    {
        if (preg_match('/^(?:[a-z0-9_]+(?:-[a-z0-9_]+)+\\.)?[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            return false;
        }

        return self::fromTag(self::toTag($name)) === $name && preg_match(MarkupTokenizer::TAG_NAME_PATTERN, self::toTag($name)) === 1;
    }
}
