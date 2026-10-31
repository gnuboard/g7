<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 파일 나열이 한 곳에서 도출되고 `glob` 을 쓰지 않는다.
 *
 * `glob` 이 `disable_functions` 에 든 호스팅에서 PHP 8 은 그 함수를 함수 테이블에서
 * 제거하므로 호출이 `Error`(치명)가 된다. 확장 업그레이드 스텝 발견과 레이아웃 확장
 * 로딩이 그 자리에서 죽고, 그 환경은 이 안이 대상으로 삼는 배치다.
 *
 * 종전에는 같은 루프가 네 벌이었고 그중 **스텝 안쪽 로더 하나만** `scandir` 로 옮겨져
 * 있었다 — 옮긴 쪽이 읽히기 전에 옮기지 않은 발견기가 죽으므로 그 회피는 도달조차
 * 하지 못했다. 「사본 중 하나만 고쳤다」가 오류도 경고도 없이 성립하던 형태라, 나열
 * 지점이 다시 갈리는 것을 이 검사가 막는다 (11차 G3 라운드).
 *
 * @scenario hosting_restriction=disabled_glob, extension_discovery=upgrade_steps
 */
class ExtensionDirectoryListingTest extends TestCase
{
    /** 확장 파일을 나열하는 코어 지점 전부. */
    private const LISTING_SOURCES = [
        'app/Extension/AbstractModule.php',
        'app/Extension/AbstractPlugin.php',
        'app/Extension/AbstractUpgradeStep.php',
    ];

