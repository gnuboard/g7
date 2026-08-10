<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * 확장이 부르는 다국어 키가 실제로 해석되는지 검사하는 패리티 테스트 (#519)
 *
 * 확장 코드가 네임스페이스 없이 `__('board.anonymous')` 처럼 부르면, 그 그룹은 코어
 * lang 에 없으므로 Laravel 이 **키 문자열 자체를 그대로 돌려준다**. 예외도 경고도 없고
 * 응답 코드도 200 이라, 화면에 원시 키가 노출되기 전까지 아무 곳에서도 드러나지 않는다.
 * (실제 사례: 통합검색 결과의 작성자 자리에 `board.anonymous` 가 그대로 찍혔다.)
 *
 * 확장 소유 그룹은 `vendor-extension::group.key` 로 불러야 하고, 네임스페이스 없는 키는
 * **코어 lang 그룹일 때만** 해석된다. 호출 지점을 손으로 열거하면 새 확장·새 호출이 같은
 * 함정을 다시 밟으므로, 저장소를 훑어 모든 호출을 검사한다.
 *
 * @scenario case=extension_translation_key_resolution
 *
 * @effects extension_non_namespaced_key_resolves_to_core_group
 */
class ExtensionTranslationKeyResolutionTest extends TestCase
{
    /** 훑을 확장 소스 루트 */
    private const SOURCE_ROOTS = [
        'modules/_bundled',
        'plugins/_bundled',
    ];

    /** 네임스페이스 없는 그룹 키 호출 (`__('group.key')` / `trans('group.key')`) */
    private const KEY_PATTERN = "/\b(?:__|trans)\(\s*'([a-z][a-z0-9_]*)\.([a-zA-Z0-9_.\-]+)'/";

    /**
     * 확장의 네임스페이스 없는 다국어 키가 전부 코어 그룹으로 해석되는지 확인
     *
     * @effects extension_non_namespaced_key_resolves_to_core_group
     */
    public function test_every_non_namespaced_key_in_extensions_resolves_to_a_core_group(): void
    {
        $calls = $this->collectCalls();

        // 스캐너가 아무것도 못 찾으면 초록은 아무 의미가 없다 — 모집단부터 단언한다.
        $this->assertNotEmpty(
            $calls,
            '확장에서 네임스페이스 없는 다국어 키 호출을 한 건도 찾지 못했다. 스캔 경로나 패턴이 잘못됐을 수 있다'
        );

        $unresolvable = [];

        foreach ($calls as $call) {
            if (! $this->isCoreGroup($call['group'])) {
                $unresolvable[] = $call['file'].':'.$call['line']." → {$call['group']}.{$call['key']}";
            }
        }

        $this->assertSame(
            [],
            $unresolvable,
            "코어에 없는 그룹을 네임스페이스 없이 부르는 곳이 있다. 번역이 실패해도 예외가 나지 않고\n".
            "원시 키 문자열이 화면에 그대로 노출된다. `vendor-extension::group.key` 형태로 부를 것.\n".
            implode("\n", $unresolvable)
        );
    }

    /**
     * 검사기가 실제로 위반을 잡아내는지 합성 표본으로 확인
     *
     * 이 단언이 없으면 패턴이 아무것도 매칭하지 못하게 되어도 위 테스트는 계속 초록이다.
     */
    public function test_detector_flags_a_key_whose_group_is_not_in_core(): void
    {
        $matches = $this->matchesIn("<?php \$name = __('board.anonymous');");

        $this->assertCount(1, $matches, '합성 표본에서 호출을 찾지 못했다 — 패턴이 깨졌다');
        $this->assertSame('board', $matches[0]['group']);
        $this->assertFalse(
            $this->isCoreGroup($matches[0]['group']),
            '`board` 그룹이 코어 lang 에 생겼다면 이 표본을 다른 미해석 키로 바꿀 것'
        );
    }

    /**
     * 네임스페이스가 붙은 키는 검사 대상이 아님을 확인
     */
    public function test_detector_ignores_namespaced_keys(): void
    {
        $this->assertSame(
            [],
            $this->matchesIn("<?php \$name = __('sirsoft-board::messages.common.guest');")
        );
    }

    /**
     * 확장 소스에서 네임스페이스 없는 키 호출을 모읍니다.
     *
     * @return array<int, array{file: string, line: int, group: string, key: string}>
     */
    private function collectCalls(): array
    {
        $calls = [];

        foreach ($this->phpFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            $relative = str_replace('\\', '/', $relative);

            foreach ($this->matchesIn($contents) as $match) {
                $calls[] = [
                    'file' => $relative,
                    'line' => $match['line'],
                    'group' => $match['group'],
                    'key' => $match['key'],
                ];
            }
        }

        return $calls;
    }

    /**
     * 소스 문자열에서 네임스페이스 없는 키 호출을 뽑습니다.
     *
     * @param  string  $contents  PHP 소스
     * @return array<int, array{line: int, group: string, key: string}>
     */
    private function matchesIn(string $contents): array
    {
        $found = [];

        foreach (preg_split('/\R/', $contents) as $index => $line) {
            if (! preg_match_all(self::KEY_PATTERN, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $found[] = [
                    'line' => $index + 1,
                    'group' => $match[1],
                    'key' => $match[2],
                ];
            }
        }

        return $found;
    }

    /**
     * 그룹이 코어 lang 파일로 존재하는지 확인합니다.
     *
     * @param  string  $group  키의 첫 세그먼트
     * @return bool 코어 그룹 여부
     */
    private function isCoreGroup(string $group): bool
    {
        foreach (['ko', 'en'] as $locale) {
            if (file_exists(base_path("lang/{$locale}/{$group}.php"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 확장 소스의 PHP 파일 목록을 만듭니다.
     *
     * @return array<int, string> 파일 절대 경로
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SOURCE_ROOTS as $root) {
            $path = base_path($root);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
