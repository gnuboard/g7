<?php

namespace App\Support\LayoutMarkup;

use App\Enums\ComponentOwnerKind;
use App\Enums\LayoutMarkupErrorCode;

/**
 * 한 소유자(템플릿 또는 확장)의 `components.json` 을 컴파일러가 쓰는 형태로 정규화한 것.
 *
 * 템플릿과 확장은 같은 스키마를 쓰되 자신을 밝히는 필드만 다르다(`templateId` vs
 * `identifier`). 런타임 검증이 템플릿 매니페스트의 `templateId` 를 필수로 보므로
 * (ComponentRegistry.ts:297) 필드명 자체는 통일하지 않고, **컴파일러가 하나의 "소유자"
 * 개념으로 흡수**한다 — 소유자 종류는 어느 필드가 있는지로 판정한다.
 *
 * 소유자 종류가 정하는 것:
 *  - **이름·태그 좌표**. 템플릿 컴포넌트는 `DataGrid`/`<data-grid>`. 확장 컴포넌트는
 *    `{식별자}.{이름}`/`<{식별자}.{kebab}>` — 식별자를 그대로 싣으므로 목록 없이 이름을 되돌린다
 *    (ComponentRegistry::fromTag). 핸들러의 `{identifier}.{name}` 관례와 같은 키 컨벤션이다.
 *  - **선언 권한**. `overrides` 는 템플릿만, `for` 는 확장만(ComponentOwnerKind 참조).
 */
final class ComponentManifest
{
    /** 매니페스트가 반드시 배열로 담아야 하는 컴포넌트 분류 (런타임 검증과 동일) */
    public const CATEGORIES = ['basic', 'composite', 'layout'];

    /**
     * @param  string  $ownerId  소유자 식별자 (템플릿 id 또는 확장 identifier)
     * @param  ComponentOwnerKind  $ownerKind  소유자 종류
     * @param  string  $version  매니페스트가 선언한 소유자 버전
     * @param  array<int, ComponentDefinition>  $definitions  이 소유자가 제공하는 컴포넌트
     * @param  array<int, ComponentOverride>  $overrides  이 소유자가 대체하는 확장 컴포넌트 (템플릿만)
     * @param  array<string, array<int, string>>  $forVariants  템플릿 id → 그 템플릿 전용 판본이 있는 짧은 이름 (확장만)
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     */
    private function __construct(
        public readonly string $ownerId,
        public readonly ComponentOwnerKind $ownerKind,
        public readonly string $version,
        public readonly array $definitions,
        public readonly array $overrides,
        public readonly array $forVariants,
        public readonly ?string $path,
    ) {}

