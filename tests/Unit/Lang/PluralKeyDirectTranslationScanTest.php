<?php

namespace Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `단수|복수` 영어 문구를 단·복수 고르기 없이 부르는 호출부 정적 스캔.
 *
 * 영어 값에 `|` 가 있는 키(Laravel 복수형)를 `__()`·`trans()`·`Lang::get()`·`@lang()` 로 부르면 수를 고르지 않아
 * 두 문장이 `|` 로 이어진 채 화면·응답에 나온다. 그런 키는 `trans_choice()` / `trans_count()` 또는 치환값의
 * `count` 로 고르는 공용 번역점(ResponseHelper·활동 로그·예외 생성자)으로 불러야 한다.
 *
 * 스캔 범위: 코어 `app`·`routes`·`config`·`database`·`resources/views` 와 번들 확장
 * (`modules/_bundled`·`plugins/_bundled`·`templates/_bundled`)의 PHP·Blade — `tests`·`lang`·`vendor`·
 * `node_modules` 는 뺀다. 키 집합: 코어 `lang/en/*.php` 와 번들 모듈 `src/lang/en`·플러그인 `lang/en` 의 PHP 사전.
 *
 * 한계: 리터럴 키만 본다. 변수·조합 키(`__($e->getMessageKey())`, `__('prefix.'.$suffix)`)와 키를 들고 다니다
 * 나중에 번역하는 경로는 이 스캔이 잡지 못한다 — 그런 경로는 `trans_count()` 를 쓰고 해당 호출부 시험으로 지킨다.
 */
class PluralKeyDirectTranslationScanTest extends TestCase
{
    /**
     * 값의 `|` 가 단·복수 구분이 아닌 키(문장 속 파이프 문자 설명) — 근거와 함께 허용.
     *
     * @var array<string, string>
     */
    private const NOT_PLURAL_PIPE_KEYS = [
        'validation.schedule_command.shell_not_allowed' => '「파이프(|)」 문자를 설명하는 문장이다 — 수 자리표시자 없음',
    ];

    /**
     * 코어·번들 확장 en 사전에서 `단수|복수` 값을 가진 키를 모읍니다.
     *
     * @return array<int, string> 번역 키 목록 (확장은 `식별자::그룹.키`)
     */
    private function pluralKeys(): array
    {
        $sources = [];
        foreach (glob(base_path('lang/en/*.php')) as $file) {
            $sources[] = [$file, ''];
        }
        foreach (glob(base_path('modules/_bundled/*/src/lang/en/*.php')) as $file) {
            $sources[] = [$file, basename(dirname($file, 4)).'::'];
        }
        foreach (glob(base_path('plugins/_bundled/*/lang/en/*.php')) as $file) {
            $sources[] = [$file, basename(dirname($file, 3)).'::'];
        }

        $keys = [];
        foreach ($sources as [$file, $namespace]) {
            $group = basename($file, '.php');
            $walk = function (array $lines, string $prefix) use (&$walk, &$keys, $namespace, $group): void {
                foreach ($lines as $key => $value) {
                    $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                    if (is_array($value)) {
                        $walk($value, $path);
                    } elseif (is_string($value) && str_contains($value, '|')) {
                        $full = $namespace.$group.'.'.$path;
                        if (! isset(self::NOT_PLURAL_PIPE_KEYS[$full])) {
                            $keys[] = $full;
                        }
                    }
                }
            };
            $walk(require $file, '');
        }

        return $keys;
    }

    /**
     * 스캔 대상 PHP·Blade 파일 경로를 모읍니다.
     *
     * @param  array<int, string>  $roots  스캔할 루트 디렉토리(절대 경로)
     * @return array<int, string> 파일 절대 경로 목록
     */
    private function sourceFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! str_ends_with($path, '.php') || preg_match('#/(tests|lang|vendor|node_modules)/#', $path)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * 파일들에서 주어진 키를 직접 번역 함수로 부르는 줄을 찾습니다.
     *
     * @param  array<int, string>  $files  파일 절대 경로 목록
     * @param  array<int, string>  $keys  `단수|복수` 키 목록
     * @return array<int, string> `파일:줄 키` 목록
     */
    private function directCalls(array $files, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $alternation = implode('|', array_map(fn (string $key) => preg_quote($key, '/'), $keys));
        // `@lang` 은 Blade 지시어라 앞 글자를 가리지 않는다(`<p>@lang(...)` 처럼 태그 바로 뒤에 올 수 있다).
        // 함수형은 `->trans(`·`::trans(`·`$trans(` 같은 다른 메서드·변수를 잡지 않도록 앞 글자를 가린다.
        $pattern = '/(?:(?<![\w>:$])(?:__|trans|Lang::get)|@lang)\(\s*([\'"])('.$alternation.')\1/';

        $hits = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (! preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $relative = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $file), '/');
                $hits[] = "{$relative}:{$line} {$match[2][0]}";
            }
        }

        return $hits;
    }

    /**
     * 코어·번들 확장 코드에 `단수|복수` 키를 `__()` 류로 직접 부르는 곳이 없다.
     *
     * @scenario surface=source_scan
     *
     * @effects plural_keys_never_translated_without_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function plural_keys_are_not_translated_without_choosing_by_count(): void
    {
        $keys = $this->pluralKeys();
        $this->assertNotEmpty($keys, '`단수|복수` 키를 하나도 못 찾았다 — 사전 경로를 확인한다');

        $roots = array_map('base_path', ['app', 'routes', 'config', 'database', 'resources/views']);
        foreach (['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'] as $bundled) {
            $roots[] = base_path($bundled);
        }

        $hits = $this->directCalls($this->sourceFiles($roots), $keys);

        $this->assertSame([], $hits, "`단수|복수` 키를 `__()` 류로 불러 두 문장이 이어져 나온다 — trans_choice()/trans_count() 로 바꾼다:\n".implode("\n", $hits));
    }

    /**
     * 스캔은 리터럴 키의 `__()`·`trans()`·`Lang::get()`·`@lang()`(태그 바로 뒤 포함) 호출을 잡고, `trans_choice()`·`trans_count()` 는 넘긴다.
     *
     * @scenario surface=source_scan
     *
     * @effects scanner_detects_direct_translation_calls
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scanner_detects_direct_calls_and_ignores_count_aware_calls(): void
    {
        $dir = sys_get_temp_dir().'/plural-scan-'.uniqid();
        mkdir($dir);
        $file = $dir.'/Probe.php';
        file_put_contents($file, implode("\n", [
            '<?php',
            "__('menu.bulk_roles_success', ['count' => 1]);",
            'trans("menu.bulk_roles_success");',
            "Lang::get('menu.bulk_roles_success');",
            "@lang('menu.bulk_roles_success')",
            "<p>@lang('menu.bulk_roles_success')</p>",
            "trans_choice('menu.bulk_roles_success', 1);",
            "trans_count('menu.bulk_roles_success', ['count' => 1]);",
            "__('menu.bulk_roles_success_other');",
        ]));

        try {
            $hits = $this->directCalls([str_replace('\\', '/', $file)], ['menu.bulk_roles_success']);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        $this->assertCount(5, $hits, implode("\n", $hits));
    }
}
