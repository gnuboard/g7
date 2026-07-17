<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 저작 태그(`<data-grid>`)를 JSON 노드의 `name`+`type` 으로 해석하는 컴파일 시점 레지스트리.
 *
 * **소스는 대상 템플릿의 `components.json` 단일이다.** 모듈·플러그인도 각자 `components.json`
 * 을 갖고 있으나 실측상 소비 경로가 없다 — 프론트에서 `/api/{modules,plugins}/{id}/components.json`
 * 을 부르는 호출자가 0건이고(런타임 레지스트리는 `/api/templates/{id}/components.json` 만
 * fetch — ComponentRegistry.ts:250), 번들 확장의 매니페스트는 전부 컴포넌트 0개다. 확장 UI 는
 * 컴포넌트 등록이 아니라 `extension_point` 노드로 주입되어 레지스트리를 우회한다. 따라서
 * 컴파일러가 확장 매니페스트를 병합하면 런타임에 없는 태그를 저작 가능하게 만드는 셈이 된다.
 *
 * 런타임 매니페스트 검증(ComponentRegistry.ts:287-347)과 **같은 강도로** 검증한다. 저작 시점에
 * 통과시켜 봐야 런타임이 `MANIFEST_INVALID` 로 거부하면 화면이 통째로 죽기 때문이다.
 *
 * prop 선언(`props`)은 타입 판정 **힌트로만** 쓰고 화이트리스트로 쓰지 않는다 — 실측상 선언
 * 커버리지가 75~77% 에 그치고 미선언 목록 상위가 `Button.type`·`Input.value` 같은 정상
 * props 라, 화이트리스트로 강제하면 정상 저작이 대량 거부된다(ComponentDefinition::propType 참조).
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

    /** 매니페스트가 반드시 배열로 담아야 하는 컴포넌트 분류 (런타임 검증과 동일) */
    private const CATEGORIES = ['basic', 'composite', 'layout'];

    /** 오타 제안을 시도할 최대 편집 거리 — 이보다 멀면 제안이 오히려 혼란을 준다 */
    private const SUGGESTION_MAX_DISTANCE = 3;

    /** @var array<string, ComponentDefinition> 태그명 → 정의 */
    private array $byTag = [];

    /** @var array<string, ComponentDefinition> 컴포넌트명 → 정의 */
    private array $byName = [];

    /**
     * @param  string  $templateId  매니페스트가 선언한 템플릿 식별자
     * @param  array<int, ComponentDefinition>  $definitions  컴포넌트 정의 목록
     */
    private function __construct(public readonly string $templateId, array $definitions)
    {
        foreach ($definitions as $definition) {
            $this->byTag[$definition->tag] = $definition;
            $this->byName[$definition->name] = $definition;
        }
    }

    /**
     * 매니페스트 파일에서 레지스트리를 로드합니다.
     *
     * @param  string  $path  `components.json` 경로
     * @return self 레지스트리
     *
     * @throws CompileError 파일을 읽을 수 없거나 매니페스트가 스키마를 위반한 경우
     */
    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestUnreadable, $path);
        }

        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalidJson, $path, [
                'reason' => json_last_error_msg(),
            ]);
        }

        return self::fromArray($manifest, $path);
    }

    /**
     * 이미 디코드된 매니페스트 배열에서 레지스트리를 만듭니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string|null  $path  오류 메시지에 실을 매니페스트 경로
     * @return self 레지스트리
     *
     * @throws CompileError 매니페스트가 스키마를 위반하거나 태그가 모호한 경우
     */
    public static function fromArray(array $manifest, ?string $path = null): self
    {
        // 런타임(ComponentRegistry.ts:289-303)은 version/templateId 를 falsy 검사만 하고
        // name/type 에만 typeof 를 건다. 컴파일러가 그 비대칭을 그대로 옮기면 `false`·`0`
        // 같은 값이 통과하고(런타임보다 오히려 느슨하다), templateId 는 아래에서 문자열로
        // 캐스팅되므로 배열이 들어오면 조용히 "Array" 라는 식별자가 된다. 저작 시점에
        // 걸러야 할 값이 런타임까지 흘러가지 않도록 두 필드 모두 비지 않은 문자열로 못박는다.
        foreach (['version', 'templateId'] as $field) {
            if (! is_string($manifest[$field] ?? null) || $manifest[$field] === '') {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $field]);
            }
        }

        if (! is_array($manifest['components'] ?? null)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => 'components']);
        }

        $definitions = [];
        $tagOwners = [];

        foreach (self::CATEGORIES as $category) {
            $entries = $manifest['components'][$category] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, [
                    'field' => 'components.'.$category,
                ]);
            }

            foreach ($entries as $index => $entry) {
                $definition = self::makeDefinition($entry, $category, $index, $path);

                // 태그가 겹치면 어느 컴포넌트를 뜻하는지 저작자도 컴파일러도 알 수 없다.
                // 런타임은 나중에 등록된 쪽이 조용히 덮어쓰지만(ComponentRegistry.ts:456),
                // 침묵 승자를 만드는 대신 매니페스트를 고치게 한다.
                if (isset($tagOwners[$definition->tag])) {
                    throw CompileError::inFile(LayoutMarkupErrorCode::AmbiguousComponentTag, $path, [
                        'tag' => $definition->tag,
                        'first' => $tagOwners[$definition->tag],
                        'second' => $definition->name,
                    ]);
                }

                $tagOwners[$definition->tag] = $definition->name;
                $definitions[] = $definition;
            }
        }

        return new self($manifest['templateId'], $definitions);
    }

    /**
     * 매니페스트 엔트리 하나를 정의로 변환합니다.
     *
     * @param  mixed  $entry  매니페스트 엔트리
     * @param  string  $category  엔트리가 실린 분류 배열 이름
     * @param  int  $index  분류 배열 내 위치 (오류 메시지용)
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return ComponentDefinition 컴포넌트 정의
     *
     * @throws CompileError 엔트리에 name/type 이 없거나 type 이 알 수 없는 값인 경우
     */
    private static function makeDefinition(mixed $entry, string $category, int $index, ?string $path): ComponentDefinition
    {
        $at = sprintf('components.%s[%d]', $category, $index);

        if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] === '') {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $at.'.name']);
        }

        if (! is_string($entry['type'] ?? null) || ! in_array($entry['type'], self::CATEGORIES, true)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $at.'.type']);
        }

        // 노드 `type` 은 **엔트리의 type 필드**를 따른다. 분류 배열과 어긋나는 실사용이 있고
        // (admin_basic 의 RichSelect·DropdownButton·DropdownMenuItem 은 layout 배열에 있으나
        // type=composite), 레이아웃 JSON 도 엔트리 값을 쓴다. 런타임이 등록하는 메타데이터가
        // 엔트리 객체 자체라 실동작이 엔트리 값으로 정해지기 때문이다.
        $propTypes = [];

        foreach ((array) ($entry['props'] ?? []) as $prop => $meta) {
            if (is_string($prop) && is_array($meta) && is_string($meta['type'] ?? null)) {
                $propTypes[$prop] = $meta['type'];
            }
        }

        $declaredSkip = array_values(array_filter(
            (array) ($entry['skipBindingKeys'] ?? []),
            static fn ($key): bool => is_string($key),
        ));

        return new ComponentDefinition(
            name: $entry['name'],
            tag: self::toTag($entry['name']),
            type: $entry['type'],
            propTypes: $propTypes,
            skipBindingKeys: array_values(array_unique([...self::DEFAULT_SKIP_BINDING_KEYS, ...$declaredSkip])),
        );
    }

    /**
     * 태그명으로 컴포넌트를 찾습니다.
     *
     * @param  string  $tag  저작 태그명 (소문자 kebab)
     * @return ComponentDefinition|null 정의, 미등록이면 null
     */
    public function resolveTag(string $tag): ?ComponentDefinition
    {
        return $this->byTag[$tag] ?? null;
    }

    /**
     * 컴포넌트명으로 정의를 찾습니다.
     *
     * @param  string  $name  컴포넌트명
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
     * 제안 없는 거부는 저작자를 같은 실수로 되돌리므로(CompileError 규약), 오타 거리 안에
     * 후보가 있으면 함께 알려 준다.
     *
     * @param  string  $tag  저작자가 쓴 태그명
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
     * 컴포넌트명을 저작 태그명으로 변환합니다.
     *
     * `DataGrid` → `data-grid`, `H1` → `h1`, `RichSelect` → `rich-select`. 번들 템플릿 전수
     * (admin_basic 117종 / basic 65종 / hello_user 8종) 실측 결과 이 변환의 태그 충돌은 0건이다.
     *
     * @param  string  $name  컴포넌트명 (Pascal)
     * @return string 태그명 (소문자 kebab)
     */
    public static function toTag(string $name): string
    {
        // 두 경계를 나눈다: 소문자·숫자 다음의 대문자(`DataGrid` → `Data-Grid`)와,
        // 연속 대문자 뒤에 오는 단어 시작(`HTMLEditor` → `HTML-Editor`).
        $tag = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
        $tag = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $tag) ?? $tag;

        return strtolower($tag);
    }
}