    /**
     * 매니페스트 파일을 읽어 정규화합니다.
     *
     * @param  string  $path  `components.json` 경로
     * @return self 정규화된 매니페스트
     *
     * @throws CompileError 파일을 읽을 수 없거나 스키마를 위반한 경우
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
     * 디코드된 매니페스트 배열을 정규화합니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return self 정규화된 매니페스트
     *
     * @throws CompileError 스키마를 위반하거나 선언 권한을 벗어난 경우
     */
    public static function fromArray(array $manifest, ?string $path = null): self
    {
        $kind = self::resolveOwnerKind($manifest, $path);
        $ownerId = self::requireNonEmptyString($manifest, $kind->ownerField(), $path);
        $version = self::requireNonEmptyString($manifest, 'version', $path);

        if (! is_array($manifest['components'] ?? null)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => 'components']);
        }

        $definitions = self::readDefinitions($manifest['components'], $ownerId, $kind, $path);

        return new self(
            ownerId: $ownerId,
            ownerKind: $kind,
            version: $version,
            definitions: $definitions,
            overrides: self::readOverrides($manifest, $ownerId, $kind, $path),
            forVariants: self::readForVariants($manifest, $kind, $definitions, $path),
            path: $path,
        );
    }

    /**
     * 매니페스트가 자신을 밝힌 필드로 소유자 종류를 판정합니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return ComponentOwnerKind 소유자 종류
     *
     * @throws CompileError 소유자 필드가 둘 다 있거나 둘 다 없는 경우
     */
    private static function resolveOwnerKind(array $manifest, ?string $path): ComponentOwnerKind
    {
        $hasTemplateId = array_key_exists('templateId', $manifest);
        $hasIdentifier = array_key_exists('identifier', $manifest);

        // 둘 다 적으면 어느 좌표계로 등록할지 — 프리픽스 없는 템플릿 이름인지 `{id}.{name}`
        // 인지 — 매니페스트만으로 정할 수 없다. 침묵 선택 대신 매니페스트를 고치게 한다.
        if ($hasTemplateId && $hasIdentifier) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestOwnerAmbiguous, $path);
        }

        if ($hasTemplateId) {
            return ComponentOwnerKind::Template;
        }

        if ($hasIdentifier) {
            return ComponentOwnerKind::Extension;
        }

        throw CompileError::inFile(LayoutMarkupErrorCode::ManifestOwnerMissing, $path);
    }

    /**
     * 매니페스트 필드가 비지 않은 문자열인지 확인하고 반환합니다.
     *
     * 런타임(ComponentRegistry.ts:289-303)은 version/templateId 를 falsy 검사만 하고 name/type
     * 에만 `typeof` 를 걸어 그 자체가 비대칭이다. 그 비대칭을 그대로 옮기면 `false`·`0` 이
     * 통과해 오히려 런타임보다 느슨해지고, 식별자는 문자열로 캐스팅되므로 배열이 들어오면
     * "Array" 라는 유령 식별자가 조용히 만들어진다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string  $field  필드명
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return string 필드 값
     *
     * @throws CompileError 필드가 없거나 비지 않은 문자열이 아닌 경우
     */
    private static function requireNonEmptyString(array $manifest, string $field, ?string $path): string
    {
        if (! is_string($manifest[$field] ?? null) || $manifest[$field] === '') {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $field]);
        }

        return $manifest[$field];
    }

    /**
     * `components` 3분류 배열을 정의 목록으로 변환합니다.
     *
     * @param  array<mixed>  $components  `components` 값
     * @param  string  $ownerId  소유자 식별자
     * @param  ComponentOwnerKind  $kind  소유자 종류
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return array<int, ComponentDefinition> 컴포넌트 정의 목록
     *
     * @throws CompileError 분류 배열이나 엔트리가 스키마를 위반한 경우
     */
    private static function readDefinitions(array $components, string $ownerId, ComponentOwnerKind $kind, ?string $path): array
    {
        $definitions = [];

        foreach (self::CATEGORIES as $category) {
            $entries = $components[$category] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, [
                    'field' => 'components.'.$category,
                ]);
            }

            foreach ($entries as $index => $entry) {
                $definitions[] = self::makeDefinition(
                    $entry,
                    $ownerId,
                    $kind,
                    sprintf('components.%s[%d]', $category, $index),
                    $path
                );
            }
        }

        return $definitions;
    }

    /**
     * 매니페스트 엔트리 하나를 정의로 변환합니다.
     *
     * @param  mixed  $entry  매니페스트 엔트리
     * @param  string  $ownerId  소유자 식별자
     * @param  ComponentOwnerKind  $kind  소유자 종류
     * @param  string  $at  엔트리 위치 (오류 메시지용)
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return ComponentDefinition 컴포넌트 정의
     *
     * @throws CompileError 엔트리에 name/type 이 없거나 type 이 알 수 없는 값인 경우
     */
    private static function makeDefinition(mixed $entry, string $ownerId, ComponentOwnerKind $kind, string $at, ?string $path): ComponentDefinition
    {
        if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] === '') {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $at.'.name']);
        }

        // 노드 `type` 은 분류 배열이 아니라 **엔트리의 type 필드**를 따른다. 분류 배열과
        // 어긋나는 실사용이 있고(admin_basic 의 RichSelect·DropdownButton 은 layout 배열에
        // 있으나 type=composite), 런타임이 등록하는 메타데이터가 엔트리 객체 자체라
        // 실동작이 엔트리 값으로 정해지기 때문이다.
        if (! is_string($entry['type'] ?? null) || ! in_array($entry['type'], self::CATEGORIES, true)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $at.'.type']);
        }

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

        $shortName = $entry['name'];

        return new ComponentDefinition(
            name: $kind === ComponentOwnerKind::Extension ? $ownerId.'.'.$shortName : $shortName,
            // 태그는 이름의 표기 규칙 하나로 정한다 — 확장이면 `식별자.케밥`(목록 없이 되돌릴 수 있다).
            tag: ComponentRegistry::toTag($kind === ComponentOwnerKind::Extension ? $ownerId.'.'.$shortName : $shortName),
            type: $entry['type'],
            propTypes: $propTypes,
            skipBindingKeys: array_values(array_unique([
                ...ComponentRegistry::DEFAULT_SKIP_BINDING_KEYS,
                ...$declaredSkip,
            ])),
            owner: $kind === ComponentOwnerKind::Extension ? $ownerId : null,
        );
    }

    /**
     * `overrides` 선언을 읽습니다 (템플릿 전용).
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  string  $ownerId  소유자 식별자
     * @param  ComponentOwnerKind  $kind  소유자 종류
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return array<int, ComponentOverride> 오버라이드 선언 목록
     *
     * @throws CompileError 확장이 선언했거나 엔트리가 스키마를 위반한 경우
     */
    private static function readOverrides(array $manifest, string $ownerId, ComponentOwnerKind $kind, ?string $path): array
    {
        $declared = $manifest['overrides'] ?? null;

        if ($declared === null) {
            return [];
        }

        // 오버라이드 선언 권한은 템플릿 전용이다 — 레이아웃 오버라이드가 source_type=template
        // 일 때만 성립하는 것과 같은 규약(migration `:22`). 확장이 남의 컴포넌트를 대체하기
        // 시작하면 서로 덮어쓰는 교착이 생기고, 그 승자 규칙은 선례가 없다.
        if (! $kind->mayDeclareOverrides()) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ExtensionOverridesForbidden, $path, [
                'extension' => $ownerId,
            ]);
        }

        if (! is_array($declared) || ! array_is_list($declared)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => 'overrides']);
        }

        $overrides = [];
        $seen = [];

        foreach ($declared as $index => $entry) {
            $at = sprintf('overrides[%d]', $index);

            if (! is_array($entry) || ! is_string($entry['target'] ?? null) || $entry['target'] === '') {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => $at.'.target']);
            }

            $target = $entry['target'];

            if (array_key_exists('targetVersion', $entry)
                && (! is_string($entry['targetVersion']) || $entry['targetVersion'] === '')) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, [
                    'field' => $at.'.targetVersion',
                ]);
            }

            // 대체본은 대상 확장의 좌표(`{target}.{name}`)에 그대로 들어앉는다 — 마크업 태그가
            // 바뀌면 대체가 아니라 새 컴포넌트다.
            $definition = self::makeDefinition($entry, $target, ComponentOwnerKind::Extension, $at, $path);

            if (isset($seen[$definition->name])) {
                throw CompileError::inFile(LayoutMarkupErrorCode::DuplicateOverrideTarget, $path, [
                    'component' => $definition->name,
                ]);
            }

            $seen[$definition->name] = true;
            $overrides[] = new ComponentOverride(
                target: $target,
                targetVersion: $entry['targetVersion'] ?? null,
                definition: $definition,
            );
        }

        return $overrides;
    }

    /**
     * `for` 선언(템플릿 전용 판본)을 읽습니다 (확장 전용).
     *
     * 판본 **선택**은 런타임 관심사라 컴파일러는 태그 해석에 이 값을 쓰지 않는다 — 어느
     * 판본이든 같은 이름으로 해석되고 props 는 verbatim 통과(D19)라 컴파일 계약이 갈리지
     * 않기 때문이다. 다만 오타는 여기서 잡는다: 판본만 있고 원본이 없으면 그 판본은 어느
     * 태그로도 작성할 수 없는 죽은 코드다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @param  ComponentOwnerKind  $kind  소유자 종류
     * @param  array<int, ComponentDefinition>  $definitions  이 소유자가 제공하는 컴포넌트
     * @param  string|null  $path  매니페스트 경로 (오류 메시지용)
     * @return array<string, array<int, string>> 템플릿 id → 짧은 이름 목록
     *
     * @throws CompileError 템플릿이 선언했거나 없는 컴포넌트를 지목한 경우
     */
    private static function readForVariants(array $manifest, ComponentOwnerKind $kind, array $definitions, ?string $path): array
    {
        $declared = $manifest['for'] ?? null;

        if ($declared === null) {
            return [];
        }

        if (! $kind->mayDeclareForVariants()) {
            throw CompileError::inFile(LayoutMarkupErrorCode::TemplateForVariantForbidden, $path);
        }

        if (! is_array($declared) || array_is_list($declared)) {
            throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, ['field' => 'for']);
        }

        $ownShortNames = [];

        foreach ($definitions as $definition) {
            $ownShortNames[substr($definition->name, strrpos($definition->name, '.') + 1)] = true;
        }

        $variants = [];

        foreach ($declared as $templateId => $names) {
            if (! is_string($templateId) || $templateId === '' || ! is_array($names) || ! array_is_list($names)) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ManifestInvalid, $path, [
                    'field' => 'for.'.(is_string($templateId) ? $templateId : '?'),
                ]);
            }

            foreach ($names as $name) {
                if (! is_string($name) || ! isset($ownShortNames[$name])) {
                    throw CompileError::inFile(LayoutMarkupErrorCode::ForVariantComponentUnknown, $path, [
                        'component' => is_string($name) ? $name : gettype($name),
                        'template' => $templateId,
                    ]);
                }
            }

            $variants[$templateId] = array_values($names);
        }

        return $variants;
    }
}
