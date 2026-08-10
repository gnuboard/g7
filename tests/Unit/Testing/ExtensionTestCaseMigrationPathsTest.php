<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 TestCase 의 마이그레이션 경로 선언 회귀 테스트
 *
 * 배경 (troubleshooting-backend.md 사례 21):
 *   `RefreshDatabase` 는 프로세스당 한 번만 fresh 마이그레이션을 수행하므로, 그 프로세스에서
 *   **가장 먼저 실행된 TestCase 의 `migrateFreshUsing()` 이 스위트 전체의 스키마를 확정**한다.
 *   자기 확장 경로만 넘기는 TestCase 가 먼저 실행되면 뒤따르는 확장의 테이블이 아예 생성되지
 *   않아 "Base table or view not found" 가 무더기로 난다.
 *
 * 이 결함은 **여러 확장 스위트를 한 명령으로 묶어 돌릴 때만** 드러난다. 각 스위트를 단독으로
 * 돌리면 전부 green 이라, 실행 기반 테스트로는 잡히지 않고 CI 조합이 바뀔 때 되살아난다.
 * 그래서 선언 자체를 검사한다 — 모든 확장 TestCase 가 번들 확장 전체를 포함해야 한다.
 */
class ExtensionTestCaseMigrationPathsTest extends TestCase
{
    /**
     * 저장소 루트 절대 경로.
     *
     * 데이터 프로바이더는 static 이라 애플리케이션 부팅 전에 실행된다 — `base_path()` 를 쓸 수
     * 없으므로 이 파일 위치에서 역산한다 (`tests/Unit/Testing/` → 3단계 상위).
     *
     * @return string 저장소 루트
     */
    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 검사 대상: `migrateFreshUsing()` 을 정의한 번들 확장 TestCase 파일.
     *
     * @return array<string, array{string}> [파일 경로]
     */
    public static function extensionTestCaseProvider(): array
    {
        $patterns = [
            'modules/_bundled/*/tests/ModuleTestCase.php',
            'plugins/_bundled/*/tests/PluginTestCase.php',
            'templates/_bundled/*/tests/TemplateTestCase.php',
        ];

        $cases = [];

        foreach ($patterns as $pattern) {
            foreach (glob(self::repositoryRoot().DIRECTORY_SEPARATOR.$pattern) ?: [] as $path) {
                $source = file_get_contents($path);

                // migrateFreshUsing 을 재정의하지 않는 TestCase 는 코어 기본 스키마를 쓰므로 대상 아님
                if (! str_contains($source, 'function migrateFreshUsing')) {
                    continue;
                }

                $relative = str_replace(self::repositoryRoot().DIRECTORY_SEPARATOR, '', $path);
                $cases[$relative] = [$path];
            }
        }

        return $cases;
    }

    /**
     * 모든 확장 TestCase 가 번들 모듈·플러그인 마이그레이션을 전부 포함한다.
     *
     * @param  string  $path  TestCase 파일 절대 경로
     */
    #[DataProvider('extensionTestCaseProvider')]
    public function test_extension_test_case_includes_all_bundled_migrations(string $path): void
    {
        $source = file_get_contents($path);
        $relative = str_replace(self::repositoryRoot().DIRECTORY_SEPARATOR, '', $path);

        foreach (['modules/_bundled/*/database/migrations', 'plugins/_bundled/*/database/migrations'] as $globPattern) {
            $this->assertStringContainsString(
                $globPattern,
                $source,
                "{$relative} 의 migrateFreshUsing() 이 '{$globPattern}' 을 포함하지 않습니다. "
                .'이 TestCase 가 프로세스에서 먼저 실행되면 다른 확장의 테이블이 생성되지 않아 '
                .'스위트를 묶어 돌릴 때 "Base table or view not found" 가 발생합니다.'
            );
        }
    }

    /**
     * 자기 확장 경로만 하드코딩한 잔재가 없다.
     *
     * glob 을 도입하면서 옛 하드코딩 줄을 지우지 않으면 경로가 중복될 뿐 결함은 남지 않지만,
     * glob 없이 하드코딩만 있는 상태로 되돌아가는 회귀는 위 단언이 잡는다. 이 단언은 그
     * 되돌림이 "자기 확장만 나열" 형태로 재등장하는 것을 추가로 막는다.
     *
     * @param  string  $path  TestCase 파일 절대 경로
     */
    #[DataProvider('extensionTestCaseProvider')]
    public function test_extension_test_case_does_not_pin_only_its_own_migrations(string $path): void
    {
        $source = file_get_contents($path);
        $relative = str_replace(self::repositoryRoot().DIRECTORY_SEPARATOR, '', $path);

        // 예: 'plugins/sirsoft-ckeditor5/database/migrations' 처럼 와일드카드 없는 단일 확장 경로
        $pinned = preg_match(
            "#['\"](modules|plugins)/(?!_bundled/\*)[a-z0-9_-]+/database/migrations['\"]#i",
            $source
        );

        $this->assertSame(
            0,
            $pinned,
            "{$relative} 이 특정 확장의 마이그레이션 경로를 직접 지정하고 있습니다. "
            .'번들 확장 전체를 glob 으로 포함하세요.'
        );
    }
}
