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
     * @return array{request_class: string|null, params: array<int, array<string, mixed>>, hook_filters: array<int, string>}
     */
    public function introspect(?string $controller, ?string $method): array
    {
        $empty = ['request_class' => null, 'params' => [], 'hook_filters' => []];

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
            'params' => $this->rulesToParams($rules),
            'hook_filters' => $this->extractHookFilters($requestClass),
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
     * @return array<string, mixed> 검증 규칙 배열
     */
    private function extractRules(string $requestClass): array
    {
        try {
            $instance = new $requestClass;

            if (! method_exists($instance, 'rules')) {
                return [];
            }

            $rules = $instance->rules();

            return is_array($rules) ? $rules : [];
        } catch (\Throwable) {
            // rules() 가 컨테이너/route 의존이면 정적 호출 실패 — 파라미터 표는 비운다.
            return [];
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
            // 중첩 필드(items.*.id)는 상위만 대표로 노출
            if (str_contains((string) $field, '.')) {
                continue;
            }

            $tokens = is_array($rule) ? $rule : explode('|', (string) $rule);
            $tokens = array_map(fn ($t) => is_string($t) ? $t : '', $tokens);

            $params[] = [
                'name' => $field,
                'type' => $this->inferType($tokens),
                'required' => in_array('required', $tokens, true),
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
