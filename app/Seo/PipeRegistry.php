<?php

namespace App\Seo;

use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * SEO 렌더링용 파이프 레지스트리
 *
 * 프론트엔드 PipeRegistry.ts의 빌트인 파이프를 PHP로 미러링합니다.
 * `{{value | pipeName(arg)}}` 형태의 파이프 표현식을 서버 사이드에서 실행합니다.
 */
class PipeRegistry
{
    /**
     * 빌트인 파이프 정의
     *
     * @var array<string, callable>
     */
    private array $pipes = [];

    /**
     * 현재 로케일
     */
    private string $locale = 'ko';

    public function __construct()
    {
        $this->registerBuiltInPipes();
    }

    /**
     * 현재 로케일을 설정합니다.
     *
     * @param  string  $locale  로케일 코드
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * 파이프가 존재하는지 확인합니다.
     *
     * @param  string  $name  파이프 이름
     * @return bool 존재 여부
     */
    public function has(string $name): bool
    {
        return isset($this->pipes[$name]);
    }

    /**
     * 파이프를 실행합니다.
     *
     * @param  string  $name  파이프 이름
     * @param  mixed  $value  변환할 값
     * @param  array  $args  파이프 인자
     * @return mixed 변환된 값
     */
    public function execute(string $name, mixed $value, array $args = []): mixed
    {
        if (! isset($this->pipes[$name])) {
            return $value;
        }

        try {
            return ($this->pipes[$name])($value, ...$args);
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * 파이프 표현식을 파싱합니다.
     *
     * @param  string  $pipeExpr  파이프 표현식 (예: "date", "truncate(100)", "truncate(50, '...')")
     * @return array{name: string, args: array} 파이프 이름과 인자
     */
    public static function parsePipeExpression(string $pipeExpr): array
    {
        $trimmed = trim($pipeExpr);

        $parenIndex = strpos($trimmed, '(');
        if ($parenIndex === false) {
            return ['name' => $trimmed, 'args' => []];
        }

        $name = trim(substr($trimmed, 0, $parenIndex));
        $argsStr = substr($trimmed, $parenIndex + 1, -1); // ')' 제외

        if (trim($argsStr) === '') {
            return ['name' => $name, 'args' => []];
        }

        $args = self::parseArgs($argsStr);

        return ['name' => $name, 'args' => $args];
    }

    /**
     * 표현식에서 파이프를 분리합니다.
     *
     * `expression | pipe1 | pipe2(arg)` → ['expression', ['pipe1', 'pipe2(arg)']]
     * `||` (OR 연산자)는 파이프로 인식하지 않습니다.
     *
     * @param  string  $expr  전체 표현식
     * @return array{0: string, 1: string[]} [기본 표현식, 파이프 배열]
     */
    public static function splitPipes(string $expr): array
    {
        $parts = [];
        $current = '';
        $inString = null;
        $depth = 0;
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];
            $prevChar = $i > 0 ? $expr[$i - 1] : '';
            $nextChar = $i < $len - 1 ? $expr[$i + 1] : '';

            // 이스케이프 문자
            if ($prevChar === '\\') {
                $current .= $char;

                continue;
            }

            // 문자열 시작/종료
            if (($char === '"' || $char === "'") && $inString === null) {
                $inString = $char;
                $current .= $char;

                continue;
            }
            if ($char === $inString) {
                $inString = null;
                $current .= $char;

                continue;
            }

            // 문자열 내부
            if ($inString !== null) {
                $current .= $char;

                continue;
            }

            // 중첩 괄호
            if (in_array($char, ['(', '[', '{'])) {
                $depth++;
                $current .= $char;

                continue;
            }
            if (in_array($char, [')', ']', '}'])) {
                $depth--;
                $current .= $char;

                continue;
            }

            // 단일 | 감지 (|| 제외, 깊이 0)
            if ($char === '|' && $nextChar !== '|' && $prevChar !== '|' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        if (count($parts) === 0) {
            return [$expr, []];
        }

        return [$parts[0], array_slice($parts, 1)];
    }

    /**
     * 표현식에 파이프가 포함되어 있는지 확인합니다.
     *
     * @param  string  $expr  표현식
     * @return bool 파이프 포함 여부
     */
    public static function hasPipes(string $expr): bool
    {
        $inString = null;
        $depth = 0;
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];
            $prevChar = $i > 0 ? $expr[$i - 1] : '';
            $nextChar = $i < $len - 1 ? $expr[$i + 1] : '';

            if ($prevChar === '\\') {
                continue;
            }

            if (($char === '"' || $char === "'") && $inString === null) {
                $inString = $char;

                continue;
            }
            if ($char === $inString) {
                $inString = null;

                continue;
            }

            if ($inString !== null) {
                continue;
            }

            if (in_array($char, ['(', '[', '{'])) {
                $depth++;

                continue;
            }
            if (in_array($char, [')', ']', '}'])) {
                $depth--;

                continue;
            }

            if ($char === '|' && $nextChar !== '|' && $prevChar !== '|' && $depth === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 빌트인 파이프를 등록합니다.
     *
     * 프론트엔드 PipeRegistry.ts의 builtInPipes와 동일한 파이프를 PHP로 구현합니다.
     */
    private function registerBuiltInPipes(): void
    {
        // ============================================
        // 날짜 파이프
        // ============================================

        $this->pipes['date'] = function (mixed $value, string $format = 'YYYY-MM-DD'): string {
            $date = $this->toCarbon($value);
            if (! $date) {
                return '';
            }

            return $date->format($this->convertDateFormat($format));
        };

        $this->pipes['datetime'] = function (mixed $value, string $format = 'YYYY-MM-DD HH:mm'): string {
            $date = $this->toCarbon($value);
            if (! $date) {
                return '';
            }

            return $date->format($this->convertDateFormat($format));
        };

        $this->pipes['relativeTime'] = function (mixed $value, ?string $locale = null): string {
            $date = $this->toCarbon($value);
            if (! $date) {
                return '';
            }

            return $date->locale($locale ?? $this->locale)->diffForHumans();
        };

        // ============================================
        // 숫자 파이프
        // ============================================

        $this->pipes['number'] = function (mixed $value, ?int $decimals = null): string {
            if (! is_numeric($value)) {
                return (string) ($value ?? '');
            }
            $num = (float) $value;

            if ($decimals !== null) {
                return number_format($num, $decimals);
            }

            // 정수면 소수점 없이, 실수면 원래 소수점 유지
            if ($num == (int) $num) {
                return number_format($num, 0);
            }

            // 소수점 자릿수 자동 감지
            $parts = explode('.', (string) $num);
            $decimalPlaces = isset($parts[1]) ? strlen($parts[1]) : 0;

            return number_format($num, $decimalPlaces);
        };

        // ============================================
        // 문자열 파이프
        // ============================================

        $this->pipes['truncate'] = function (mixed $value, int $length = 100, string $suffix = '...'): string {
            if (! is_string($value)) {
                return (string) ($value ?? '');
            }
            if (mb_strlen($value) <= $length) {
                return $value;
            }

            return mb_substr($value, 0, $length).$suffix;
        };

        $this->pipes['uppercase'] = function (mixed $value): string {
            if (! is_string($value)) {
                return (string) ($value ?? '');
            }

            return mb_strtoupper($value);
        };

        $this->pipes['lowercase'] = function (mixed $value): string {
            if (! is_string($value)) {
                return (string) ($value ?? '');
            }

            return mb_strtolower($value);
        };

        $this->pipes['stripHtml'] = function (mixed $value): string {
            if (! is_string($value)) {
                return (string) ($value ?? '');
            }

            return strip_tags($value);
        };

        // ============================================
        // 기본값 파이프
        // ============================================

        $this->pipes['default'] = function (mixed $value, mixed $defaultValue = ''): mixed {
            if ($value === null || $value === '') {
                return $defaultValue;
            }

            return $value;
        };

        $this->pipes['fallback'] = function (mixed $value, mixed $fallbackValue = null): mixed {
            return $value ?? $fallbackValue;
        };

        // ============================================
        // 배열 파이프
        // ============================================

        $this->pipes['first'] = function (mixed $value): mixed {
            if (! is_array($value) || empty($value)) {
                return null;
            }

            return reset($value);
        };

        $this->pipes['last'] = function (mixed $value): mixed {
            if (! is_array($value) || empty($value)) {
                return null;
            }

            return end($value);
        };

        $this->pipes['join'] = function (mixed $value, string $separator = ', '): string {
            if (! is_array($value)) {
                return '';
            }

            return implode($separator, $value);
        };

        $this->pipes['length'] = function (mixed $value): int {
            if (is_array($value)) {
                return count($value);
            }
            if (is_string($value)) {
                return mb_strlen($value);
            }

            return 0;
        };

        $this->pipes['filterBy'] = function (mixed $value, mixed $allowList = [], ?string $field = null): array {
            if (! is_array($value)) {
                return [];
            }
            if (! is_array($allowList)) {
                return $value;
            }

            return array_values(array_filter($value, function ($item) use ($allowList, $field) {
                $itemValue = $field !== null ? Arr::get($item, $field) : $item;

                return in_array($itemValue, $allowList);
            }));
        };

        // ============================================
        // 객체 파이프
        // ============================================

        $this->pipes['keys'] = function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_keys($value);
        };

        $this->pipes['values'] = function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values($value);
        };

        $this->pipes['json'] = function (mixed $value, ?int $indent = null): string {
            $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if ($indent !== null) {
                $options |= JSON_PRETTY_PRINT;
            }

            return json_encode($value, $options) ?: '';
        };

        // ============================================
        // 다국어 파이프
        // ============================================

        $this->pipes['localized'] = function (mixed $value, ?string $locale = null): string {
            if ($value === null) {
                return '';
            }
            if (is_string($value)) {
                return $value;
            }
            if (! is_array($value)) {
                return (string) $value;
            }

            $targetLocale = $locale ?? $this->locale;

            return $value[$targetLocale] ?? $value['ko'] ?? $value['en'] ?? (string) (reset($value) ?: '');
        };
    }

    /**
     * 값을 Carbon 인스턴스로 변환합니다.
     *
     * @param  mixed  $value  변환할 값
     * @return Carbon|null Carbon 인스턴스 또는 null
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value);
            }
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value);
            }
            if (is_string($value)) {
                return Carbon::parse($value);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * JS 날짜 포맷을 PHP 날짜 포맷으로 변환합니다.
     *
     * @param  string  $format  JS 포맷 (예: YYYY-MM-DD HH:mm:ss)
     * @return string PHP 포맷 (예: Y-m-d H:i:s)
     */
    private function convertDateFormat(string $format): string
    {
        return str_replace(
            ['YYYY', 'YY', 'MM', 'DD', 'HH', 'mm', 'ss'],
            ['Y', 'y', 'm', 'd', 'H', 'i', 's'],
            $format
        );
    }

    /**
     * 인자 문자열을 파싱합니다.
     *
     * @param  string  $argsStr  인자 문자열 (예: "100, '...'")
     * @return array 파싱된 인자 배열
     */
    private static function parseArgs(string $argsStr): array
    {
        $args = [];
        $current = '';
        $inString = null;
        $depth = 0;

        for ($i = 0; $i < strlen($argsStr); $i++) {
            $char = $argsStr[$i];
            $prevChar = $i > 0 ? $argsStr[$i - 1] : '';

            // 이스케이프 문자
            if ($prevChar === '\\') {
                $current .= $char;

                continue;
            }

            // 문자열 시작/종료
            if (($char === '"' || $char === "'") && $inString === null) {
                $inString = $char;

                continue;
            }
            if ($char === $inString) {
                $inString = null;

                continue;
            }

            // 문자열 내부
            if ($inString !== null) {
                $current .= $char;

                continue;
            }

            // 중첩 괄호
            if (in_array($char, ['(', '[', '{'])) {
                $depth++;
                $current .= $char;

                continue;
            }
            if (in_array($char, [')', ']', '}'])) {
                $depth--;
                $current .= $char;

                continue;
            }

            // 쉼표는 인자 구분자 (깊이 0)
            if ($char === ',' && $depth === 0) {
                $args[] = self::parseArgValue(trim($current));
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $args[] = self::parseArgValue(trim($current));
        }

        return $args;
    }

    /**
     * 인자 값을 적절한 타입으로 변환합니다.
     *
     * @param  string  $value  인자 값 문자열
     * @return mixed 변환된 값
     */
    private static function parseArgValue(string $value): mixed
    {
        if ($value === 'null') {
            return null;
        }
        if ($value === 'undefined') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        if (is_numeric($value) && $value !== '') {
            return $value + 0;
        }

        return $value;
    }
}