    /**
     * 확장 발견과 로딩 소스 파일들이 glob() 을 직접 부르지 않는지 확인한다.
     *
     * @effects extension_file_listing_survives_disabled_glob
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_listing_sources_do_not_call_glob(): void
    {
        foreach (self::LISTING_SOURCES as $relative) {
            $body = (string) file_get_contents(base_path($relative));

            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z_])glob\s*\(/',
                $body,
                $relative.' 가 `glob()` 을 부른다 — 그 함수가 차단된 호스팅에서 PHP 8 은 '
                .'`Error` 를 내고 확장 발견·로딩이 그 자리에서 죽는다. '
                .'`ExtensionManager::listDirectoryFiles()` 를 쓴다'
            );
        }
    }

    /**
     * 확장 나열 소스 파일들이 모두 공유 헬퍼 ExtensionManager::listDirectoryFiles 를 부르는지 확인한다.
     *
     * @scenario hosting_restriction=disabled_glob, extension_discovery=upgrade_steps
     *
     * @effects extension_file_listing_survives_disabled_glob
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_listing_sources_route_through_the_shared_helper(): void
    {
        foreach (self::LISTING_SOURCES as $relative) {
            $this->assertStringContainsString(
                'ExtensionManager::listDirectoryFiles(',
                (string) file_get_contents(base_path($relative)),
                $relative.' 가 공유 나열 헬퍼를 쓰지 않는다 — 사본이 갈리면 한쪽만 고쳐진 채 '
                .'남고, 그 상태는 차단 호스팅에서만 드러난다'
            );
        }
    }

    /**
     * 파일 나열 헬퍼가 접두와 확장자로 거르고 점으로 시작하는 항목을 빼며 이름순으로 정렬해 glob 판본과 같은 결과를 내는지 확인한다.
     *
     * @effects extension_file_listing_survives_disabled_glob
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_helper_sorts_filters_and_skips_dot_entries(): void
    {
        $dir = storage_path('framework/testing/ext-listing');
        @mkdir($dir, 0755, true);

        $written = [
            'Upgrade_7_2_0.php',
            'Upgrade_7_10_0.php',
            'Upgrade_7_1_0.php',
            'NotAStep.php',
            'Upgrade_7_3_0.txt',
            // `glob('Upgrade_*.php')` 가 걸러 주던 것들 — 정렬상 가장 앞으로 온다.
            '._Upgrade_7_0_0.php',
            '.#Upgrade_7_0_0.php',
        ];

        foreach ($written as $name) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.$name, '<?php');
        }

        try {
            $found = array_map(
                static fn (string $p): string => basename($p),
                ExtensionManager::listDirectoryFiles($dir, '.php', 'Upgrade_')
            );

            $this->assertSame(
                ['Upgrade_7_10_0.php', 'Upgrade_7_1_0.php', 'Upgrade_7_2_0.php'],
                $found,
                '접두·확장자 필터 또는 이름 정렬이 `glob` 판본과 다르다'
            );

            // 확장자만 거는 형태(레이아웃 확장 JSON)도 같은 규칙을 쓴다.
            $this->assertSame(
                ['NotAStep.php', 'Upgrade_7_10_0.php', 'Upgrade_7_1_0.php', 'Upgrade_7_2_0.php'],
                array_map(
                    static fn (string $p): string => basename($p),
                    ExtensionManager::listDirectoryFiles($dir, '.php')
                )
            );
        } finally {
            foreach ($written as $name) {
                @unlink($dir.DIRECTORY_SEPARATOR.$name);
            }
            @rmdir($dir);
        }
    }

    /**
     * 없는 디렉토리를 넘기면 파일 나열 헬퍼가 예외 없이 빈 배열을 돌려주는지 확인한다.
     *
     * @effects extension_file_listing_survives_disabled_glob
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_helper_returns_empty_for_a_missing_directory(): void
    {
        $this->assertSame([], ExtensionManager::listDirectoryFiles(
            storage_path('framework/testing/does-not-exist-'.__FUNCTION__),
            '.php'
        ));
    }

    /**
     * 디렉토리 판본이 `glob('*', GLOB_ONLYDIR)` 와 같은 결과를 낸다.
     *
     * 11차가 파일 나열을 한 곳으로 접었지만 **디렉토리** 나열은 그대로 `glob` 이었고,
     * 그 지점들(템플릿 오버라이드 순회 · `_pending` 잔여물 정리 · 손상 백업 정리)은
     * 확장 설치 흐름에서 먼저 실행된다 — 먼저 죽는 쪽이 남아 있으면 옮긴 회피는
     * 도달조차 하지 못한다 (12차 G3 라운드).
     *
     * @scenario hosting_restriction=disabled_glob, extension_discovery=upgrade_steps
     *
     * @effects extension_directory_listing_matches_glob_onlydir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_directory_helper_matches_glob_only_dir(): void
    {
        $dir = storage_path('framework/testing/listdirs-'.bin2hex(random_bytes(4)));
        $made = ['sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-page', '.hidden'];

        foreach ($made as $name) {
            @mkdir($dir.DIRECTORY_SEPARATOR.$name, 0777, true);
        }
        @file_put_contents($dir.DIRECTORY_SEPARATOR.'not-a-directory.json', '{}');

        try {
            $this->assertSame(
                ['sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-page'],
                array_map(
                    static fn (string $p): string => basename($p),
                    ExtensionManager::listDirectories($dir)
                ),
                '파일은 제외하고 디렉토리만, 이름 오름차순으로 나열해야 한다'
            );

            // `.` 로 시작하는 항목은 배제한다 — `glob('*')` 이 걸러 주던 성질이다.
            $this->assertNotContains(
                '.hidden',
                array_map(static fn (string $p): string => basename($p), ExtensionManager::listDirectories($dir))
            );

            // 접두사 필터 — `_pending` 잔여물 정리(`{id}_updating_*`)가 쓰는 형태다.
            $this->assertSame(
                ['sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-page'],
                array_map(
                    static fn (string $p): string => basename($p),
                    ExtensionManager::listDirectories($dir, 'sirsoft-')
                )
            );

            $this->assertSame([], ExtensionManager::listDirectories($dir, 'nomatch-'));
        } finally {
            foreach ($made as $name) {
                @rmdir($dir.DIRECTORY_SEPARATOR.$name);
            }
            @unlink($dir.DIRECTORY_SEPARATOR.'not-a-directory.json');
            @rmdir($dir);
        }
    }

    /**
     * 없는 디렉토리를 넘기면 디렉토리 나열 헬퍼가 예외 없이 빈 배열을 돌려주는지 확인한다.
     *
     * @effects extension_directory_listing_matches_glob_onlydir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_directory_helper_returns_empty_for_a_missing_directory(): void
    {
        $this->assertSame([], ExtensionManager::listDirectories(
            storage_path('framework/testing/does-not-exist-'.__FUNCTION__)
        ));
    }
}
