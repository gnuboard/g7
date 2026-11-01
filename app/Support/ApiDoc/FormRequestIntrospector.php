<?php

namespace App\Support\ApiDoc;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * FormRequest 정적 분석기
 *
 * 컨트롤러 메서드 시그니처에서 FormRequest 를 찾아 rules() 를 리플렉션으로
 * 읽고, 요청 파라미터의 타입·필수 여부·허용값을 문서용 메타데이터로 변환합니다.
 */
class FormRequestIntrospector
{
    /**
     * 컨트롤러 메서드의 첫 FormRequest 파라미터에서 rules 메타데이터를 추출합니다.
     *
     * @param  string|null  $controller  컨트롤러 FQCN
     * @param  string|null  $method  메서드명
     *                               `rules_resolved` 는 규칙을 실제로 읽어 냈는지다 — FormRequest 가 없거나 `rules()` 호출이 실패하면 false 다.
     *                               파라미터가 비어 있을 때 「없다」가 사실인지 「읽지 못했다」인지를 가르는 근거다. `referenced_names` 는 요청
     *                               클래스(와 그 트레이트) 소스에 따옴표로 적힌 이름이다 — 규칙 밖에서 직접 읽는 파라미터(`$this->boolean('draft')`)를
     *                               「없어진 파라미터」로 잘못 판정하지 않게 하는 근거다.
     * @return array{request_class: string|null, params: array<int, array<string, mixed>>, hook_filters: array<int, string>, rules_resolved: bool, referenced_names: array<int, string>}
     */
    public function introspect(?string $controller, ?string $method): array
    {
        $empty = ['request_class' => null, 'params' => [], 'hook_filters' => [], 'rules_resolved' => false, 'referenced_names' => []];

        if (! $controller || ! $method || ! class_exists($controller)) {
            return $empty;
        }

        try {
            $ref = new ReflectionMethod($controller, $method);
        } catch (\ReflectionException) {
            return $empty;
        }

        $requestClass = $this->findFormRequestParam($ref);

        if (! $requestClass) {
            return $empty;
        }

        $rules = $this->extractRules($requestClass);

        return [
            'request_class' => $requestClass,
            'params' => $this->rulesToParams($rules ?? []),
            'hook_filters' => $this->extractHookFilters($requestClass),
            'rules_resolved' => $rules !== null,
            'referenced_names' => $this->referencedNames([$requestClass, $controller]),
        ];
    }

    /**
     * 메서드 파라미터 중 FormRequest 하위 클래스를 찾습니다.
     *
     * @param  ReflectionMethod  $ref  메서드 리플렉션
     * @return class-string<FormRequest>|null FormRequest FQCN
     */
    private function findFormRequestParam(ReflectionMethod $ref): ?string
    {
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * FormRequest 인스턴스를 만들어 rules() 를 호출합니다.
     *
     * @param  class-string<FormRequest>  $requestClass  FormRequest FQCN
     * @return array<string, mixed>|null 검증 규칙 배열 (읽어 내지 못하면 null)
     */
    private function extractRules(string $requestClass): ?array
    {
        try {
            $instance = new $requestClass;

            if (! method_exists($instance, 'rules')) {
                return [];
            }

            $rules = $instance->rules();

            return is_array($rules) ? $rules : null;
        } catch (\Throwable) {
            // rules() 가 컨테이너/route 의존이면 정적 호출 실패 — 파라미터 표는 비우고, 읽지 못했다고 알린다.
            return null;
        }
    }

    /**
     * 검증 규칙 배열을 문서용 파라미터 메타데이터로 변환합니다.
     *
     * @param  array<string, mixed>  $rules  검증 규칙 배열
     * @return array<int, array<string, mixed>> 파라미터 메타데이터 목록
     */
    private function rulesToParams(array $rules): array
    {
        $params = [];

        foreach ($rules as $field => $rule) {
            // 배열 요소 규칙(items.*.id)은 상위 필드(items)만 대표로 노출한다.
            // 점(`.`) 포함 여부만 보면 와일드카드가 없는 중첩 **객체** 필드
            // (refund_bank.bank_code, general.site_name 등)까지 버려져 문서에서 통째로 빠진다.
            if (str_contains((string) $field, '*')) {
                continue;
            }

            $tokens = is_array($rule) ? $rule : explode('|', (string) $rule);
            $tokens = array_map(fn ($t) => is_string($t) ? $t : '', $tokens);

            // `accepted` 는 값이 `yes`, `on`, `1`, `true` 로 실려 와야만 통과한다 — 사실상 필수 boolean 이다.
            // 조건부(`accepted_if`)는 필수가 아니지만 값의 성격은 같다.
            $accepted = in_array('accepted', $tokens, true);
            $acceptLike = $accepted || array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'accepted_if')) !== [];

            // 규칙에 타입 토큰이 없으면 `string` 은 생성기가 모를 때 쓰는 기본값이다(`type_known` false).
            $typeKnown = $acceptLike || array_filter(
                $tokens,
                static fn (string $t): bool => preg_match('/^(integer|numeric|boolean|array|string|date|email|file|image|uuid)/', $t) === 1
            ) !== [];

            $params[] = [
                'name' => $field,
                'type_known' => $typeKnown,
                'type' => $acceptLike ? 'boolean' : $this->inferType($tokens),
                'required' => in_array('required', $tokens, true) || $accepted,
                'allowed' => $this->inferAllowed($tokens),
            ];
        }

