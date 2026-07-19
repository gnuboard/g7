<?php

namespace App\Support\LayoutMarkup;

/**
 * 왕복 하네스 — `compile(decompile(json)) ≡ json` 을 검증하는 S9 의 기계 오라클.
 *
 * 역변환기의 정확성은 이 왕복으로만 봉인된다: JSON 을 `.g7.html` 로 되돌리고(decompile→serialize),
 * 그 소스를 다시 컴파일해(tokenize→tree→document) 원본과 **정규화 후 deep-equal** 을 본다. 강검증·lint
 * 는 왕복 구조 검증과 무관하므로 건너뛰고 순수 컴파일 사슬만 태운다.
 *
 * **정규화 = 키 순서 무관, 리스트 순서 유지.** 실 레이아웃은 저자 임의 순서라 top-level 키 순서가
 * 컴파일러 KEY_ORDER 와 다르다(예: 손저작 순서 vs `order()`). 객체 키는 재귀 ksort 로 순서를 지우고
 * 리스트는 순서를 지켜 비교한다 — JSON 의미론적 동등성 그대로다. provenance 표식(meta.authoring)은
 * 비교 전 strip 한다(컴파일러 산출엔 없으므로). 빈 `{}`↔`[]` 는 `json_decode(assoc)` 가 양쪽에서
 * 똑같이 접어 발산하지 않는다.
 */
final class LayoutMarkupRoundTrip
{
    /**
     * JSON 레이아웃 하나의 왕복을 검증합니다.
     *
     * @param  array<string, mixed>  $json  원본 JSON 레이아웃
     * @param  ComponentRegistry  $registry  대상 레지스트리
     * @param  string|null  $path  경로 (오류 메시지용)
     * @return array{ok: bool, error: string|null, markup: string|null, source: array<string, mixed>, recompiled: array<string, mixed>|null}
     */
    public function verify(array $json, ComponentRegistry $registry, ?string $path = null): array
    {
        $source = LayoutProvenance::strip($json);

        try {
            $markup = (new LayoutMarkupDecompiler($registry))->decompile($source);

            $tokens = (new MarkupTokenizer)->tokenize($markup, $path);
            $roots = (new MarkupTreeBuilder)->build($tokens, $path);
            $recompiled = (new DocumentCompiler($registry))->compile($roots, $path);
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => $error->getMessage(), 'markup' => $markup ?? null, 'source' => $source, 'recompiled' => null];
        }

        $ok = self::encode(self::canonicalize(self::normalize($source))) === self::encode(self::canonicalize(self::normalize($recompiled)));

