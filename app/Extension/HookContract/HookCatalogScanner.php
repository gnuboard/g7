<?php

namespace App\Extension\HookContract;

/**
 * 코어·번들 확장 소스에서 공개 훅 발화처를 모아 훅 계약 목록을 만드는 스캐너.
 *
 * 공개 훅의 이름과 인자 개수는 서드파티 확장이 코드에 박아 쓰는 계약이다(설계 결정 2026-09-29).
 * 이 스캐너가 만든 목록(`app/Extension/HookContract/data/catalog.php`)을 기준으로
 *  - 스냅샷 테스트가 이름 삭제·인자 개수 변경을 잡고,
 *  - 등록 시점 가드가 발화처 없는 훅 구독을 경고한다.
 *
 * PHP 토크나이저로 `HookManager::doAction|applyFilters|applyFiltersWithLegacyName|doActionWithLegacyName`
 * 과 래퍼 `ExtensionFilter::apply` 호출을 읽는다. 첫 인자가 문자열 리터럴이 아닌 발화처(이름 조립)는
 * 풀지 않고 "동적 발화처" 로 보고한다 — 그 이름들은 `app/Extension/HookContract/data/dynamic.php` 에 손으로 적고, 목록에 없는
 * 새 동적 발화처는 스냅샷 테스트가 실패시킨다(목록이 조용히 불완전해지지 않게).
 */
class HookCatalogScanner
{
    /**
     * 액션 발화 메서드 → 훅 이름 인자 개수.
     */
    private const ACTION_METHODS = [
        'doAction' => 1,
        'doActionWithLegacyName' => 2,
    ];

    /**
     * 필터 발화 메서드 → 훅 이름 인자 개수.
     */
    private const FILTER_METHODS = [
        'applyFilters' => 1,
        'applyFiltersWithLegacyName' => 2,
    ];

    /**
     * 스캔 결과를 반환합니다.
     *
     * @param  string  $basePath  저장소 루트
     * @return array{hooks: array<string, array{type: string, args: array<int, int|string>, owner: string, replacement?: string}>, dynamic: array<int, array{file: string, line: int, expr: string}>}
     */
    public function scan(string $basePath): array
    {
        $hooks = [];
        $dynamic = [];

        foreach ($this->sourceRoots($basePath) as $owner => $root) {
            foreach ($this->phpFiles($root) as $file) {
                $relative = str_replace('\\', '/', substr($file, strlen($basePath) + 1));

                foreach ($this->callsIn((string) file_get_contents($file)) as $call) {
                    foreach ($call['names'] as $position => $name) {
                        if ($name === null) {
                            $dynamic[] = ['file' => $relative, 'line' => $call['line'], 'expr' => $call['nameExpr']];

                            continue;
                        }

                        $entry = $hooks[$name] ?? ['type' => $call['type'], 'args' => [], 'owner' => $owner];
                        if (! in_array($call['args'], $entry['args'], true)) {
                            $entry['args'][] = $call['args'];
                        }
                        // *WithLegacyName 의 두 번째 이름은 호환용 옛 이름 — 표준 이름을 대체로 적는다
                        if ($call['legacyPair'] && $position === 1 && is_string($call['names'][0] ?? null)) {
                            $entry['replacement'] = $call['names'][0];
                        }
                        $hooks[$name] = $entry;
                    }
                }
            }
        }

        ksort($hooks);
        foreach ($hooks as &$entry) {
            usort($entry['args'], fn ($a, $b) => strcmp((string) $a, (string) $b));
        }
        unset($entry);

        usort($dynamic, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return ['hooks' => $hooks, 'dynamic' => $dynamic];
    }

    /**
     * 스캔 대상 소스 루트 (소유자 → 디렉토리).
     *
     * @param  string  $basePath  저장소 루트
     * @return array<string, string> 소유자 식별자 → 절대 경로
     */
    private function sourceRoots(string $basePath): array
    {
        $roots = ['core' => $basePath.DIRECTORY_SEPARATOR.'app'];

        foreach (['modules', 'plugins'] as $type) {
            foreach (glob($basePath."/{$type}/_bundled/*/src", GLOB_ONLYDIR) ?: [] as $src) {
                $roots[basename(dirname($src))] = $src;
            }
        }

        return $roots;
    }

    /**
     * 디렉토리 아래 PHP 파일 목록 (이름순).
     *
     * @param  string  $root  디렉토리
     * @return array<int, string> 절대 경로 목록
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * 소스 한 파일에서 훅 발화 호출을 찾습니다.
     *
     * @param  string  $source  PHP 소스
     * @return array<int, array{type: string, names: array<int, string|null>, legacyPair: bool, args: int|string, line: int, nameExpr: string}>
     */
    public function callsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $calls = [];
        $context = $this->fileContext($tokens);

        for ($i = 0; $i < $count; $i++) {
            $class = $this->classNameAt($tokens, $i);
            if ($class === null) {
                continue;
            }

            $j = $this->nextMeaningful($tokens, $i + 1);
            if (! is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $m = $this->nextMeaningful($tokens, $j + 1);
            if (! is_array($tokens[$m] ?? null) || $tokens[$m][0] !== T_STRING) {
                continue;
            }
            $method = $tokens[$m][1];

            [$type, $nameArgs] = match (true) {
                $class === 'HookManager' && isset(self::ACTION_METHODS[$method]) => ['action', self::ACTION_METHODS[$method]],
                $class === 'HookManager' && isset(self::FILTER_METHODS[$method]) => ['filter', self::FILTER_METHODS[$method]],
                $class === 'ExtensionFilter' && $method === 'apply' => ['filter', 1],
                default => [null, 0],
            };
            if ($type === null) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $m + 1);
            if (($tokens[$open] ?? null) !== '(') {
                continue; // 메서드 참조 등 호출이 아닌 경우
            }

            $args = $this->splitArguments($tokens, $open);
            $names = [];
            for ($k = 0; $k < $nameArgs; $k++) {
                $resolved = $this->resolveName($args[$k] ?? [], $context);
                if ($resolved !== null) {
                    $names[] = $resolved;

                    continue;
                }

                // `조건 ? 'a' : 'b'` 인자, 또는 `$hook = 조건 ? 'a' : 'b';` 직전 대입이 리터럴 후보만 담으면
                // 후보를 모두 등록한다
                $candidates = in_array('?', $args[$k] ?? [], true)
                    ? $this->candidatesFromTokens($args[$k], $context)
                    : $this->candidatesFromAssignment($tokens, $i, $args[$k] ?? [], $context);
                array_push($names, ...($candidates ?: [null]));
            }

            $rest = array_slice($args, $nameArgs);
            $variadic = false;
            foreach ($rest as $arg) {
                if ($this->startsWithEllipsis($arg)) {
                    $variadic = true;
                }
            }

            $calls[] = [
                'type' => $type,
                'names' => $names,
                'legacyPair' => $nameArgs === 2 && count($names) === 2,
                'args' => $variadic ? '*' : count($rest),
                'line' => is_array($tokens[$i]) ? $tokens[$i][2] : 0,
                'nameExpr' => $this->render($args[0] ?? []),
            ];
        }

        return $calls;
    }