        return $params;
    }

    /**
     * 규칙 토큰에서 파라미터 타입을 유추합니다.
     *
     * @param  array<int, string>  $tokens  규칙 토큰 목록
     * @return string 유추된 타입
     */
    private function inferType(array $tokens): string
    {
        foreach (['integer', 'numeric', 'boolean', 'array', 'string', 'date', 'email', 'file', 'image', 'uuid'] as $type) {
            foreach ($tokens as $t) {
                if ($t === $type || str_starts_with($t, $type)) {
                    return $type === 'numeric' ? 'number' : $type;
                }
            }
        }

        return 'string';
    }

    /**
     * 규칙 토큰에서 허용값(in:, max:, min: 등)을 유추합니다.
     *
     * @param  array<int, string>  $tokens  규칙 토큰 목록
     * @return string 허용값 설명 (없으면 빈 문자열)
     */
    private function inferAllowed(array $tokens): string
    {
        $parts = [];

        foreach ($tokens as $t) {
            if (str_starts_with($t, 'in:')) {
                $parts[] = '`'.str_replace(',', '`, `', substr($t, 3)).'`';
            } elseif (str_starts_with($t, 'max:')) {
                $parts[] = 'max '.substr($t, 4);
            } elseif (str_starts_with($t, 'min:')) {
                $parts[] = 'min '.substr($t, 4);
            } elseif (str_starts_with($t, 'between:')) {
                $parts[] = 'between '.substr($t, 8);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * 요청 클래스, 컨트롤러와 그 트레이트 소스에 따옴표로 적힌 이름을 모읍니다.
     *
     * 컨트롤러가 규칙 밖에서 직접 읽는 파라미터(`$request->input('sort')`)도 여기에 걸린다.
     *
     * @param  array<int, string|null>  $classes  대상 클래스 FQCN (null 은 건너뜀)
     * @return array<int, string> 이름 목록 (중복 제거)
     */
    private function referencedNames(array $classes): array
    {
        try {
            $files = [];
            $pending = array_map(
                static fn (string $class): ReflectionClass => new ReflectionClass($class),
                array_values(array_filter($classes, static fn (?string $class): bool => $class !== null && class_exists($class)))
            );

            while ($pending !== []) {
                $class = array_pop($pending);
                $file = $class->getFileName();

                if ($file && ! isset($files[$file]) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                    $files[$file] = true;
                    foreach ($class->getTraits() as $trait) {
                        $pending[] = $trait;
                    }
                }
            }

            $names = [];
            foreach (array_keys($files) as $file) {
                // SCIM 확장 스키마처럼 `:`, `-` 가 든 URN 이름도 파라미터 이름이 될 수 있다.
                if (is_readable($file) && preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_.*:\-]*)[\'"]/', (string) file_get_contents($file), $m)) {
                    array_push($names, ...$m[1]);
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * FormRequest 소스에서 HookManager::applyFilters 훅 이름을 추출합니다.
     *
     * @param  class-string<FormRequest>  $requestClass  FormRequest FQCN
     * @return array<int, string> 훅 필터 이름 목록
     */
    private function extractHookFilters(string $requestClass): array
    {
        try {
            $file = (new ReflectionClass($requestClass))->getFileName();

            if (! $file || ! is_readable($file)) {
                return [];
            }

            $source = file_get_contents($file);
            preg_match_all('/applyFilters\(\s*[\'"]([a-z0-9_.-]+)[\'"]/i', $source, $m);

            return array_values(array_unique($m[1] ?? []));
        } catch (\Throwable) {
            return [];
        }
    }
}