        return ['ok' => $ok, 'error' => null, 'markup' => $markup, 'source' => $source, 'recompiled' => $recompiled];
    }

    /**
     * 컴파일러가 결정론적으로 파생·주입하는 값을 지워 비교를 정규화합니다 ("입력만 받고 정규화").
     *
     * 두 값은 저작 표면이 아니라 컴파일 산출이라, 손저작 소스에 낡은 값이 남아 있어도 이관 산출은
     * 컴파일러의 정본으로 수렴한다 — 그 수렴을 왕복 실패로 세면 안 된다:
     *
     *  - **노드 `type`**(basic/composite/layout): 레지스트리(name)에서 파생된다. 소스에 낡은
     *    type(예: Textarea 를 composite 로 캐시)이 있어도 재컴파일은 레지스트리 정본을 낸다. name 비교는
     *    엄격히 유지하므로(태그를 잘못 내면 name 이 어긋나 잡힌다) type 만 지워도 안전하다.
     *  - **`meta.is_partial`**: `<partial>` 컴파일이 항상 주입한다. 소스에 없어도 산출엔 있으므로 양쪽에서 지운다.
     *
     * @param  mixed  $value  값
     * @return mixed 정규화된 값
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // 노드(name + 파생 type)에서 파생 type 제거.
        if (isset($value['name']) && is_string($value['name'])
            && isset($value['type']) && in_array($value['type'], ['basic', 'composite', 'layout'], true)) {
            unset($value['type']);
        }

        // 컴파일러 주입 is_partial 제거 — 그 결과 meta 가 비면 meta 자체도 제거(빈 meta 는 방출되지 않음).
        if (isset($value['meta']) && is_array($value['meta'])) {
            unset($value['meta']['is_partial']);

            if ($value['meta'] === []) {
                unset($value['meta']);
            }
        }

        // 제어 핸들러의 params.{actions,cases,conditions} → top-level (컴파일러 정본). 런타임은 둘을
        // 동치로 읽으므로(ActionDispatcher params.actions fallback) 이관은 top-level 로 수렴한다.
        if (in_array($value['handler'] ?? null, ['sequence', 'parallel', 'switch', 'conditions'], true) && isset($value['params']) && is_array($value['params'])) {
            foreach (['actions', 'cases', 'conditions'] as $controlKey) {
                if (! isset($value[$controlKey]) && isset($value['params'][$controlKey])) {
                    $value[$controlKey] = $value['params'][$controlKey];
                    unset($value['params'][$controlKey]);
                }
            }

            if ($value['params'] === []) {
                unset($value['params']);
            }
        }

        // 노드 액션 콜백의 단일 원소 배열 → 객체 (컴파일러 compileCallbackActions 미러: 1개=객체).
        // onSuccess:[{x}] 와 onSuccess:{x} 는 런타임 동치이고 컴파일 정본은 객체다 — 이관은 객체로 수렴한다.
        foreach (['onSuccess', 'onError', 'onReceive'] as $callback) {
            if (isset($value[$callback]) && is_array($value[$callback])
                && array_is_list($value[$callback]) && count($value[$callback]) === 1) {
                $value[$callback] = $value[$callback][0];
            }
        }

        // Select 의 리프트 가능한 `<option>` 자식 → props.options (컴파일러 shouldLiftOptions 미러).
        // 소스가 Option 자식으로, 산출이 options 배열로 갈리는 이형을 정본(options)으로 수렴시킨다.
        $value = self::liftSelectOptions($value);

        // 컴파일러가 항상 생략하는 빈 컨테이너(빈 props/children)를 제거 — `props:[]` 는 방출되지 않는다.
        foreach (['props', 'children'] as $container) {
            if (array_key_exists($container, $value) && $value[$container] === []) {
                unset($value[$container]);
            }
        }

        return array_map([self::class, 'normalize'], $value);
    }

    /**
     * Select 노드의 리프트 가능한 Option 자식을 props.options 로 접습니다 (컴파일러 liftOptions 미러).
     *
     * @param  array<string, mixed>  $node  노드
     * @return array<string, mixed> 정규화된 노드
     */
    private static function liftSelectOptions(array $node): array
    {
        if (($node['name'] ?? null) !== 'Select' || empty($node['children']) || ! is_array($node['children'])) {
            return $node;
        }

        $props = $node['props'] ?? [];

        if (isset($props['options']) || isset($props['multiple']) || isset($props['size'])) {
            return $node;
        }

        $options = [];

        foreach ($node['children'] as $child) {
            if (! is_array($child) || ($child['name'] ?? null) !== 'Option' || ! empty($child['children'])) {
                return $node;
            }

            $childProps = $child['props'] ?? [];

            if (! array_key_exists('value', $childProps) || array_diff(array_keys($childProps), ['value', 'disabled']) !== []) {
                return $node;
            }

            $option = ['value' => $childProps['value'], 'label' => (string) ($child['text'] ?? '')];

            if (! empty($childProps['disabled'])) {
                $option['disabled'] = true;
            }

            $options[] = $option;
        }

        $props['options'] = $options;
        $node['props'] = $props;
        unset($node['children']);

        return $node;
    }

    /**
     * 마크업에서 방출된 `<json>` 탈출구 키를 추출합니다 (예산 계량 — 결함 인벤토리 대조용).
     *
     * `<json key="X">` 는 키 X 를, `<json>`(무키)은 노드 전체 탈출을 `*whole-node*` 로 집계한다.
     *
     * @param  string  $markup  `.g7.html` 소스
     * @return array<int, string> 탈출 키 목록 (중복 포함)
     */
    public static function escapedKeys(string $markup): array
    {
        preg_match_all('/<json(?:\s+key="([^"]*)")?\s*>/', $markup, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $m): string => ($m[1] ?? '') === '' ? '*whole-node*' : $m[1],
            $matches,
        );
    }

    /**
     * 값을 정규화합니다 — 연관 배열은 키 정렬(순서 무관), 리스트는 순서 유지.
     *
     * @param  mixed  $value  값
     * @return mixed 정규화된 값
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);

        return array_map([self::class, 'canonicalize'], $value);
    }

    /**
     * 정규화된 값을 결정적 JSON 문자열로 인코드합니다 (비교·diff 용).
     *
     * @param  mixed  $value  값
     * @return string JSON 문자열
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