    /**
     * 토큰 위치가 대상 클래스 이름이면 짧은 이름을 반환합니다.
     *
     * @param  array<int, mixed>  $tokens  토큰 목록
     * @param  int  $i  위치
     * @return string|null 'HookManager' / 'ExtensionFilter' 또는 null
     */
    private function classNameAt(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        if (! is_array($token)) {
            return null;
        }

        $text = match ($token[0]) {
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => $token[1],
            default => null,
        };
        if ($text === null) {
            return null;
        }

        $short = ltrim(strrchr('\\'.$text, '\\'), '\\');

        return in_array($short, ['HookManager', 'ExtensionFilter'], true) ? $short : null;
    }

    /**
     * 공백·주석을 건너뛴 다음 토큰 위치를 반환합니다.
     *
     * @param  array<int, mixed>  $tokens  토큰 목록
     * @param  int  $i  시작 위치
     * @return int 위치
     */
    private function nextMeaningful(array $tokens, int $i): int
    {
        while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }

        return $i;
    }

    /**
     * 여는 괄호부터 짝이 맞는 닫는 괄호까지의 인자를 최상위 쉼표로 나눕니다.
     *
     * @param  array<int, mixed>  $tokens  토큰 목록
     * @param  int  $open  여는 괄호 위치
     * @return array<int, array<int, mixed>> 인자별 토큰 목록 (공백·주석 제외)
     */
    private function splitArguments(array $tokens, int $open): array
    {
        $depth = 0;
        $args = [];
        $current = [];

        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $args[] = $current;
                    }
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $current[] = $token;
        }

        return $args;
    }

    /**
     * 훅 이름 인자를 문자열로 풉니다 — 문자열 리터럴·클래스 상수·그 둘의 `.` 연결만 풀고,
     * 변수·메서드 호출이 섞이면 null(동적 발화처)을 반환합니다.
     *
     * @param  array<int, mixed>  $arg  인자 토큰
     * @param  array{namespace: string, class: string|null, uses: array<string, string>, constants: array<string, string>}  $context  파일 문맥
     * @return string|null 풀린 이름
     */
    private function resolveName(array $arg, array $context): ?string
    {
        if ($arg === []) {
            return null;
        }

        $parts = [];
        $expectOperand = true;
        $n = count($arg);

        for ($i = 0; $i < $n; $i++) {
            $token = $arg[$i];

            if (! $expectOperand) {
                if ($token !== '.') {
                    return null;
                }
                $expectOperand = true;

                continue;
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $raw = $token[1];
                if ($raw[0] === '"' && str_contains($raw, '$')) {
                    return null;
                }
                $parts[] = stripcslashes(substr($raw, 1, -1));
                $expectOperand = false;

                continue;
            }

            // Class::CONST (self/static/짧은 이름/정규화 이름)
            $classToken = $token;
            $colon = $arg[$i + 1] ?? null;
            $constToken = $arg[$i + 2] ?? null;
            if (is_array($classToken) && in_array($classToken[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && is_array($colon) && $colon[0] === T_DOUBLE_COLON
                && is_array($constToken) && $constToken[0] === T_STRING
                && (($arg[$i + 3] ?? null) !== '(')) {
                $value = $this->resolveConstant($classToken[1], $constToken[1], $context);
                if ($value === null) {
                    return null;
                }
                $parts[] = $value;
                $i += 2;
                $expectOperand = false;

                continue;
            }

            return null;
        }

        return $expectOperand ? null : implode('', $parts);
    }

    /**
     * 변수 하나로 넘긴 훅 이름을, 같은 함수 안 직전 대입문의 리터럴 후보로 풉니다.
     *
     * 대입 우변에 함수·메서드 호출이 있으면(이름을 계산하는 경우) 풀지 않는다. 후보는 훅 이름 모양
     * (`segment.segment...`)의 문자열 리터럴과 풀리는 클래스 상수다.
     *
     * @param  array<int, mixed>  $tokens  파일 토큰
     * @param  int  $callAt  호출 위치
     * @param  array<int, mixed>  $arg  이름 인자 토큰
     * @param  array{namespace: string, class: string|null, uses: array<string, string>, constants: array<string, string>}  $context  파일 문맥
     * @return array<int, string> 후보 이름 (풀 수 없으면 빈 배열)
     */
    private function candidatesFromAssignment(array $tokens, int $callAt, array $arg, array $context): array
    {
        if (count($arg) !== 1 || ! is_array($arg[0]) || $arg[0][0] !== T_VARIABLE) {
            return [];
        }
        $variable = $arg[0][1];

        for ($i = $callAt - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_FUNCTION) {
                return []; // 함수 경계 — 매개변수로 들어온 이름
            }
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }
            $eq = $this->nextMeaningful($tokens, $i + 1);
            if (($tokens[$eq] ?? null) !== '=') {
                continue;
            }

            $rhs = [];
            for ($j = $eq + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $rhs[] = $tokens[$j];
                }
            }

            return $this->candidatesFromTokens($rhs, $context);
        }

        return [];
    }

    /**
     * 식 토큰에서 훅 이름 후보(리터럴·클래스 상수)를 모읍니다 — 호출이 섞이면 빈 배열.
     *
     * 삼항식처럼 이름이 몇 개의 리터럴 중 하나로 정해지는 식을 위한 것이다. 문자열 연결(`.`)이
     * 있으면 조각이 이름이 아니므로 풀지 않는다.
     *
     * @param  array<int, mixed>  $expr  식 토큰 (공백·주석 제외)
     * @param  array{namespace: string, class: string|null, uses: array<string, string>, constants: array<string, string>}  $context  파일 문맥
     * @return array<int, string> 후보 이름
     */
    private function candidatesFromTokens(array $expr, array $context): array
    {
        $names = [];
        foreach ($expr as $idx => $t) {
            if ($t === '.' || ($t === '(' && ! $this->isEnumValueAccess($expr, $idx))) {
                return []; // 이름을 계산하는 연결·호출
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = stripcslashes(substr($t[1], 1, -1));
                if (preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $value)) {
                    $names[] = $value;
                }
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && is_array($expr[$idx + 1] ?? null) && $expr[$idx + 1][0] === T_DOUBLE_COLON
                && is_array($expr[$idx + 2] ?? null) && $expr[$idx + 2][0] === T_STRING) {
                $value = $this->resolveConstant($t[1], $expr[$idx + 2][1], $context);
                if ($value !== null && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $value)) {
                    $names[] = $value;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * 괄호가 호출이 아니라 비교식의 묶음인지(여는 괄호 앞이 연산자) 판단합니다.
     *
     * @param  array<int, mixed>  $rhs  우변 토큰
     * @param  int  $idx  여는 괄호 위치
     * @return bool 호출이 아니면 true
     */
    private function isEnumValueAccess(array $rhs, int $idx): bool
    {
        $prev = $rhs[$idx - 1] ?? null;

        // match (...) { 조건 => '이름', ... } 의 괄호도 호출이 아니다
        if (is_array($prev) && $prev[0] === T_MATCH) {
            return true;
        }

        return $prev === null || (! is_array($prev) && in_array($prev, ['=', '?', ':', '(', ',', '!'], true));
    }

    /**
     * 클래스 상수를 문자열로 풉니다 (같은 파일 상수 → 오토로드 순).
     *
     * @param  string  $class  소스에 적힌 클래스 이름 (self/static 포함)
     * @param  string  $name  상수 이름
     * @param  array{namespace: string, class: string|null, uses: array<string, string>, constants: array<string, string>}  $context  파일 문맥
     * @return string|null 상수 값 (문자열이 아니거나 풀 수 없으면 null)
     */
    private function resolveConstant(string $class, string $name, array $context): ?string
    {
        $lower = strtolower($class);
        if (in_array($lower, ['self', 'static'], true)) {
            if (isset($context['constants'][$name])) {
                return $context['constants'][$name];
            }
            $fqcn = $context['class'];
        } elseif (str_starts_with($class, '\\')) {
            $fqcn = ltrim($class, '\\');
        } else {
            $first = strtok($class, '\\');
            $fqcn = isset($context['uses'][strtolower($first)])
                ? $context['uses'][strtolower($first)].substr($class, strlen($first))
                : ltrim($context['namespace'].'\\'.$class, '\\');
        }

        if ($fqcn === null) {
            return null;
        }

        try {
            if ((class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) && defined($fqcn.'::'.$name)) {
                $value = constant($fqcn.'::'.$name);

                return is_string($value) ? $value : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * 파일의 네임스페이스·클래스·use 별칭·문자열 클래스 상수를 모읍니다.
     *
     * @param  array<int, mixed>  $tokens  토큰 목록
     * @return array{namespace: string, class: string|null, uses: array<string, string>, constants: array<string, string>}
     */
    private function fileContext(array $tokens): array
    {
        $namespace = '';
        $class = null;
        $uses = [];
        $constants = [];
        $depth = 0;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $j = $this->nextMeaningful($tokens, $i + 1);
                if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $namespace = $tokens[$j][1];
                }
            } elseif ($token[0] === T_USE && $depth === 0) {
                $j = $this->nextMeaningful($tokens, $i + 1);
                if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    $full = ltrim($tokens[$j][1], '\\');
                    $alias = substr(strrchr('\\'.$full, '\\'), 1);
                    $k = $this->nextMeaningful($tokens, $j + 1);
                    if (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_AS) {
                        $a = $this->nextMeaningful($tokens, $k + 1);
                        $alias = is_array($tokens[$a] ?? null) ? $tokens[$a][1] : $alias;
                    }
                    $uses[strtolower($alias)] = $full;
                }
            } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $class === null) {
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if (($tokens[$prev] ?? null) === '::' || (is_array($tokens[$prev] ?? null) && $tokens[$prev][0] === T_DOUBLE_COLON)) {
                    continue; // Foo::class
                }
                $j = $this->nextMeaningful($tokens, $i + 1);
                if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_STRING) {
                    $class = ltrim($namespace.'\\'.$tokens[$j][1], '\\');
                }
            } elseif ($token[0] === T_CONST) {
                $j = $this->nextMeaningful($tokens, $i + 1);
                // 타입 선언 상수(const string NAME = ...) 지원
                $k = $this->nextMeaningful($tokens, $j + 1);
                if (($tokens[$k] ?? null) !== '=') {
                    $j = $k;
                    $k = $this->nextMeaningful($tokens, $j + 1);
                }
                $v = $this->nextMeaningful($tokens, $k + 1);
                $end = $this->nextMeaningful($tokens, $v + 1);
                if (is_array($tokens[$j] ?? null) && ($tokens[$k] ?? null) === '='
                    && is_array($tokens[$v] ?? null) && $tokens[$v][0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array($tokens[$end] ?? null, [';', ','], true)) {
                    $constants[$tokens[$j][1]] = stripcslashes(substr($tokens[$v][1], 1, -1));
                }
            }
        }

        return ['namespace' => $namespace, 'class' => $class, 'uses' => $uses, 'constants' => $constants];
    }

    /**
     * 인자가 전개 연산자(...)로 시작하는지 확인합니다.
     *
     * @param  array<int, mixed>  $arg  인자 토큰
     * @return bool 전개 인자 여부
     */
    private function startsWithEllipsis(array $arg): bool
    {
        return isset($arg[0]) && is_array($arg[0]) && $arg[0][0] === T_ELLIPSIS;
    }

    /**
     * 토큰 목록을 문자열로 되돌립니다.
     *
     * @param  array<int, mixed>  $tokens  토큰 목록
     * @return string 소스 조각
     */
    private function render(array $tokens): string
    {
        return implode('', array_map(fn ($t) => is_array($t) ? $t[1] : $t, $tokens));
    }
}
