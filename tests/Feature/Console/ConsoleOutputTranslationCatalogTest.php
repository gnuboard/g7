<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 콘솔 명령이 쓰는 번역 키가 ko·en 원본과 번들 ja 언어팩에 모두 있고, 치환 자리가 세 언어에서 같은지 검증한다.
 *
 * 코어(`app/Console`·시더)와 번들 모듈·플러그인(`src/Console`·`database/seeders`)의 파일에서 `__('리터럴 키')` 를 모두 모아,
 * 키가 가리키는 lang 파일을 직접 읽어 판정한다(확장 활성 여부와 무관). 키가 한 언어에서 빠지면 그 로케일의
 * 운영자는 키 원문이나 한국어 폴백을 보고, 치환 자리가 다르면 값이 들어가지 않은 `:name` 이 찍힌다.
 */
class ConsoleOutputTranslationCatalogTest extends TestCase
{
    /**
     * 읽어 둔 lang 파일 배열.
     *
     * @var array<string, array<mixed>|null>
     */
    private array $files = [];

    /**
     * 콘솔 명령 파일 목록을 돌려줍니다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function consoleFiles(): array
    {
        $dirs = [app_path('Console'), database_path('seeders'), app_path('Database')];
        foreach (['modules/_bundled', 'plugins/_bundled'] as $base) {
            foreach (File::directories(base_path($base)) as $ext) {
                foreach (['/src/Console', '/database/seeders'] as $sub) {
                    if (is_dir($ext.$sub)) {
                        $dirs[] = $ext.$sub;
                    }
                }
            }
        }

        $files = [];
        foreach ($dirs as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * 키 하나가 가리키는 lang 파일 경로(로케일별)와 파일 안 점 경로를 돌려줍니다.
     *
     * @param  string  $key  번역 키 (`modules.commands.x` 또는 `sirsoft-board::messages.console.x`)
     * @return array{paths: array<string, string>, path: string}|null 해석할 수 없으면 null
     */
    private function locate(string $key): ?array
    {
        if (str_contains($key, '::')) {
            [$identifier, $rest] = explode('::', $key, 2);
            [$file, $path] = array_pad(explode('.', $rest, 2), 2, '');
            if (is_dir(base_path("modules/_bundled/{$identifier}"))) {
                return ['path' => $path, 'paths' => [
                    'ko' => base_path("modules/_bundled/{$identifier}/src/lang/ko/{$file}.php"),
                    'en' => base_path("modules/_bundled/{$identifier}/src/lang/en/{$file}.php"),
                    'ja' => base_path("lang-packs/_bundled/g7-module-{$identifier}-ja/backend/ja/{$file}.php"),
                ]];
            }
            if (is_dir(base_path("plugins/_bundled/{$identifier}"))) {
                return ['path' => $path, 'paths' => [
                    'ko' => base_path("plugins/_bundled/{$identifier}/lang/ko/{$file}.php"),
                    'en' => base_path("plugins/_bundled/{$identifier}/lang/en/{$file}.php"),
                    'ja' => base_path("lang-packs/_bundled/g7-plugin-{$identifier}-ja/backend/ja/{$file}.php"),
                ]];
            }

            return null;
        }

        [$file, $path] = array_pad(explode('.', $key, 2), 2, '');
        if (! is_file(base_path("lang/ko/{$file}.php"))) {
            return null;
        }

        return ['path' => $path, 'paths' => [
            'ko' => base_path("lang/ko/{$file}.php"),
            'en' => base_path("lang/en/{$file}.php"),
            'ja' => base_path("lang-packs/_bundled/g7-core-ja/backend/ja/{$file}.php"),
        ]];
    }

    /**
     * lang 파일에서 점 경로의 값을 읽습니다.
     *
     * @param  string  $file  lang 파일 절대 경로
     * @param  string  $path  점 경로
     * @return mixed 값 (없으면 null)
     */
    private function read(string $file, string $path): mixed
    {
        if (! array_key_exists($file, $this->files)) {
            $this->files[$file] = is_file($file) ? require $file : null;
        }

        return $this->files[$file] === null ? null : data_get($this->files[$file], $path);
    }

    /**
     * 문구의 치환 자리 이름 집합을 돌려줍니다.
     *
     * @param  string  $message  문구
     * @return array<int, string> 정렬된 치환 자리 이름
     */
    private function placeholders(string $message): array
    {
        preg_match_all('/:([a-z_][a-z0-9_]*)/i', $message, $m);
        $names = array_values(array_unique(array_map('strtolower', $m[1])));
        sort($names);

        return $names;
    }

    /**
     * 콘솔 명령이 쓰는 리터럴 번역 키가 ko·en·ja 세 곳에 문자열로 있고 치환 자리가 같다.
     *
     * @scenario surface=console_translation_catalog
     *
     * @effects console_keys_resolve_in_ko_en_ja
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_console_translation_keys_exist_in_ko_en_ja_with_same_placeholders(): void
    {
        $keys = [];
        foreach ($this->consoleFiles() as $file) {
            preg_match_all("/(?<![\\w>\$:])(?:__|trans|trans_choice)\\(\\s*'([A-Za-z0-9_\\-]+(?:::)?[A-Za-z0-9_.\\-]+)'/", File::get($file), $m);
            foreach ($m[1] as $key) {
                $keys[$key][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertGreaterThan(500, count($keys), '콘솔 번역 키 모집단이 너무 작습니다');

        $problems = [];
        foreach ($keys as $key => $usedIn) {
            // `__('domain.group.'.$kind)` 처럼 뒤를 이어 붙이는 동적 키의 앞부분은 판정하지 않는다
            if (str_ends_with($key, '.')) {
                continue;
            }

            $where = $this->locate($key);
            if ($where === null) {
                $problems[] = "{$key}: lang 파일을 찾을 수 없음 ({$usedIn[0]})";

                continue;
            }

            $sets = [];
            foreach ($where['paths'] as $locale => $path) {
                $value = $this->read($path, $where['path']);
                // 표 머리글처럼 배열을 통째로 받는 키는 잎 이름과 잎마다의 치환 자리를 함께 비교한다
                if (is_array($value)) {
                    $value = implode("\n", array_map(
                        fn ($leaf, $text) => $leaf.'='.(is_string($text) ? $text : ''),
                        array_keys(Arr::dot($value)),
                        Arr::dot($value),
                    ));
                    $sets[$locale.'_leaves'] = array_keys(Arr::dot($this->read($path, $where['path'])));
                }
                if (! is_string($value)) {
                    $problems[] = "{$key}: {$locale} 에 문자열이 없음 ({$usedIn[0]})";

                    continue 2;
                }
                $sets[$locale] = $this->placeholders($value);
            }

            if (($sets['ko_leaves'] ?? null) !== ($sets['en_leaves'] ?? null) || ($sets['ko_leaves'] ?? null) !== ($sets['ja_leaves'] ?? null)) {
                $problems[] = "{$key}: 배열 잎 이름이 세 언어에서 다름";
            }

            if ($sets['en'] !== $sets['ko'] || $sets['ja'] !== $sets['ko']) {
                $problems[] = "{$key}: 치환 자리 불일치 ko[".implode(',', $sets['ko']).'] en['.implode(',', $sets['en']).'] ja['.implode(',', $sets['ja']).']';
            }
        }

        $this->assertSame([], $problems, implode("\n", array_slice($problems, 0, 40)));
    }
}
