<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * `X::m()` 정적 호출의 대상 메서드가 static 인지 잠근다 (#155).
 *
 * 사이트맵 생성기가 인스턴스 메서드 `TemplateManager::getActiveTemplate()` 을 정적으로 불러 PHP 8 에서 Error 가 났고,
 * catch 가 경고 한 줄로 삼켜 모든 사이트의 sitemap.xml 에서 정적 화면이 통째로 빠졌다. 단위 테스트는 운영 로직을 복사해
 * 우회했기 때문에 잡지 못했다. 같은 실수가 다시 들어오지 않도록 코어와 번들 확장의 정적 호출을 전수 검사한다.
 * 트러블슈팅 `[case:backend-61]` 의 회귀 그물이다.
 *
 * 판정 — 토큰으로 `이름::메서드(` 를 찾고 `use` 별칭·namespace 로 FQCN 을 해석한 뒤, 리플렉션으로 메서드가 있고 static 이
 * 아니면 위반이다. 다음은 제외한다.
 *   - `parent::` 호출, Facade 하위 클래스
 *   - 인스턴스 메서드(와 그 안의 비정적 클로저)에서 자기 클래스·부모·구현 인터페이스·trait 를 부르는 전달 호출
 *   - 정의되지 않은 메서드(`__callStatic`·Eloquent trait 의 `static::saved()` 등은 판정하지 않는다)
 *   - 해석되지 않는 클래스(`class_exists` 가드 뒤 선택 라이브러리 등)
 *
 * 프레임워크 없이 파일만 읽으므로 `PHPUnit\Framework\TestCase` 를 직접 상속한다.
 */
class StaticCallTargetsStaticMethodTest extends TestCase
{
    /** 코어 검사 디렉터리 */
    private const CORE_DIRS = ['app', 'routes', 'config', 'database', 'upgrades'];

    /** 번들 확장 루트 */
    private const BUNDLED_ROOTS = ['modules/_bundled', 'plugins/_bundled'];

    /** 모집단에서 빼는 경로 조각 */
    private const EXCLUDED_SEGMENTS = ['/vendor/', '/node_modules/', '/tests/'];

    /** 전달 호출이 아닌 정적 호출 판정에서 빼는 상위 클래스 */
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    /**
     * 코어와 번들 확장의 정적 호출이 모두 static 메서드를 가리킨다.
     *
     * @scenario surface=core_and_bundled_source
     *
     * @effects static_calls_target_static_methods
     *
     * @return void 반환값 없음
     */
    public function test_static_calls_target_static_methods(): void
    {
        $root = dirname(__DIR__, 3);
        $this->registerBundledAutoloaders($root);

        $files = $this->collectFiles($root);
        $calls = 0;
        $violations = [];

        foreach ($files as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            foreach ($this->scan((string) file_get_contents($path), $calls) as $hit) {
                $violations[] = $relative.':'.$hit;
            }
        }

        $this->assertGreaterThan(3000, count($files), '검사 모집단(파일)이 비정상적으로 작습니다');
        $this->assertGreaterThan(15000, $calls, '검사 모집단(정적 호출)이 비정상적으로 작습니다');
        $this->assertSame([], $violations, sprintf(
            '인스턴스 메서드를 정적으로 호출합니다 — PHP 8 에서 Error 입니다. 인스턴스를 주입받아 호출하세요 (파일 %d · 정적 호출 %d)',
            count($files),
            $calls,
        ));
    }

    /**
     * 대조군 — 위반 1건만 정확히 잡고 parent·전달 호출·Facade·static 메서드는 통과시킨다.
     *
     * @scenario surface=fixture
     *
     * @effects static_call_scanner_catches_only_real_violation
     *
     * @return void 반환값 없음
     */
    public function test_scanner_catches_exactly_the_fixture_violation(): void
    {
        $fixture = dirname(__DIR__, 2).'/Fixtures/Architecture/StaticCallViolationFixture.php';
        require_once $fixture;

        $calls = 0;
        $violations = $this->scan((string) file_get_contents($fixture), $calls);

        $this->assertGreaterThanOrEqual(8, $calls, '대조군의 정적 호출을 모두 세지 못했습니다');
        $this->assertCount(1, $violations, '대조군 위반은 정확히 1건이어야 합니다: '.implode(', ', $violations));
        $this->assertStringContainsString(
            'StaticCallTargetFixture::instanceMethod() → Tests\\Fixtures\\Architecture\\StaticCallTargetFixture',
            $violations[0],
        );
    }

    /**
     * 번들 확장 composer.json 의 psr-4 매핑으로 오토로더를 등록합니다(설치본보다 먼저 찾는다).
     *
     * @param  string  $root  저장소 루트
     * @return void 반환값 없음
     */
    private function registerBundledAutoloaders(string $root): void
    {
        $map = [];
        foreach (self::BUNDLED_ROOTS as $bundled) {
            foreach (glob($root.'/'.$bundled.'/*/composer.json') ?: [] as $composerFile) {
                $composer = json_decode((string) file_get_contents($composerFile), true);
                foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
                    foreach ((array) $dirs as $dir) {
                        $map[$prefix][] = rtrim(dirname($composerFile).'/'.$dir, '/').'/';
                    }
                }
            }
        }

        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $dirs) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                foreach ($dirs as $dir) {
                    if (is_file($dir.$relative)) {
                        require_once $dir.$relative;

                        return;
                    }
                }
            }
        }, true, true);
    }

    /**
     * 검사 대상 PHP 파일 목록을 모읍니다.
     *
     * @param  string  $root  저장소 루트
     * @return array<int, string> 파일 절대 경로 목록
     */
    private function collectFiles(string $root): array
    {
        $dirs = array_map(fn (string $dir): string => $root.'/'.$dir, self::CORE_DIRS);
        foreach (self::BUNDLED_ROOTS as $bundled) {
            foreach (glob($root.'/'.$bundled.'/*', GLOB_ONLYDIR) ?: [] as $extension) {
                $dirs[] = $extension;
            }
        }

        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::EXCLUDED_SEGMENTS as $segment) {
                    if (str_contains($path, $segment)) {
                        continue 2;
                    }
                }
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * 파일 하나의 정적 호출을 검사해 위반을 돌려줍니다.
     *
     * @param  string  $source  PHP 소스
     * @param  int  $calls  지금까지 센 정적 호출 수(누적)
     * @return array<int, string> `줄 X::m() → FQCN` 형식의 위반 목록
     */
    private function scan(string $source, int &$calls): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            fn (\PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $namespace = '';
        $imports = [];
        $depth = 0;
        /** @var array<int, array{kind: string, depth: int, name?: string|null, static?: bool}> $scopes */
        $scopes = [];
        $pending = null;
        $violations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;

            if ($token->is(T_NAMESPACE) && ! ($tokens[$i + 1] ?? null)?->is(T_NS_SEPARATOR)) {
                $namespace = '';
                $j = $i + 1;
                while (isset($tokens[$j]) && ! $tokens[$j]->is([';', '{'])) {
                    $namespace .= $tokens[$j]->text;
                    $j++;
                }
                $imports = [];

                continue;
            }

            if ($token->is(T_USE) && ! $this->insideKind($scopes, ['class', 'function']) && ! ($previous?->is(')'))) {
                $i = $this->readImport($tokens, $i, $imports);

                continue;
            }

            if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ! ($previous?->is(T_DOUBLE_COLON))) {
                $nameToken = $tokens[$i + 1] ?? null;
                $name = ($nameToken?->is(T_STRING) && ! $previous?->is(T_NEW)) ? ltrim($namespace.'\\'.$nameToken->text, '\\') : null;
                $pending = ['kind' => 'class', 'name' => $name];

                continue;
            }

            if ($token->is([T_FUNCTION])) {
                $pending = ['kind' => 'function', 'static' => $this->hasStaticModifier($tokens, $i)];

                continue;
            }

            if ($token->is(';') && $pending !== null && $pending['kind'] === 'function') {
                // 추상·인터페이스 메서드 선언
                $pending = null;

                continue;
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pending !== null && $token->is('{')) {
                    $scopes[] = $pending + ['depth' => $depth];
                    $pending = null;
                }

                continue;
            }

            if ($token->is('}')) {
                while ($scopes !== [] && end($scopes)['depth'] === $depth) {
                    array_pop($scopes);
                }
                $depth--;

                continue;
            }

            if (! $token->is(T_DOUBLE_COLON) || $previous === null) {
                continue;
            }

            $method = $tokens[$i + 1] ?? null;
            $open = $tokens[$i + 2] ?? null;
            if (! $method?->is(T_STRING) || ! $open?->is('(')) {
                continue;
            }
            if (! $previous->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])) {
                continue;
            }
            // `$obj->prop::m()`·`X::Y::m()` 처럼 클래스가 식으로 정해지는 호출은 이름으로 해석할 수 없다
            if (($tokens[$i - 2] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW])) {
                continue;
            }

            $calls++;
            $written = $previous->text;
            if (strtolower($written) === 'parent') {
                continue;
            }

            $currentClass = $this->currentClass($scopes);
            $fqcn = in_array(strtolower($written), ['self', 'static'], true)
                ? $currentClass
                : $this->resolveName($written, $namespace, $imports);

            $violation = $fqcn === null ? null : $this->judge($fqcn, $method->text, $currentClass, $this->inInstanceContext($scopes));
            if ($violation !== null) {
                $violations[] = $token->line.' '.$written.'::'.$method->text.'() → '.$violation;
            }
        }

        return $violations;
    }

    /**
     * 해석한 대상 메서드가 위반인지 판정합니다.
     *
     * @param  string  $fqcn  대상 클래스 FQCN
     * @param  string  $method  메서드 이름
     * @param  string|null  $currentClass  호출이 든 클래스 FQCN
     * @param  bool  $instanceContext  `$this` 가 있는 문맥인지
     * @return string|null 위반이면 대상 FQCN, 아니면 null
     */
    private function judge(string $fqcn, string $method, ?string $currentClass, bool $instanceContext): ?string
    {
        try {
            if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn)) {
                return null;
            }
            $target = new ReflectionClass($fqcn);
        } catch (\Throwable) {
            return null;
        }

        if (! $target->hasMethod($method) || $target->getMethod($method)->isStatic()) {
            return null;
        }
        if ($target->isSubclassOf(self::FACADE)) {
            return null;
        }
        if ($instanceContext && $currentClass !== null && $this->isForwardingTarget($currentClass, $target)) {
            return null;
        }

        return $target->getName();
    }

    /**
     * 호출이 든 클래스가 대상 자신이거나 그 하위·구현·trait 사용 클래스인지(전달 호출) 확인합니다.
     *
     * @param  string  $currentClass  호출이 든 클래스 FQCN
     * @param  ReflectionClass  $target  대상 클래스
     * @return bool 전달 호출이면 true
     */
    private function isForwardingTarget(string $currentClass, ReflectionClass $target): bool
    {
        if (strcasecmp($currentClass, $target->getName()) === 0) {
            return true;
        }

        try {
            if (! class_exists($currentClass) && ! trait_exists($currentClass) && ! enum_exists($currentClass)) {
                return false;
            }
            $current = new ReflectionClass($currentClass);
        } catch (\Throwable) {
            return false;
        }

        if ($current->isSubclassOf($target->getName())) {
            return true;
        }

        $traits = [];
        for ($class = $current; $class !== false; $class = $class->getParentClass()) {
            $traits = array_merge($traits, $this->traitsOf($class));
        }

        return in_array($target->getName(), $traits, true);
    }

    /**
     * 클래스가 쓰는 trait 를 중첩까지 모읍니다.
     *
     * @param  ReflectionClass  $class  클래스
     * @return array<int, string> trait FQCN 목록
     */
    private function traitsOf(ReflectionClass $class): array
    {
        $names = [];
        foreach ($class->getTraits() as $trait) {
            $names[] = $trait->getName();
            $names = array_merge($names, $this->traitsOf($trait));
        }

        return $names;
    }

    /**
     * `use` 가져오기 문 하나를 읽어 별칭 표에 넣고, 문이 끝난 토큰 위치를 돌려줍니다.
     *
     * @param  array<int, \PhpToken>  $tokens  토큰 목록
     * @param  int  $i  `use` 토큰 위치
     * @param  array<string, string>  $imports  별칭(소문자) → FQCN
     * @return int 문 끝(`;`) 위치
     */
    private function readImport(array $tokens, int $i, array &$imports): int
    {
        $j = $i + 1;
        if (($tokens[$j] ?? null)?->is([T_FUNCTION, T_CONST])) {
            while (isset($tokens[$j]) && ! $tokens[$j]->is(';')) {
                $j++;
            }

            return $j;
        }

        $prefix = '';
        $name = '';
        $alias = null;
        $afterAs = false;
        $flush = function () use (&$prefix, &$name, &$alias, &$afterAs, &$imports): void {
            if ($name !== '') {
                $full = ltrim($prefix.$name, '\\');
                $short = $alias ?? substr($full, (int) strrpos('\\'.$full, '\\'));
                $imports[strtolower($short)] = $full;
            }
            $name = '';
            $alias = null;
            $afterAs = false;
        };

        for (; isset($tokens[$j]) && ! $tokens[$j]->is(';'); $j++) {
            $token = $tokens[$j];
            if ($token->is('{')) {
                $prefix = rtrim($name, '\\').'\\';
                $name = '';
            } elseif ($token->is([',', '}'])) {
                $flush();
            } elseif ($token->is(T_AS)) {
                $afterAs = true;
            } elseif ($afterAs) {
                $alias = $token->text;
            } elseif (! $token->is([T_FUNCTION, T_CONST])) {
                $name .= $token->text;
            }
        }
        $flush();

        return $j;
    }

    /**
     * 적힌 클래스 이름을 namespace·가져오기로 FQCN 으로 해석합니다.
     *
     * @param  string  $written  적힌 이름
     * @param  string  $namespace  현재 namespace
     * @param  array<string, string>  $imports  별칭(소문자) → FQCN
     * @return string 해석한 전체 클래스 이름
     */
    private function resolveName(string $written, string $namespace, array $imports): string
    {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        $first = strtolower(explode('\\', $written, 2)[0]);
        if (isset($imports[$first])) {
            $rest = str_contains($written, '\\') ? substr($written, strpos($written, '\\')) : '';

            return $imports[$first].$rest;
        }

        return ltrim($namespace.'\\'.$written, '\\');
    }

    /**
     * 함수 선언 앞에 `static` 수식어가 있는지 확인합니다(정적 메서드·정적 클로저).
     *
     * @param  array<int, \PhpToken>  $tokens  토큰 목록
     * @param  int  $i  `function` 토큰 위치
     * @return bool static 이면 true
     */
    private function hasStaticModifier(array $tokens, int $i): bool
    {
        // 수식어는 속성(#[...]) 뒤, `function` 바로 앞에 붙는다 — 수식어만 거슬러 올라가며 static 을 찾는다
        for ($j = $i - 1; $j >= 0 && $tokens[$j]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT, T_READONLY, T_STATIC]); $j--) {
            if ($tokens[$j]->is(T_STATIC)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 스코프 스택에 주어진 종류가 있는지 확인합니다.
     *
     * @param  array<int, array<string, mixed>>  $scopes  스코프 스택
     * @param  array<int, string>  $kinds  종류 목록
     * @return bool 있으면 true
     */
    private function insideKind(array $scopes, array $kinds): bool
    {
        foreach ($scopes as $scope) {
            if (in_array($scope['kind'], $kinds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 가장 안쪽 클래스 스코프의 FQCN 을 돌려줍니다(익명 클래스는 null).
     *
     * @param  array<int, array<string, mixed>>  $scopes  스코프 스택
     * @return string|null 클래스 FQCN
     */
    private function currentClass(array $scopes): ?string
    {
        for ($k = count($scopes) - 1; $k >= 0; $k--) {
            if ($scopes[$k]['kind'] === 'class') {
                return $scopes[$k]['name'];
            }
        }

        return null;
    }

    /**
     * `$this` 가 있는 문맥인지 확인합니다 — 가장 안쪽 클래스까지의 함수 스코프가 모두 비정적이어야 한다.
     *
     * @param  array<int, array<string, mixed>>  $scopes  스코프 스택
     * @return bool 인스턴스 문맥이면 true
     */
    private function inInstanceContext(array $scopes): bool
    {
        $sawFunction = false;
        for ($k = count($scopes) - 1; $k >= 0; $k--) {
            if ($scopes[$k]['kind'] === 'class') {
                return $sawFunction;
            }
            if ($scopes[$k]['static']) {
                return false;
            }
            $sawFunction = true;
        }

        return false;
    }
}
