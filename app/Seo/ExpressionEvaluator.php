<?php

namespace App\Seo;

use Illuminate\Support\Arr;

class ExpressionEvaluator
{
    /**
     * 파이프 레지스트리
     */
    private PipeRegistry $pipeRegistry;

    /**
     * 템플릿 번역 사전
     */
    private array $translations = [];

    /**
     * SEO 상태 오버라이드 (seo-config.json의 seo_overrides)
     *
     * 구조: { "_local": { "collapsedReplies": { "*": false } } }
     * 와일드카드 "*" 키는 동적 접근 시 기본값을 반환합니다.
     */
    private array $seoOverrides = [];

    public function __construct()
    {
        $this->pipeRegistry = new PipeRegistry;
    }

    /**
     * 파이프 레지스트리를 반환합니다.
     *
     * @return PipeRegistry 파이프 레지스트리 인스턴스
     */
    public function getPipeRegistry(): PipeRegistry
    {
        return $this->pipeRegistry;
    }

    /**
     * 템플릿 번역 사전을 설정합니다.
     *
     * @param  array  $translations  번역 사전 (dot notation 접근 가능)
     */
    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }

    /**
     * SEO 상태 오버라이드를 설정합니다.
     *
     * seo-config.json의 seo_overrides 섹션으로, SEO 렌더링 시
     * _local/_global 상태에 기본값을 주입합니다.
     * 와일드카드("*")를 통해 동적 접근 시 기본값을 반환할 수 있습니다.
     *
     * @param  array  $overrides  오버라이드 정의 (예: {"_local": {"collapsedReplies": {"*": false}}})
     */
    public function setSeoOverrides(array $overrides): void
    {
        $this->seoOverrides = $overrides;
    }

    /**
     * 표현식이 포함된 문자열을 평가합니다.
     *
     * @param  string  $expression  표현식 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return string 평가된 문자열
     */
    /**
     * 표현식을 평가하여 원본 값(배열/객체 포함)을 반환합니다.
     *
     * text_format의 dot notation 등에서 객체 prop을 해석할 때 사용합니다.
     * 단일 {{expr}} 패턴일 때만 원본 값을 반환하고, 복합 표현식은 문자열을 반환합니다.
     *
     * @param  string  $expression  표현식
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 원본 값 (배열, 문자열, 숫자 등)
     */
    public function evaluateRaw(string $expression, array $context): mixed
    {
        // 단일 {{expr}} 패턴인 경우 원본 값 반환
        if (preg_match('/^\{\{(.+?)\}\}$/', trim($expression), $matches)) {
            $expr = trim($matches[1]);

            // 파이프 표현식: 원본 값을 파이프로 변환 후 반환
            if (PipeRegistry::hasPipes($expr)) {
                [$baseExpr, $pipes] = PipeRegistry::splitPipes($expr);
                $value = $this->resolveToValue($baseExpr, $context);

                foreach ($pipes as $pipeExpr) {
                    $parsed = PipeRegistry::parsePipeExpression($pipeExpr);
                    if ($this->pipeRegistry->has($parsed['name'])) {
                        $value = $this->pipeRegistry->execute($parsed['name'], $value, $parsed['args']);
                    }
                }

                return $value;
            }

            // 연산자/함수 호출/리터럴이 포함된 표현식은 resolveToValue로 처리
            if (str_contains($expr, '??') || str_contains($expr, '?') || preg_match('/[\w$]+\s*\(/', $expr)
                || str_starts_with($expr, '{') || str_contains($expr, '...')) {
                return $this->resolveToValue($expr, $context);
            }

            return $this->resolvePath($expr, $context);
        }

        // 복합 표현식은 문자열로 반환
        return $this->evaluate($expression, $context);
    }

    /**
     * ?? 연산자를 포함한 표현식을 원본 값으로 평가합니다.
     *
     * evaluateRaw에서 단일 {{expr ?? fallback}} 패턴 처리 시 사용됩니다.
     * 좌측 경로의 원본 값(배열/객체 등)을 유지하면서 null coalescing을 수행합니다.
     *
     * @param  string  $expr  표현식 ({{ }} 없이)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 원본 값
     */
    private function evaluateRawWithNullCoalescing(string $expr, array $context): mixed
    {
        $parts = $this->splitOutsideBrackets($expr, '??');
        if (count($parts) !== 2) {
            return $this->resolvePath($expr, $context);
        }

        $value = $this->resolvePath(trim($parts[0]), $context);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $fallback = trim($parts[1]);

        // 빈 배열 리터럴 []
        if ($fallback === '[]') {
            return [];
        }

        // 따옴표 감싸인 문자열 리터럴
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $fallback, $m)) {
            return $m[1];
        }

        // 숫자 리터럴
        if (is_numeric($fallback)) {
            return $fallback + 0;
        }

        // 표현식으로 재귀 해석
        return $this->resolvePath($fallback, $context);
    }

    public function evaluate(string $expression, array $context): string
    {
        // $t: 번역 키 처리 (전체가 $t:로 시작하는 경우)
        if (str_starts_with($expression, '$t:')) {
            return $this->resolveTranslation($expression, $context);
        }

        // {{binding | pipe}} 패턴 치환 (파이프 표현식 지원)
        $result = (string) preg_replace_callback('/\{\{(.+?)\}\}/', function ($matches) use ($context) {
            $expr = trim($matches[1]);

            // 파이프 분리: expr | pipe1 | pipe2(arg)
            if (PipeRegistry::hasPipes($expr)) {
                [$baseExpr, $pipes] = PipeRegistry::splitPipes($expr);
                $value = $this->resolveToValue($baseExpr, $context);

                foreach ($pipes as $pipeExpr) {
                    $parsed = PipeRegistry::parsePipeExpression($pipeExpr);
                    if ($this->pipeRegistry->has($parsed['name'])) {
                        $value = $this->pipeRegistry->execute($parsed['name'], $value, $parsed['args']);
                    }
                }

                return $this->valueToString($value);
            }

            return $this->evaluateExpression($expr, $context);
        }, $expression);

        // 인라인 $t:key 토큰 해석 ({{}} 외부 텍스트 내 $t:key|param=value 패턴)
        if (str_contains($result, '$t:')) {
            $result = preg_replace_callback('/\$t:([\w.\-]+(?:\|[\w.\-]+=[\w.\-{}]+)*)/', function ($matches) use ($context) {
                return $this->resolveTranslation('$t:'.$matches[1], $context);
            }, $result);
        }

        // 후처리: 빈 {{}} 치환으로 남은 고립된 연산자/부호 정리 (예: "+", "-")
        $cleaned = trim($result);
        if ($cleaned !== '' && strlen($cleaned) <= 2 && preg_match('/^[+\-\/*]+$/', $cleaned)) {
            return '';
        }

        return $result;
    }

    /**
     * $t: 번역 키를 해석합니다.
     *
     * @param  string  $expression  번역 표현식 ($t:key 또는 $t:key|param=value)
     * @param  array  $context  데이터 컨텍스트 (파라미터 값의 {{}} 표현식 해석용)
     * @return string 번역된 문자열
     */
    private function resolveTranslation(string $expression, array $context = []): string
    {
        $key = substr($expression, 3);

        // 파라미터 분리: $t:key|param1=value1|param2=value2
        $params = [];
        if (str_contains($key, '|')) {
            $parts = explode('|', $key);
            $key = $parts[0];
            foreach (array_slice($parts, 1) as $param) {
                if (str_contains($param, '=')) {
                    [$paramKey, $paramValue] = explode('=', $param, 2);
                    $paramValue = trim($paramValue);

                    // {{}} 표현식이 포함된 파라미터 값을 해석
                    if (str_contains($paramValue, '{{') && ! empty($context)) {
                        $paramValue = (string) preg_replace_callback('/\{\{(.+?)\}\}/', function ($m) use ($context) {
                            return $this->evaluateExpression(trim($m[1]), $context);
                        }, $paramValue);
                    }

                    $params[trim($paramKey)] = $paramValue;
                }
            }
        }

        // 1. 템플릿 번역 사전에서 먼저 조회
        if (! empty($this->translations)) {
            $translated = Arr::get($this->translations, $key);
            if ($translated !== null && is_string($translated)) {
                // 파라미터 치환: :param 또는 {{param}} → value
                // 템플릿 번역은 {{param}} 형식, Laravel 번역은 :param 형식
                foreach ($params as $paramKey => $paramValue) {
                    $translated = str_replace(
                        ['{{'.$paramKey.'}}', ':'.$paramKey],
                        [$paramValue, $paramValue],
                        $translated
                    );
                }

                return $translated;
            }
        }

        // 2. Laravel 번역 fallback
        $translated = __($key, $params);

        // 번역 키가 그대로 반환되면 빈 문자열 (SEO 페이지에서 raw key 노출 방지)
        if ($translated === $key) {
            return '';
        }

        return $translated;
    }

    /**
     * 단일 표현식을 평가합니다.
     *
     * @param  string  $expr  표현식
     * @param  array  $context  데이터 컨텍스트
     * @return string 평가된 값
     */
    private function evaluateExpression(string $expr, array $context): string
    {
        // 0. 외부 괄호 제거: (expr) → expr (재귀 평가)
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')')) {
            $inner = substr($expr, 1, -1);
            $depth = 0;
            $valid = true;
            for ($i = 0; $i < strlen($inner); $i++) {
                if ($inner[$i] === '(') {
                    $depth++;
                } elseif ($inner[$i] === ')') {
                    $depth--;
                    if ($depth < 0) {
                        $valid = false;
                        break;
                    }
                }
            }
            if ($valid && $depth === 0) {
                return $this->evaluateExpression($inner, $context);
            }
        }

        // 0.5. 괄호 하위 표현식 + 후속 프로퍼티 접근: (expr).property
        // JavaScript의 (array ?? []).length 같은 패턴을 PHP에서 해석
        // @since engine-v1.17.6
        if (str_starts_with($expr, '(')) {
            $depth = 0;
            $closingPos = null;
            for ($i = 0; $i < strlen($expr); $i++) {
                if ($expr[$i] === '(') {
                    $depth++;
                } elseif ($expr[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $closingPos = $i;
                        break;
                    }
                }
            }
            if ($closingPos !== null && $closingPos < strlen($expr) - 1) {
                $trailing = substr($expr, $closingPos + 1);
                // (subexpr).prop 패턴: 후속 부분이 .으로 시작하고 단순 프로퍼티 경로
                if (preg_match('/^\.([\w.]+)$/', $trailing, $propMatch)) {
                    $innerExpr = substr($expr, 1, $closingPos - 1);
                    $innerValue = $this->resolveToValue($innerExpr, $context);
                    if ($innerValue !== null) {
                        $current = $innerValue;
                        foreach (explode('.', $propMatch[1]) as $seg) {
                            if ($seg === '') {
                                continue;
                            }
                            $virtual = $this->resolveVirtualProperty($current, $seg);
                            if ($virtual !== null) {
                                $current = $virtual;

                                continue;
                            }
                            if (is_array($current) && array_key_exists($seg, $current)) {
                                $current = $current[$seg];

                                continue;
                            }

                            return '';
                        }

                        return $this->valueToString($current);
                    }
                }
            }
        }

        // 1. 삼항 연산자: a ? b : c (JS 우선순위에서 ||/&& 보다 낮으므로 최우선 분리)
        $ternaryResult = $this->evaluateTernary($expr, $context);
        if ($ternaryResult !== null) {
            return $ternaryResult;
        }

        // 1.5. null coalescing: value ?? 'default' (브래킷 내부 ?? 제외)
        if (str_contains($expr, '??')) {
            $parts = $this->splitOutsideBrackets($expr, '??');
            if (count($parts) === 2) {
                $value = $this->evaluateExpression(trim($parts[0]), $context);

                if ($value !== '') {
                    return $value;
                }

                $fallback = trim($parts[1]);

                // 따옴표 감싸인 문자열 리터럴
                if (preg_match('/^[\'"](.*)[\'"]\s*$/', $fallback, $m)) {
                    return $m[1];
                }

                // 빈 배열 리터럴 []
                if ($fallback === '[]') {
                    return '';
                }

                // 표현식으로 재귀 평가
                return $this->evaluateExpression($fallback, $context);
            }
        }

        // 2. 논리 OR: a || b
        if (str_contains($expr, '||')) {
            $parts = $this->splitOutsideBrackets($expr, '||');
            if (count($parts) === 2) {
                $leftVal = $this->evaluateExpression(trim($parts[0]), $context);
                if ($leftVal !== '' && $leftVal !== 'false' && $leftVal !== '0') {
                    return 'true';
                }
                $rightVal = $this->evaluateExpression(trim($parts[1]), $context);
                if ($rightVal !== '' && $rightVal !== 'false' && $rightVal !== '0') {
                    return 'true';
                }

                return 'false';
            }
        }

        // 3. 논리 AND: a && b
        if (str_contains($expr, '&&')) {
            $parts = $this->splitOutsideBrackets($expr, '&&');
            if (count($parts) === 2) {
                $leftVal = $this->evaluateExpression(trim($parts[0]), $context);
                if ($leftVal === '' || $leftVal === 'false' || $leftVal === '0') {
                    return 'false';
                }
                $rightVal = $this->evaluateExpression(trim($parts[1]), $context);
                if ($rightVal === '' || $rightVal === 'false' || $rightVal === '0') {
                    return 'false';
                }

                return 'true';
            }
        }

        // 4. 비교 연산자: a > b, a === 'value', 등
        if ($this->isSimpleComparison($expr)) {
            return $this->evaluateComparison($expr, $context);
        }

        // 4.5. 산술 연산: expr +-*/% N (우변이 숫자 리터럴인 경우)
        if (preg_match('/^(.+?)\s*([+\-*\/%])\s*(\d+(?:\.\d+)?)$/', $expr, $arithMatch)) {
            $leftVal = $this->evaluateExpression(trim($arithMatch[1]), $context);
            if (is_numeric($leftVal)) {
                $operator = $arithMatch[2];
                $right = (float) $arithMatch[3];
                $left = (float) $leftVal;
                $result = match ($operator) {
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $right != 0 ? $left / $right : 0,
                    '%' => $right != 0 ? fmod($left, $right) : 0,
                };

                return (string) ($result == (int) $result ? (int) $result : $result);
            }
        }

        // 5. 부정 연산자: !path
        if (str_starts_with($expr, '!') && ! str_starts_with($expr, '!=')) {
            $inner = trim(substr($expr, 1));
            $value = $this->evaluateExpression($inner, $context);

            return (! $value || $value === 'false' || $value === '0') ? 'true' : 'false';
        }

        // 6. 메서드/함수 호출 처리 ($t, $localized 등 $ 접두사 함수 포함)
        if (preg_match('/[\w$]+\s*\(/', $expr)) {
            // 7a. 순수 메서드 호출: expr.method(args)
            if (str_ends_with($expr, ')')) {
                $callResult = $this->evaluateCallExpression($expr, $context);
                if ($callResult !== null) {
                    return $this->valueToString($callResult);
                }
            }

            // 7b. 메서드 호출 + 후속 프로퍼티: expr.method(args).prop
            $lastParen = strrpos($expr, ')');
            if ($lastParen !== false && $lastParen < strlen($expr) - 1) {
                $methodPart = substr($expr, 0, $lastParen + 1);
                $propPart = substr($expr, $lastParen + 1);

                if (str_starts_with($propPart, '.')) {
                    $callResult = $this->evaluateCallExpression($methodPart, $context);
                    if ($callResult !== null) {
                        $current = $callResult;
                        foreach (explode('.', str_replace('?.', '.', ltrim($propPart, '.'))) as $seg) {
                            if ($seg === '') {
                                continue;
                            }
                            $virtual = $this->resolveVirtualProperty($current, $seg);
                            if ($virtual !== null) {
                                $current = $virtual;

                                continue;
                            }
                            if (is_array($current) && array_key_exists($seg, $current)) {
                                $current = $current[$seg];

                                continue;
                            }

                            return '';
                        }

                        return $this->valueToString($current);
                    }
                }
            }

            return '';
        }

        // 8. 리터럴 값 감지 (숫자, 따옴표 문자열, boolean/null)
        if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
            return $expr; // 숫자 리터럴
        }
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $expr, $litMatch)) {
            return $litMatch[1]; // 문자열 리터럴
        }
        if ($expr === 'true' || $expr === 'false') {
            return $expr;
        }
        if ($expr === 'null' || $expr === 'undefined') {
            return '';
        }

        // 9. 경로 해석 (브래킷 접근자 포함)
        $value = $this->resolvePath($expr, $context);

        return $value !== null ? $this->valueToString($value) : '';
    }

    /**
     * 단순 비교 표현식인지 확인합니다.
     *
     * @param  string  $expr  표현식
     * @return bool 비교 표현식 여부
     */
    private function isSimpleComparison(string $expr): bool
    {
        // 우측: 숫자, 따옴표 문자열, boolean/null 리터럴, 단순 경로
        return (bool) preg_match(
            '/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*([\d.\-]+|\'[^\']*\'|"[^"]*"|true|false|null|[\w.\?\[\]]+)$/',
            $expr
        );
    }

    /**
     * 비교 표현식을 평가합니다.
     *
     * @param  string  $expr  비교 표현식
     * @param  array  $context  데이터 컨텍스트
     * @return string 'true' 또는 'false'
     */
    private function evaluateComparison(string $expr, array $context): string
    {
        if (! preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/', $expr, $matches)) {
            return '';
        }

        $leftExpr = trim($matches[1]);
        $operator = $matches[2];
        $rightExpr = trim($matches[3]);

        // 좌측 해석 (복합 표현식 또는 단순 경로)
        // ?.는 optional chaining(경로 구문)이므로 제외, 실제 연산자만 매칭
        if (preg_match('/\?\?|\|\||&&|\(|!/', $leftExpr)) {
            // 원본 타입 보존 평가 시도 (배열 다중 값 매칭 지원)
            $leftValue = $this->evaluateComparisonOperand($leftExpr, $context);
        } else {
            $leftValue = $this->resolvePath($leftExpr, $context);
        }

        // 우측 해석 (리터럴 또는 경로)
        $rightValue = $this->resolveComparisonValue($rightExpr, $context);

        // 좌측이 null이면 JavaScript null 시맨틱 적용
        // SEO 컨텍스트에서 _global/_local 등 미존재 경로 참조 시 null 반환
        // null !== value → true, null === value → false (JavaScript 동작과 일치)
        if ($leftValue === null) {
            return match ($operator) {
                '!==', '!=' => ($rightValue === null) ? 'false' : 'true',
                '===', '==' => ($rightValue === null) ? 'true' : 'false',
                default => '',
            };
        }

        $result = match ($operator) {
            '===', '==' => $this->looseEquals($leftValue, $rightValue),
            '!==', '!=' => ! $this->looseEquals($leftValue, $rightValue),
            '>' => is_numeric($leftValue) && is_numeric($rightValue) && (float) $leftValue > (float) $rightValue,
            '<' => is_numeric($leftValue) && is_numeric($rightValue) && (float) $leftValue < (float) $rightValue,
            '>=' => is_numeric($leftValue) && is_numeric($rightValue) && (float) $leftValue >= (float) $rightValue,
            '<=' => is_numeric($leftValue) && is_numeric($rightValue) && (float) $leftValue <= (float) $rightValue,
            default => false,
        };

        return $result ? 'true' : 'false';
    }

    /**
     * 비교 좌측 복합 표현식을 원본 타입 보존하며 평가합니다.
     *
     * evaluateExpression()은 string만 반환하므로 배열 타입이 유실됩니다.
     * 이 메서드는 (path ?? fallback) 패턴에서 배열 오버라이드를 보존하여
     * looseEquals()의 배열 다중 값 매칭이 동작하도록 합니다.
     *
     * @param  string  $expr  좌측 표현식 (예: "(_local.activeTab ?? 'info')")
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값 (배열 타입 보존)
     */
    private function evaluateComparisonOperand(string $expr, array $context): mixed
    {
        // 외부 괄호 제거
        $inner = $expr;
        if (str_starts_with($inner, '(') && str_ends_with($inner, ')')) {
            $body = substr($inner, 1, -1);
            $depth = 0;
            $valid = true;
            for ($i = 0; $i < strlen($body); $i++) {
                if ($body[$i] === '(') {
                    $depth++;
                } elseif ($body[$i] === ')') {
                    $depth--;
                    if ($depth < 0) {
                        $valid = false;
                        break;
                    }
                }
            }
            if ($valid && $depth === 0) {
                $inner = $body;
            }
        }

        // 단순 null coalescing (path ?? fallback): 원본 타입 보존
        if (str_contains($inner, '??')) {
            $parts = $this->splitOutsideBrackets($inner, '??');
            if (count($parts) === 2 && ! preg_match('/\|\||&&/', $inner)) {
                return $this->evaluateRawWithNullCoalescing($inner, $context);
            }
        }

        // 기타 복합 표현식: evaluateExpression으로 문자열 평가 (기존 동작)
        $evaluated = $this->evaluateExpression($expr, $context);

        return $evaluated !== '' ? $evaluated : null;
    }

    /**
     * 비교 우측 값을 해석합니다.
     *
     * @param  string  $expr  우측 표현식
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값
     */
    private function resolveComparisonValue(string $expr, array $context): mixed
    {
        // 따옴표 문자열 리터럴
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $expr, $matches)) {
            return $matches[1];
        }

        // 숫자 리터럴
        if (is_numeric($expr)) {
            return $expr + 0;
        }

        // Boolean/null 리터럴
        if ($expr === 'true') {
            return true;
        }
        if ($expr === 'false') {
            return false;
        }
        if ($expr === 'null') {
            return null;
        }

        // 경로 참조
        return $this->resolvePath($expr, $context);
    }

    /**
     * 느슨한 동등 비교를 수행합니다.
     *
     * seo_overrides에서 배열 값이 설정된 경우 다중 값 매칭을 지원합니다.
     * 예: _local.activeTab = ["info", "reviews", "qna"] 설정 시
     *     _local.activeTab === 'reviews' → true (in_array 매칭)
     *
     * @param  mixed  $a  좌측 값
     * @param  mixed  $b  우측 값
     * @return bool 동등 여부
     */
    private function looseEquals(mixed $a, mixed $b): bool
    {
        // seo_overrides 배열 다중 값 매칭: 한쪽이 배열이면 in_array 체크
        // SEO 렌더링에서 탭/아코디언 등 다중 조건을 동시에 true로 만들기 위한 기능
        if (is_array($a) && ! is_array($b)) {
            return in_array($b, $a, false);
        }
        if (is_array($b) && ! is_array($a)) {
            return in_array($a, $b, false);
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a == $b;
    }

    /**
     * 삼항 연산자를 평가합니다.
     *
     * `condition ? trueValue : falseValue` 형태의 삼항 표현식을 파싱하여 평가합니다.
     * `?.` (optional chaining)와 `?` (삼항)을 정확히 구분하며,
     * 중첩 삼항은 우측 결합(right-associative)으로 처리합니다.
     *
     * @param  string  $expr  표현식
     * @param  array  $context  데이터 컨텍스트
     * @return string|null 평가 결과 (삼항이 아니면 null)
     */
    private function evaluateTernary(string $expr, array $context): ?string
    {
        $len = strlen($expr);
        $depth = 0;       // () [] 깊이
        $inSingle = false; // 작은따옴표 문자열 내부
        $inDouble = false; // 큰따옴표 문자열 내부
        $questionPos = -1;

        // 1단계: 최외곽 삼항 `?` 위치 찾기 (?.은 건너뜀)
        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];

            // 따옴표 추적
            if ($char === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;

                continue;
            }
            if ($char === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;

                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }

            // 괄호/브래킷 깊이 추적
            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }

            // depth === 0일 때만 삼항 `?` 검사
            if ($depth === 0 && $char === '?') {
                // `?.` (optional chaining)이면 건너뛰기
                if ($i + 1 < $len && $expr[$i + 1] === '.') {
                    $i++; // `.` 건너뛰기

                    continue;
                }
                // `??` (null coalescing)이면 건너뛰기
                if ($i + 1 < $len && $expr[$i + 1] === '?') {
                    $i++; // 두 번째 `?` 건너뛰기

                    continue;
                }
                $questionPos = $i;
                break;
            }
        }

        if ($questionPos < 0) {
            return null; // 삼항 아님
        }

        // 2단계: `?` 이후에서 매칭되는 `:` 찾기
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $colonPos = -1;

        for ($i = $questionPos + 1; $i < $len; $i++) {
            $char = $expr[$i];

            if ($char === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;

                continue;
            }
            if ($char === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;

                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }

            // depth === 0이고 삼항의 `:` (`::`가 아닌)
            if ($depth === 0 && $char === ':') {
                $colonPos = $i;
                break;
            }
        }

        if ($colonPos < 0) {
            return null; // `:` 없음 → 삼항 아님
        }

        // 3단계: 조건 / true 분기 / false 분기 분리 후 평가
        $condition = trim(substr($expr, 0, $questionPos));
        $trueExpr = trim(substr($expr, $questionPos + 1, $colonPos - $questionPos - 1));
        $falseExpr = trim(substr($expr, $colonPos + 1)); // 우측 결합: 나머지 전체가 false 분기

        $condResult = $this->evaluateExpression($condition, $context);
        $isTruthy = $condResult !== '' && $condResult !== 'false' && $condResult !== '0' && $condResult !== 'null';

        return $isTruthy
            ? $this->evaluateExpression($trueExpr, $context)
            : $this->evaluateExpression($falseExpr, $context);
    }

    /**
     * 삼항 연산자를 평가하여 원본 값(raw)을 반환합니다.
     *
     * resolveToValue()에서 호출되며, 결과를 [value] 배열로 래핑하여
     * null 결과와 "삼항이 아님"을 구분합니다.
     *
     * @param  string  $expr  표현식
     * @param  array  $context  데이터 컨텍스트
     * @return array|null [value] (삼항이면) 또는 null (삼항이 아니면)
     */
    private function evaluateTernaryRaw(string $expr, array $context): ?array
    {
        $len = strlen($expr);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $questionPos = -1;

        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];
            if ($char === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;

                continue;
            }
            if ($char === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;

                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }
            if ($depth === 0 && $char === '?') {
                if ($i + 1 < $len && ($expr[$i + 1] === '.' || $expr[$i + 1] === '?')) {
                    $i++;

                    continue;
                }
                $questionPos = $i;
                break;
            }
        }

        if ($questionPos < 0) {
            return null;
        }

        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $colonPos = -1;

        for ($i = $questionPos + 1; $i < $len; $i++) {
            $char = $expr[$i];
            if ($char === "'" && ! $inDouble) {
                $inSingle = ! $inSingle;

                continue;
            }
            if ($char === '"' && ! $inSingle) {
                $inDouble = ! $inDouble;

                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }
            if ($depth === 0 && $char === ':') {
                $colonPos = $i;
                break;
            }
        }

        if ($colonPos < 0) {
            return null;
        }

        $condition = trim(substr($expr, 0, $questionPos));
        $trueExpr = trim(substr($expr, $questionPos + 1, $colonPos - $questionPos - 1));
        $falseExpr = trim(substr($expr, $colonPos + 1));

        $condResult = $this->evaluateExpression($condition, $context);
        $isTruthy = $condResult !== '' && $condResult !== 'false' && $condResult !== '0' && $condResult !== 'null';

        return [$this->resolveToValue($isTruthy ? $trueExpr : $falseExpr, $context)];
    }

    /**
     * 브래킷 외부에서 구분자로 문자열을 분할합니다.
     *
     * [] 또는 () 내부의 구분자는 무시합니다.
     *
     * @param  string  $expr  표현식
     * @param  string  $delimiter  구분자 (예: '??', '||', '&&')
     * @return array 분할된 배열 (분할 불가 시 [$expr])
     */
    private function splitOutsideBrackets(string $expr, string $delimiter): array
    {
        $depth = 0;
        $len = strlen($expr);
        $delimLen = strlen($delimiter);

        for ($i = 0; $i <= $len - $delimLen; $i++) {
            $char = $expr[$i];
            if ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                $depth--;
            } elseif ($depth === 0 && substr($expr, $i, $delimLen) === $delimiter) {
                return [
                    substr($expr, 0, $i),
                    substr($expr, $i + $delimLen),
                ];
            }
        }

        return [$expr];
    }

    /**
     * dot notation + optional chaining + 배열 인덱스 + 브래킷 접근자로 값을 해석합니다.
     *
     * @param  string  $path  경로
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값 또는 null
     */
    private function resolvePath(string $path, array $context): mixed
    {
        // optional chaining (?.) → dot notation으로 변환
        $normalizedPath = str_replace('?.', '.', $path);

        // 동적 브래킷 접근자 감지: path.[expr].rest (단순 숫자 인덱스 제외)
        if (preg_match('/\[(?!\d+\])/', $normalizedPath)) {
            return $this->resolvePathWithBrackets($normalizedPath, $context);
        }

        // 배열 인덱스 처리: images[0] → images.0
        $normalizedPath = preg_replace('/\[(\d+)\]/', '.$1', $normalizedPath);

        // 1차: SEO 오버라이드 매칭 (배열 다중 값 매칭은 context 값보다 우선)
        // 예: _local.activeTab = ["info", "reviews", "qna"] → 탭 조건 모두 true
        $override = $this->matchSeoOverride($normalizedPath);
        if ($override !== null) {
            // 배열 다중 값 오버라이드: context 값보다 항상 우선 (모든 조건을 동시에 충족시키기 위함)
            if (is_array($override) && ! array_key_exists('*', $override)) {
                return $override;
            }

            // 와일드카드 오버라이드: context 값이 없을 때만 적용 (기존 동작 유지)
            $value = Arr::get($context, $normalizedPath);
            if ($value !== null) {
                return $value;
            }

            return $override;
        }

        // 2차: Arr::get()으로 직접 해석 시도
        $value = Arr::get($context, $normalizedPath);
        if ($value !== null) {
            return $value;
        }

        // 3차: 세그먼트별 순회하며 가상 프로퍼티 해석
        return $this->resolvePathWithVirtualProps($normalizedPath, $context);
    }

    /**
     * 세그먼트별로 경로를 순회하며 가상 프로퍼티를 해석합니다.
     *
     * PHP 배열/문자열에 존재하지 않지만 JavaScript에서 지원하는
     * 프로퍼티(length 등)를 부모 값의 타입에 따라 동적으로 해석합니다.
     *
     * @param  string  $normalizedPath  정규화된 경로 (dot notation)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값 또는 null
     */
    private function resolvePathWithVirtualProps(string $normalizedPath, array $context): mixed
    {
        $segments = explode('.', $normalizedPath);
        $current = $context;

        for ($i = 0; $i < count($segments); $i++) {
            $segment = $segments[$i];

            // 현재 값이 배열이고 키가 존재하면 진행
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            // 키가 존재하지 않으면 → 가상 프로퍼티 해석 시도
            $virtualValue = $this->resolveVirtualProperty($current, $segment);
            if ($virtualValue !== null) {
                // 가상 프로퍼티 뒤에 추가 세그먼트가 있으면 계속 순회
                if ($i < count($segments) - 1) {
                    $remainingPath = implode('.', array_slice($segments, $i + 1));

                    return is_array($virtualValue)
                        ? Arr::get($virtualValue, $remainingPath)
                        : null;
                }

                return $virtualValue;
            }

            return null;
        }

        return $current;
    }

    /**
     * 값의 타입에 따라 JavaScript 가상 프로퍼티를 해석합니다.
     *
     * @param  mixed  $value  부모 값
     * @param  string  $property  프로퍼티 이름
     * @return mixed 해석된 값 또는 null (미지원 프로퍼티)
     */
    private function resolveVirtualProperty(mixed $value, string $property): mixed
    {
        if (is_array($value)) {
            return match ($property) {
                'length' => count($value),
                default => null,
            };
        }

        if (is_string($value)) {
            return match ($property) {
                'length' => mb_strlen($value),
                default => null,
            };
        }

        return null;
    }

    /**
     * SEO 오버라이드에서 경로 매칭을 수행합니다.
     *
     * seo_overrides._local / seo_overrides._global 내의 와일드카드("*") 키를
     * 사용하여, 동적 브래킷 접근이나 미존재 경로에 대해 기본값을 반환합니다.
     *
     * 예: normalizedPath = "_local.collapsedReplies.5"
     *     오버라이드: {"_local": {"collapsedReplies": {"*": false}}}
     *     → "collapsedReplies.*" 와일드카드 매칭 → false 반환
     *
     * @param  string  $normalizedPath  정규화된 경로 (dot notation)
     * @return mixed 매칭된 오버라이드 값 또는 null (매칭 없음)
     */
    private function matchSeoOverride(string $normalizedPath): mixed
    {
        if (empty($this->seoOverrides)) {
            return null;
        }

        foreach (['_local', '_global'] as $scope) {
            $scopeOverrides = $this->seoOverrides[$scope] ?? [];
            if (empty($scopeOverrides) || ! str_starts_with($normalizedPath, $scope.'.')) {
                continue;
            }

            $relativePath = substr($normalizedPath, strlen($scope) + 1);

            foreach ($scopeOverrides as $overrideKey => $overrideValue) {
                // 정확한 매칭: _local.key → overrideKey = "key"
                if ($relativePath === $overrideKey) {
                    return $overrideValue;
                }

                // 와일드카드 매칭: _local.collapsedReplies.xxx → overrideKey = "collapsedReplies"
                // overrideValue가 배열이고 "*" 키가 있으면 와일드카드
                if (is_array($overrideValue) && array_key_exists('*', $overrideValue)) {
                    // prefix.* 직접 매칭 (resolvePathWithBrackets에서 호출)
                    if ($relativePath === $overrideKey.'.*') {
                        return $overrideValue['*'];
                    }
                    // prefix.dynamicKey 매칭 (resolvePath에서 호출)
                    if (str_starts_with($relativePath, $overrideKey.'.')) {
                        return $overrideValue['*'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * 브래킷 접근자가 포함된 경로를 해석합니다.
     *
     * 예: product.data.multi_currency.[preferredCurrency ?? 'KRW'].formatted
     *
     * @param  string  $path  경로 (optional chaining 이미 정규화됨)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값 또는 null
     */
    private function resolvePathWithBrackets(string $path, array $context): mixed
    {
        if (preg_match('/^(.+?)\[(.+?)\](.*)$/', $path, $matches)) {
            $prefix = $matches[1];
            $bracketExpr = trim($matches[2]);
            $suffix = ltrim($matches[3], '.');

            // 브래킷 내부 표현식을 키로 해석
            $key = $this->resolveBracketExpression($bracketExpr, $context);
            if ($key === null) {
                // SEO 오버라이드: 브래킷 키 해석 불가 시 prefix에 대한 와일드카드 매칭
                // 예: _local.collapsedReplies?.[$computed.xxx] → $computed 미존재 → prefix 와일드카드 확인
                $normalizedPrefix = rtrim(preg_replace('/\[(\d+)\]/', '.$1', str_replace('?.', '.', $prefix)), '.');
                $override = $this->matchSeoOverride($normalizedPrefix.'.*');
                if ($override !== null) {
                    return $override;
                }

                return null;
            }

            // 경로 재구성 후 재귀 해석 (prefix 끝 dot 제거 — path.[key] 패턴의 이중 dot 방지)
            $fullPath = rtrim($prefix, '.').'.'.$key;
            if ($suffix !== '') {
                $fullPath .= '.'.$suffix;
            }

            return $this->resolvePath($fullPath, $context);
        }

        // fallback: 숫자 인덱스만 변환 후 해석
        $normalizedPath = preg_replace('/\[(\d+)\]/', '.$1', $path);

        return Arr::get($context, $normalizedPath);
    }

    /**
     * 브래킷 내부 표현식을 키 문자열로 해석합니다.
     *
     * @param  string  $expr  브래킷 내부 표현식
     * @param  array  $context  데이터 컨텍스트
     * @return string|null 해석된 키 또는 null
     */
    private function resolveBracketExpression(string $expr, array $context): ?string
    {
        // 문자열 리터럴: 'KRW' 또는 "KRW"
        if (preg_match('/^[\'"](.+?)[\'"]$/', $expr, $m)) {
            return $m[1];
        }

        // 숫자 인덱스
        if (is_numeric($expr)) {
            return $expr;
        }

        // null coalescing: path ?? 'fallback'
        if (str_contains($expr, '??')) {
            $parts = array_map('trim', explode('??', $expr, 2));
            $value = $this->resolvePath($parts[0], $context);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }

            // fallback (따옴표 제거)
            return trim($parts[1], " '\"");
        }

        // 경로 참조
        $value = $this->resolvePath($expr, $context);

        return $value !== null ? (string) $value : null;
    }

    // ====================================================================
    // 메서드/함수 호출 평가 시스템
    // JavaScript 표현식의 메서드 호출을 PHP로 해석합니다.
    // ====================================================================

    /**
     * 표현식을 raw 값(배열/숫자/문자열 등)으로 해석합니다.
     *
     * evaluateExpression()과 달리 string이 아닌 원본 타입을 반환합니다.
     * 메서드 호출의 인수 해석이나 체이닝에 사용됩니다.
     *
     * @param  string  $expr  표현식
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값
     */
    private function resolveToValue(string $expr, array $context): mixed
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        // 문자열 리터럴
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $expr, $m)) {
            return $m[1];
        }

        // 숫자 리터럴
        if (is_numeric($expr)) {
            return $expr + 0;
        }

        // Boolean/null/빈 배열 리터럴
        if ($expr === 'true') {
            return true;
        }
        if ($expr === 'false') {
            return false;
        }
        if ($expr === 'null') {
            return null;
        }
        if ($expr === '[]') {
            return [];
        }

        // 배열 리터럴: ['a','b','c'] 또는 [...arr, item] → PHP 배열
        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            $parsed = $this->parseArrayLiteral($expr, $context);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // 객체 리터럴: {key: value, ...obj} → PHP 연상 배열
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}')) {
            $parsed = $this->parseObjectLiteral($expr, $context);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // 외부 괄호 제거: (expr) → expr
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')')) {
            $inner = substr($expr, 1, -1);
            $depth = 0;
            $valid = true;
            for ($i = 0; $i < strlen($inner); $i++) {
                if ($inner[$i] === '(') {
                    $depth++;
                } elseif ($inner[$i] === ')') {
                    $depth--;
                    if ($depth < 0) {
                        $valid = false;
                        break;
                    }
                }
            }
            if ($valid && $depth === 0) {
                return $this->resolveToValue($inner, $context);
            }
        }

        // 메서드/함수 호출 ($t, $localized 등 $ 접두사 함수 포함)
        if (preg_match('/[\w$]+\s*\(/', $expr)) {
            if (str_ends_with($expr, ')')) {
                $result = $this->evaluateCallExpression($expr, $context);
                if ($result !== null) {
                    return $result;
                }
            }

            // 메서드 호출 + 후속 프로퍼티: expr().prop
            $lastParen = strrpos($expr, ')');
            if ($lastParen !== false && $lastParen < strlen($expr) - 1) {
                $methodPart = substr($expr, 0, $lastParen + 1);
                $propPart = ltrim(substr($expr, $lastParen + 1), '.');

                $methodResult = $this->evaluateCallExpression($methodPart, $context);
                if ($methodResult !== null && $propPart !== '') {
                    $current = $methodResult;
                    foreach (explode('.', str_replace('?.', '.', $propPart)) as $seg) {
                        if ($seg === '') {
                            continue;
                        }
                        $virtual = $this->resolveVirtualProperty($current, $seg);
                        if ($virtual !== null) {
                            $current = $virtual;

                            continue;
                        }
                        if (is_array($current) && array_key_exists($seg, $current)) {
                            $current = $current[$seg];

                            continue;
                        }

                        return null;
                    }

                    return $current;
                }
            }
        }

        // 삼항 연산자: condition ? trueExpr : falseExpr
        $ternaryResult = $this->evaluateTernaryRaw($expr, $context);
        if ($ternaryResult !== null) {
            return $ternaryResult[0]; // 배열로 래핑하여 null 결과도 구분
        }

        // null coalescing
        if (str_contains($expr, '??')) {
            $parts = $this->splitOutsideBrackets($expr, '??');
            if (count($parts) === 2) {
                $value = $this->resolveToValue(trim($parts[0]), $context);
                if ($value !== null && $value !== '') {
                    return $value;
                }

                return $this->resolveToValue(trim($parts[1]), $context);
            }
        }

        // 경로 해석 (raw 값 반환)
        return $this->resolvePath($expr, $context);
    }

    /**
     * 배열 리터럴 문자열을 PHP 배열로 파싱합니다.
     *
     * JavaScript 배열 리터럴 ['a', 'b', 123, true] 등을 지원합니다.
     * 스프레드 연산자 [...arr, item]도 지원합니다.
     * context가 제공되면 스프레드 및 표현식 요소를 평가합니다.
     *
     * @param  string  $expr  배열 리터럴 문자열 (예: "['gallery','card']")
     * @param  array|null  $context  데이터 컨텍스트 (스프레드/표현식 평가용)
     * @return array|null 파싱된 배열 또는 null (파싱 불가 시)
     */
    private function parseArrayLiteral(string $expr, ?array $context = null): ?array
    {
        $inner = trim(substr($expr, 1, -1));
        if ($inner === '') {
            return [];
        }

        $elements = $this->splitArgs($inner);
        $result = [];

        foreach ($elements as $element) {
            $element = trim($element);

            // 배열 스프레드: ...arr
            if (str_starts_with($element, '...')) {
                if ($context === null) {
                    return null;
                }
                $spreadExpr = trim(substr($element, 3));
                $spreadValue = $this->resolveToValue($spreadExpr, $context);
                if (is_array($spreadValue)) {
                    foreach ($spreadValue as $v) {
                        $result[] = $v;
                    }
                }

                continue;
            }

            // 문자열 리터럴
            if (preg_match('/^[\'"](.*)[\'"]\s*$/', $element, $m)) {
                $result[] = $m[1];

                continue;
            }

            // 숫자 리터럴
            if (is_numeric($element)) {
                $result[] = $element + 0;

                continue;
            }

            // boolean/null
            if ($element === 'true') {
                $result[] = true;

                continue;
            }
            if ($element === 'false') {
                $result[] = false;

                continue;
            }
            if ($element === 'null') {
                $result[] = null;

                continue;
            }

            // context 있으면 표현식으로 평가 시도
            if ($context !== null) {
                $resolved = $this->resolveToValue($element, $context);
                if ($resolved !== null) {
                    $result[] = $resolved;

                    continue;
                }
            }

            // 리터럴이 아닌 요소가 있고 context도 없으면 배열 리터럴로 취급하지 않음
            return null;
        }

        return $result;
    }

    /**
     * 객체 리터럴 문자열을 PHP 연상 배열로 파싱합니다.
     *
     * JavaScript 객체 리터럴 { key: value, key2: 'text' } 등을 지원합니다.
     * 스프레드 연산자 { ...obj, key: value }도 지원합니다.
     * 동적 키 { [expr]: value }도 지원합니다.
     *
     * @param  string  $expr  객체 리터럴 문자열 (예: "{ status: 'active', ...defaults }")
     * @param  array  $context  데이터 컨텍스트
     * @return array|null 파싱된 연상 배열 또는 null (파싱 불가 시)
     */
    private function parseObjectLiteral(string $expr, array $context): ?array
    {
        $inner = trim(substr($expr, 1, -1));
        if ($inner === '') {
            return [];
        }

        $entries = $this->splitArgs($inner);
        $result = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            // 스프레드: ...obj
            if (str_starts_with($entry, '...')) {
                $spreadExpr = trim(substr($entry, 3));
                $spreadValue = $this->resolveToValue($spreadExpr, $context);
                if (is_array($spreadValue)) {
                    foreach ($spreadValue as $k => $v) {
                        $result[$k] = $v;
                    }
                }

                continue;
            }

            // 동적 키: [expr]: value
            if (str_starts_with($entry, '[')) {
                $closeBracket = $this->findMatchingBracket($entry, 0);
                if ($closeBracket !== false) {
                    $keyExpr = trim(substr($entry, 1, $closeBracket - 1));
                    $rest = trim(substr($entry, $closeBracket + 1));

                    // ]: value → value 부분 추출
                    if (str_starts_with($rest, ':')) {
                        $valueExpr = trim(substr($rest, 1));
                        $key = $this->resolveToValue($keyExpr, $context);
                        if ($key === null || $key === '') {
                            continue;
                        }
                        $value = $this->resolveObjectPropertyValue($valueExpr, $context);
                        $result[(string) $key] = $value;

                        continue;
                    }
                }

                return null;
            }

            // 일반 key: value
            $colonPos = $this->findColonInEntry($entry);
            if ($colonPos === false) {
                // shorthand property: { key } → key: key 형태
                $value = $this->resolveToValue($entry, $context);
                $result[$entry] = $value;

                continue;
            }

            $key = trim(substr($entry, 0, $colonPos));
            $valueExpr = trim(substr($entry, $colonPos + 1));

            // 따옴표로 감싸인 키
            if (preg_match('/^[\'"](.+)[\'"]$/', $key, $m)) {
                $key = $m[1];
            }

            $value = $this->resolveObjectPropertyValue($valueExpr, $context);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 객체 리터럴 프로퍼티 값을 해석합니다.
     *
     * @param  string  $valueExpr  값 표현식
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값
     */
    private function resolveObjectPropertyValue(string $valueExpr, array $context): mixed
    {
        // 문자열 리터럴
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $valueExpr, $m)) {
            return $m[1];
        }

        // 숫자 리터럴
        if (is_numeric($valueExpr)) {
            return $valueExpr + 0;
        }

        // boolean/null
        if ($valueExpr === 'true') {
            return true;
        }
        if ($valueExpr === 'false') {
            return false;
        }
        if ($valueExpr === 'null') {
            return null;
        }

        // 배열/객체 리터럴
        if (str_starts_with($valueExpr, '[') && str_ends_with($valueExpr, ']')) {
            $parsed = $this->parseArrayLiteral($valueExpr, $context);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        if (str_starts_with($valueExpr, '{') && str_ends_with($valueExpr, '}')) {
            $parsed = $this->parseObjectLiteral($valueExpr, $context);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // 표현식으로 평가
        return $this->resolveToValue($valueExpr, $context);
    }

    /**
     * 객체 리터럴 엔트리에서 key: value 구분용 콜론 위치를 찾습니다.
     *
     * 중첩 구조 내부의 콜론(삼항 연산자의 : 등)은 무시합니다.
     *
     * @param  string  $entry  단일 엔트리 문자열
     * @return int|false 콜론 위치 또는 false
     */
    private function findColonInEntry(string $entry): int|false
    {
        $depth = 0;
        $inQuote = null;

        for ($i = 0; $i < strlen($entry); $i++) {
            $char = $entry[$i];

            if ($inQuote !== null) {
                if ($char === $inQuote && ($i === 0 || $entry[$i - 1] !== '\\')) {
                    $inQuote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inQuote = $char;

                continue;
            }

            if (in_array($char, ['(', '[', '{'])) {
                $depth++;
            }
            if (in_array($char, [')', ']', '}'])) {
                $depth--;
            }

            if ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return false;
    }

    /**
     * 문자열에서 매칭되는 닫는 브래킷 위치를 찾습니다.
     *
     * @param  string  $str  문자열
     * @param  int  $openPos  여는 브래킷 위치
     * @return int|false 닫는 브래킷 위치 또는 false
     */
    private function findMatchingBracket(string $str, int $openPos): int|false
    {
        $open = $str[$openPos];
        $close = match ($open) {
            '[' => ']',
            '(' => ')',
            '{' => '}',
            default => null,
        };

        if ($close === null) {
            return false;
        }

        $depth = 0;
        $inQuote = null;

        for ($i = $openPos; $i < strlen($str); $i++) {
            $char = $str[$i];

            if ($inQuote !== null) {
                if ($char === $inQuote && ($i === 0 || $str[$i - 1] !== '\\')) {
                    $inQuote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inQuote = $char;

                continue;
            }

            if ($char === $open) {
                $depth++;
            }
            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * 메서드/함수 호출 표현식을 평가합니다.
     *
     * @param  string  $expr  표현식 (반드시 )로 끝나야 함)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가 결과 또는 null (해석 불가)
     */
    private function evaluateCallExpression(string $expr, array $context): mixed
    {
        $call = $this->parseCallExpression($expr);
        if ($call === null) {
            return null;
        }

        return match ($call['type']) {
            'static' => $this->evaluateStaticCall($call['class'], $call['method'], $call['args'], $context),
            'global' => $this->evaluateGlobalFunction($call['function'], $call['args'], $context),
            'instance' => $this->evaluateInstanceCall($call['object'], $call['method'], $call['args'], $context),
            default => null,
        };
    }

    /**
     * 메서드 호출 구문을 파싱합니다.
     *
     * 지원 형태:
     * - 정적 메서드: Object.values(expr), Math.min(a, b)
     * - 전역 함수: Number(expr), String(expr)
     * - 인스턴스 메서드: expr.method(args)
     *
     * @param  string  $expr  표현식
     * @return array|null 파싱 결과 또는 null
     */
    private function parseCallExpression(string $expr): ?array
    {
        $len = strlen($expr);
        if ($len === 0 || $expr[$len - 1] !== ')') {
            return null;
        }

        // 매칭되는 ( 찾기
        $depth = 0;
        $openPos = -1;
        for ($i = $len - 1; $i >= 0; $i--) {
            if ($expr[$i] === ')') {
                $depth++;
            } elseif ($expr[$i] === '(') {
                $depth--;
                if ($depth === 0) {
                    $openPos = $i;
                    break;
                }
            }
        }

        if ($openPos < 0) {
            return null;
        }

        $beforeParen = substr($expr, 0, $openPos);
        $argsStr = substr($expr, $openPos + 1, $len - $openPos - 2);

        // 정적 메서드: Object.values, Math.min, Array.isArray, JSON.stringify 등
        if (preg_match('/^(Object|Math|Array|JSON|Date|Number)\.([\w]+)$/', $beforeParen, $m)) {
            return ['type' => 'static', 'class' => $m[1], 'method' => $m[2], 'args' => $argsStr];
        }

        // 전역 함수: Number(), String(), parseInt(), $t(), $localized() 등
        if (preg_match('/^(Number|String|Boolean|parseInt|parseFloat|isNaN|isFinite|encodeURIComponent|decodeURIComponent|\$t|\$localized)$/', $beforeParen)) {
            return ['type' => 'global', 'function' => $beforeParen, 'args' => $argsStr];
        }

        // 인스턴스 메서드: expr.method()
        if (preg_match('/^(.+)\.([\w]+)$/', $beforeParen, $m)) {
            return ['type' => 'instance', 'object' => $m[1], 'method' => $m[2], 'args' => $argsStr];
        }

        return null;
    }

    /**
     * 메서드 인수 문자열을 쉼표로 분할합니다.
     *
     * 따옴표, 괄호, 브래킷 내부의 쉼표는 무시합니다.
     *
     * @param  string  $argsStr  인수 문자열
     * @return array 분할된 인수 문자열 배열
     */
    private function splitArgs(string $argsStr): array
    {
        $argsStr = trim($argsStr);
        if ($argsStr === '') {
            return [];
        }

        $args = [];
        $current = '';
        $depth = 0;
        $inQuote = null;

        for ($i = 0; $i < strlen($argsStr); $i++) {
            $char = $argsStr[$i];

            if ($inQuote !== null) {
                $current .= $char;
                if ($char === $inQuote && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                    $inQuote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inQuote = $char;
                $current .= $char;

                continue;
            }

            if (in_array($char, ['(', '[', '{'])) {
                $depth++;
            }
            if (in_array($char, [')', ']', '}'])) {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }

    /**
     * 정적 메서드를 평가합니다.
     *
     * @param  string  $class  클래스명 (Object, Math, Array, JSON, Date, Number)
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가 결과 또는 null
     */
    private function evaluateStaticCall(string $class, string $method, string $argsStr, array $context): mixed
    {
        $args = $this->splitArgs($argsStr);
        $resolvedArgs = array_map(fn ($a) => $this->resolveToValue($a, $context), $args);

        return match ($class) {
            'Object' => match ($method) {
                'keys' => is_array($resolvedArgs[0] ?? null) ? array_keys($resolvedArgs[0]) : [],
                'values' => is_array($resolvedArgs[0] ?? null) ? array_values($resolvedArgs[0]) : [],
                'entries' => is_array($resolvedArgs[0] ?? null)
                    ? array_map(fn ($k, $v) => [$k, $v], array_keys($resolvedArgs[0]), array_values($resolvedArgs[0]))
                    : [],
                'assign' => $this->objectAssign($resolvedArgs),
                default => null,
            },
            'Math' => match ($method) {
                'min' => min(...array_filter($resolvedArgs, 'is_numeric')),
                'max' => max(...array_filter($resolvedArgs, 'is_numeric')),
                'floor' => is_numeric($resolvedArgs[0] ?? null) ? (int) floor((float) $resolvedArgs[0]) : null,
                'ceil' => is_numeric($resolvedArgs[0] ?? null) ? (int) ceil((float) $resolvedArgs[0]) : null,
                'round' => is_numeric($resolvedArgs[0] ?? null) ? round((float) $resolvedArgs[0], (int) ($resolvedArgs[1] ?? 0)) : null,
                'abs' => is_numeric($resolvedArgs[0] ?? null) ? abs($resolvedArgs[0]) : null,
                'random' => mt_rand() / mt_getrandmax(),
                default => null,
            },
            'Array' => match ($method) {
                'isArray' => is_array($resolvedArgs[0] ?? null),
                'from' => is_array($resolvedArgs[0] ?? null) ? array_values($resolvedArgs[0]) : [],
                default => null,
            },
            'JSON' => match ($method) {
                'stringify' => json_encode($resolvedArgs[0] ?? null, JSON_UNESCAPED_UNICODE),
                'parse' => is_string($resolvedArgs[0] ?? null) ? json_decode($resolvedArgs[0], true) : null,
                default => null,
            },
            'Number' => match ($method) {
                'isNaN' => ! is_numeric($resolvedArgs[0] ?? null),
                'isFinite' => is_numeric($resolvedArgs[0] ?? null) && is_finite((float) $resolvedArgs[0]),
                'parseInt' => is_numeric($resolvedArgs[0] ?? null) ? (int) $resolvedArgs[0] : null,
                'parseFloat' => is_numeric($resolvedArgs[0] ?? null) ? (float) $resolvedArgs[0] : null,
                default => null,
            },
            default => null,
        };
    }

    /**
     * Object.assign() 구현
     *
     * @param  array  $resolvedArgs  해석된 인수 배열
     * @return array 병합된 객체
     */
    private function objectAssign(array $resolvedArgs): array
    {
        $result = [];
        foreach ($resolvedArgs as $arg) {
            if (is_array($arg)) {
                $result = array_merge($result, $arg);
            }
        }

        return $result;
    }

    /**
     * 전역 함수를 평가합니다.
     *
     * @param  string  $function  함수명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가 결과
     */
    private function evaluateGlobalFunction(string $function, string $argsStr, array $context): mixed
    {
        $args = $this->splitArgs($argsStr);
        $value = ! empty($args) ? $this->resolveToValue($args[0], $context) : null;

        return match ($function) {
            'Number' => is_numeric($value) ? $value + 0 : (is_bool($value) ? ($value ? 1 : 0) : 0),
            'String' => $value !== null ? (string) $value : '',
            'Boolean' => ! empty($value) && $value !== 'false' && $value !== '0',
            'parseInt' => is_numeric($value) ? (int) $value : null,
            'parseFloat' => is_numeric($value) ? (float) $value : null,
            'isNaN' => ! is_numeric($value),
            'isFinite' => is_numeric($value) && is_finite((float) $value),
            'encodeURIComponent' => is_string($value) ? rawurlencode($value) : '',
            'decodeURIComponent' => is_string($value) ? rawurldecode($value) : '',
            '$t' => $this->evaluateTranslationFunction($argsStr, $context),
            '$localized' => $this->evaluateLocalizedFunction($value, $context),
            default => null,
        };
    }

    /**
     * $t() 함수 호출을 평가합니다.
     *
     * 프론트엔드의 `$t('key')` 구문과 호환됩니다.
     * 내부적으로 기존 resolveTranslation()을 재활용합니다.
     *
     * @param  string  $argsStr  인수 문자열 (예: "'shop.product.sold_out'")
     * @param  array  $context  데이터 컨텍스트
     * @return string 번역된 문자열
     */
    private function evaluateTranslationFunction(string $argsStr, array $context): string
    {
        $args = $this->splitArgs($argsStr);
        if (empty($args)) {
            return '';
        }

        // 첫 번째 인수: 번역 키 (문자열 리터럴 또는 표현식)
        $keyArg = trim($args[0]);
        if (preg_match('/^[\'"](.+)[\'"]\s*$/', $keyArg, $m)) {
            $key = $m[1];
        } else {
            // 표현식으로 평가하여 키 획득
            $key = $this->evaluateExpression($keyArg, $context);
        }

        if ($key === '') {
            return '';
        }

        return $this->resolveTranslation('$t:'.$key, $context);
    }

    /**
     * $localized() 함수 호출을 평가합니다.
     *
     * 다국어 객체 {ko: "한국어", en: "English"} 에서 현재 로케일에 해당하는 값을 반환합니다.
     * fallback 순서: 현재 로케일 → 'ko' → 첫 번째 값
     *
     * @param  mixed  $value  다국어 객체 (배열)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 현재 로케일의 값
     */
    private function evaluateLocalizedFunction(mixed $value, array $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $locale = app()->getLocale();

        return $value[$locale] ?? $value['ko'] ?? ($value ? reset($value) : '');
    }

    /**
     * 인스턴스 메서드를 평가합니다.
     *
     * @param  string  $objectExpr  객체 표현식
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가 결과 또는 null
     */
    private function evaluateInstanceCall(string $objectExpr, string $method, string $argsStr, array $context): mixed
    {
        $value = $this->resolveToValue($objectExpr, $context);

        if (is_array($value)) {
            return $this->callArrayMethod($value, $method, $argsStr, $context);
        }

        if (is_string($value)) {
            return $this->callStringMethod($value, $method, $argsStr, $context);
        }

        if (is_numeric($value)) {
            return $this->callNumberMethod($value, $method, $argsStr, $context);
        }

        return null;
    }

    /**
     * 배열 메서드를 호출합니다.
     *
     * @param  array  $value  배열
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 결과
     */
    private function callArrayMethod(array $value, string $method, string $argsStr, array $context): mixed
    {
        $args = $this->splitArgs($argsStr);

        return match ($method) {
            'join' => implode(
                ! empty($args) ? $this->resolveToValue($args[0], $context) ?? ',' : ',',
                array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? ''), $value)
            ),
            'slice' => $this->arraySlice(
                $value,
                (int) ($this->resolveToValue($args[0] ?? '0', $context) ?? 0),
                isset($args[1]) ? (int) $this->resolveToValue($args[1], $context) : null
            ),
            'includes' => in_array($this->resolveToValue($args[0] ?? 'null', $context), $value),
            'indexOf' => ($idx = array_search($this->resolveToValue($args[0] ?? 'null', $context), $value)) !== false ? $idx : -1,
            'flat' => $this->arrayFlat($value, isset($args[0]) ? (int) $this->resolveToValue($args[0], $context) : 1),
            'reverse' => array_reverse($value),
            'pop' => ! empty($value) ? end($value) : null,
            'shift' => ! empty($value) ? reset($value) : null,
            'concat' => array_merge($value, ...array_map(
                fn ($a) => (array) ($this->resolveToValue($a, $context) ?? []),
                $args
            )),
            'at' => $this->arrayAt($value, (int) ($this->resolveToValue($args[0] ?? '0', $context) ?? 0)),
            'fill' => array_fill(0, count($value), $this->resolveToValue($args[0] ?? 'null', $context)),
            'toString' => implode(',', $value),
            // 콜백 메서드
            'map' => $this->callArrayCallbackMethod($value, 'map', $argsStr, $context),
            'filter' => $this->callArrayCallbackMethod($value, 'filter', $argsStr, $context),
            'find' => $this->callArrayCallbackMethod($value, 'find', $argsStr, $context),
            'findIndex' => $this->callArrayCallbackMethod($value, 'findIndex', $argsStr, $context),
            'some' => $this->callArrayCallbackMethod($value, 'some', $argsStr, $context),
            'every' => $this->callArrayCallbackMethod($value, 'every', $argsStr, $context),
            'flatMap' => $this->callArrayCallbackMethod($value, 'flatMap', $argsStr, $context),
            'reduce' => $this->callArrayCallbackMethod($value, 'reduce', $argsStr, $context),
            'sort' => $this->callArrayCallbackMethod($value, 'sort', $argsStr, $context),
            default => null,
        };
    }

    /**
     * 문자열 메서드를 호출합니다.
     *
     * @param  string  $value  문자열
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 결과
     */
    private function callStringMethod(string $value, string $method, string $argsStr, array $context): mixed
    {
        $args = $this->splitArgs($argsStr);
        $resolvedArgs = array_map(fn ($a) => $this->resolveToValue($a, $context), $args);

        return match ($method) {
            'split' => explode((string) ($resolvedArgs[0] ?? ''), $value),
            'join' => $value,  // 문자열에 join은 자기 자신 반환
            'trim' => trim($value),
            'trimStart', 'trimLeft' => ltrim($value),
            'trimEnd', 'trimRight' => rtrim($value),
            'toLowerCase', 'toLocaleLowerCase' => mb_strtolower($value),
            'toUpperCase', 'toLocaleUpperCase' => mb_strtoupper($value),
            'substring' => mb_substr(
                $value,
                (int) ($resolvedArgs[0] ?? 0),
                isset($resolvedArgs[1]) ? (int) $resolvedArgs[1] - (int) ($resolvedArgs[0] ?? 0) : null
            ),
            'substr' => mb_substr($value, (int) ($resolvedArgs[0] ?? 0), isset($resolvedArgs[1]) ? (int) $resolvedArgs[1] : null),
            'slice' => $this->stringSlice($value, (int) ($resolvedArgs[0] ?? 0), $resolvedArgs[1] ?? null),
            'includes' => str_contains($value, (string) ($resolvedArgs[0] ?? '')),
            'indexOf' => ($pos = mb_strpos($value, (string) ($resolvedArgs[0] ?? ''))) !== false ? $pos : -1,
            'lastIndexOf' => ($pos = mb_strrpos($value, (string) ($resolvedArgs[0] ?? ''))) !== false ? $pos : -1,
            'startsWith' => str_starts_with($value, (string) ($resolvedArgs[0] ?? '')),
            'endsWith' => str_ends_with($value, (string) ($resolvedArgs[0] ?? '')),
            'replace' => $this->stringReplace($value, $resolvedArgs),
            'replaceAll' => str_replace((string) ($resolvedArgs[0] ?? ''), (string) ($resolvedArgs[1] ?? ''), $value),
            'repeat' => str_repeat($value, max(0, (int) ($resolvedArgs[0] ?? 0))),
            'padStart' => str_pad($value, (int) ($resolvedArgs[0] ?? 0), (string) ($resolvedArgs[1] ?? ' '), STR_PAD_LEFT),
            'padEnd' => str_pad($value, (int) ($resolvedArgs[0] ?? 0), (string) ($resolvedArgs[1] ?? ' '), STR_PAD_RIGHT),
            'charAt' => mb_substr($value, (int) ($resolvedArgs[0] ?? 0), 1),
            'charCodeAt' => mb_ord(mb_substr($value, (int) ($resolvedArgs[0] ?? 0), 1)),
            'at' => $this->stringAt($value, (int) ($resolvedArgs[0] ?? 0)),
            'concat' => $value.implode('', array_map(fn ($a) => (string) ($a ?? ''), $resolvedArgs)),
            'toString' => $value,
            'match' => null, // 정규식 — SEO에서 불필요
            'search' => null,
            'normalize' => $value, // PHP normalizer 불필요
            'localeCompare' => null,
            'toLocaleString' => $value,
            default => null,
        };
    }

    /**
     * 숫자 메서드를 호출합니다.
     *
     * @param  int|float  $value  숫자
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 결과
     */
    private function callNumberMethod(int|float $value, string $method, string $argsStr, array $context): mixed
    {
        $args = $this->splitArgs($argsStr);
        $resolvedArgs = array_map(fn ($a) => $this->resolveToValue($a, $context), $args);

        return match ($method) {
            'toLocaleString' => number_format($value, is_float($value) && $value != (int) $value ? 2 : 0),
            'toFixed' => number_format((float) $value, (int) ($resolvedArgs[0] ?? 0), '.', ''),
            'toString' => isset($resolvedArgs[0]) && is_numeric($resolvedArgs[0])
                ? base_convert((string) (int) $value, 10, (int) $resolvedArgs[0])
                : (string) $value,
            'valueOf' => $value,
            default => null,
        };
    }

    /**
     * JavaScript String.prototype.slice 구현
     *
     * @param  string  $value  문자열
     * @param  int  $start  시작 인덱스 (음수 가능)
     * @param  mixed  $end  끝 인덱스 (null = 끝까지)
     * @return string 추출된 문자열
     */
    private function stringSlice(string $value, int $start, mixed $end): string
    {
        $len = mb_strlen($value);
        if ($start < 0) {
            $start = max($len + $start, 0);
        }
        if ($end === null) {
            return mb_substr($value, $start);
        }
        $end = (int) $end;
        if ($end < 0) {
            $end = $len + $end;
        }
        $length = $end - $start;

        return $length > 0 ? mb_substr($value, $start, $length) : '';
    }

    /**
     * JavaScript String.prototype.replace 구현 (최초 1회만)
     *
     * @param  string  $value  문자열
     * @param  array  $args  인수 [search, replacement]
     * @return string 치환된 문자열
     */
    private function stringReplace(string $value, array $args): string
    {
        $search = (string) ($args[0] ?? '');
        $replace = (string) ($args[1] ?? '');
        $pos = strpos($value, $search);
        if ($pos !== false) {
            return substr_replace($value, $replace, $pos, strlen($search));
        }

        return $value;
    }

    /**
     * JavaScript String.prototype.at 구현 (음수 인덱스 지원)
     *
     * @param  string  $value  문자열
     * @param  int  $index  인덱스
     * @return string 문자
     */
    private function stringAt(string $value, int $index): string
    {
        $len = mb_strlen($value);
        if ($index < 0) {
            $index = $len + $index;
        }
        if ($index < 0 || $index >= $len) {
            return '';
        }

        return mb_substr($value, $index, 1);
    }

    /**
     * JavaScript Array.prototype.at 구현 (음수 인덱스 지원)
     *
     * @param  array  $value  배열
     * @param  int  $index  인덱스
     * @return mixed 요소
     */
    private function arrayAt(array $value, int $index): mixed
    {
        $values = array_values($value);
        $len = count($values);
        if ($index < 0) {
            $index = $len + $index;
        }

        return ($index >= 0 && $index < $len) ? $values[$index] : null;
    }

    /**
     * JavaScript Array.prototype.slice 구현 (끝 인덱스 기반)
     *
     * @param  array  $value  배열
     * @param  int  $start  시작 인덱스 (음수 가능)
     * @param  int|null  $end  끝 인덱스 (null = 끝까지, 음수 가능)
     * @return array 추출된 배열
     */
    private function arraySlice(array $value, int $start, ?int $end): array
    {
        $values = array_values($value);
        $len = count($values);

        if ($start < 0) {
            $start = max($len + $start, 0);
        }
        if ($end === null) {
            return array_slice($values, $start);
        }
        if ($end < 0) {
            $end = $len + $end;
        }
        $length = $end - $start;

        return $length > 0 ? array_slice($values, $start, $length) : [];
    }

    /**
     * JavaScript Array.prototype.flat 구현
     *
     * @param  array  $value  배열
     * @param  int  $depth  깊이
     * @return array 평탄화된 배열
     */
    private function arrayFlat(array $value, int $depth = 1): array
    {
        $result = [];
        foreach ($value as $item) {
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, $this->arrayFlat($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * 콜백이 필요한 배열 메서드를 호출합니다.
     *
     * 단순 화살표 함수만 지원: item => item.prop, (item, idx) => condition
     *
     * @param  array  $value  배열
     * @param  string  $method  메서드명
     * @param  string  $argsStr  인수 문자열 (콜백 포함)
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 결과
     */
    private function callArrayCallbackMethod(array $value, string $method, string $argsStr, array $context): mixed
    {
        $allArgs = $this->splitArgs($argsStr);
        $callbackStr = $allArgs[0] ?? '';
        $callback = $this->parseSimpleCallback($callbackStr);

        // reduce의 초기값
        $initialValue = isset($allArgs[1]) ? $this->resolveToValue($allArgs[1], $context) : null;

        // 콜백 파싱 실패 시
        if ($callback === null) {
            // sort()는 콜백 없이도 동작
            if ($method === 'sort') {
                sort($value);

                return $value;
            }

            return $method === 'filter' ? $value : null;
        }

        $values = array_values($value);

        return match ($method) {
            'map' => array_values(array_map(
                fn ($item, $idx) => $this->evaluateCallbackBody($callback, $item, $idx, $context),
                $values,
                array_keys($values)
            )),
            'filter' => array_values(array_filter(
                $values,
                fn ($item, $idx) => $this->isTruthy($this->evaluateCallbackBody($callback, $item, $idx, $context)),
                ARRAY_FILTER_USE_BOTH
            )),
            'find' => $this->arrayFind($values, $callback, $context),
            'findIndex' => $this->arrayFindIndex($values, $callback, $context),
            'some' => $this->arraySome($values, $callback, $context),
            'every' => $this->arrayEvery($values, $callback, $context),
            'flatMap' => $this->arrayFlat(array_values(array_map(
                fn ($item, $idx) => $this->evaluateCallbackBody($callback, $item, $idx, $context),
                $values,
                array_keys($values)
            ))),
            'reduce' => $this->arrayReduce($values, $callback, $initialValue, $context),
            'sort' => $this->arraySort($values, $callback, $context),
            default => null,
        };
    }

    /**
     * 단순 화살표 함수를 파싱합니다.
     *
     * 지원: item => body, (item) => body, (item, idx) => body, ([a, b]) => body
     *
     * @param  string  $callbackStr  콜백 문자열
     * @return array|null ['params' => [...], 'body' => '...'] 또는 null
     */
    private function parseSimpleCallback(string $callbackStr): ?array
    {
        $callbackStr = trim($callbackStr);

        // (params) => body 또는 param => body
        if (! preg_match('/^(?:\(([^)]*)\)|(\w+))\s*=>\s*(.+)$/s', $callbackStr, $m)) {
            return null;
        }

        $paramsStr = $m[1] !== '' ? $m[1] : $m[2];
        $body = trim($m[3]);

        $params = array_map('trim', explode(',', $paramsStr));

        // 구조 분해 제거: [key, value] → key, value
        $params = array_map(fn ($p) => trim($p, '[] '), $params);

        return ['params' => $params, 'body' => $body];
    }

    /**
     * 콜백 본문을 평가합니다.
     *
     * @param  array  $callback  파싱된 콜백
     * @param  mixed  $item  현재 아이템
     * @param  int  $index  현재 인덱스
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가 결과
     */
    private function evaluateCallbackBody(array $callback, mixed $item, int $index, array $context): mixed
    {
        $localContext = $context;

        // 파라미터 바인딩
        $params = $callback['params'];
        if (isset($params[0]) && $params[0] !== '_') {
            $localContext[$params[0]] = $item;
        }
        if (isset($params[1]) && $params[1] !== '_') {
            $localContext[$params[1]] = $index;
        }

        return $this->resolveToValue($callback['body'], $localContext);
    }

    /**
     * 값의 truthiness를 판단합니다.
     *
     * @param  mixed  $value  값
     * @return bool truthy 여부
     */
    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return true;
    }

    /**
     * Array.prototype.find 구현
     */
    private function arrayFind(array $values, array $callback, array $context): mixed
    {
        foreach ($values as $idx => $item) {
            if ($this->isTruthy($this->evaluateCallbackBody($callback, $item, $idx, $context))) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Array.prototype.findIndex 구현
     */
    private function arrayFindIndex(array $values, array $callback, array $context): int
    {
        foreach ($values as $idx => $item) {
            if ($this->isTruthy($this->evaluateCallbackBody($callback, $item, $idx, $context))) {
                return $idx;
            }
        }

        return -1;
    }

    /**
     * Array.prototype.some 구현
     */
    private function arraySome(array $values, array $callback, array $context): bool
    {
        foreach ($values as $idx => $item) {
            if ($this->isTruthy($this->evaluateCallbackBody($callback, $item, $idx, $context))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Array.prototype.every 구현
     */
    private function arrayEvery(array $values, array $callback, array $context): bool
    {
        foreach ($values as $idx => $item) {
            if (! $this->isTruthy($this->evaluateCallbackBody($callback, $item, $idx, $context))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Array.prototype.reduce 구현
     */
    private function arrayReduce(array $values, array $callback, mixed $initialValue, array $context): mixed
    {
        $accumulator = $initialValue;
        foreach ($values as $idx => $item) {
            $localContext = $context;
            $params = $callback['params'];
            if (isset($params[0])) {
                $localContext[$params[0]] = $accumulator;
            }
            if (isset($params[1])) {
                $localContext[$params[1]] = $item;
            }
            if (isset($params[2])) {
                $localContext[$params[2]] = $idx;
            }
            $accumulator = $this->resolveToValue($callback['body'], $localContext);
        }

        return $accumulator;
    }

    /**
     * Array.prototype.sort (콜백 비교) 구현
     */
    private function arraySort(array $values, array $callback, array $context): array
    {
        usort($values, function ($a, $b) use ($callback, $context) {
            $localContext = $context;
            $params = $callback['params'];
            if (isset($params[0])) {
                $localContext[$params[0]] = $a;
            }
            if (isset($params[1])) {
                $localContext[$params[1]] = $b;
            }
            $result = $this->resolveToValue($callback['body'], $localContext);

            return is_numeric($result) ? (int) $result : 0;
        });

        return $values;
    }

    /**
     * 값을 문자열로 변환합니다.
     *
     * @param  mixed  $value  변환할 값
     * @return string 변환된 문자열
     */
    private function valueToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // 다국어 객체 자동 해석: {"ko": "값", "en": "value"} → 현재 로케일 값
            $locale = app()->getLocale();
            if (isset($value[$locale])) {
                return (string) $value[$locale];
            }
            // fallback 로케일
            $fallbackLocale = config('app.fallback_locale', 'en');
            if (isset($value[$fallbackLocale])) {
                return (string) $value[$fallbackLocale];
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return '';
    }
}
