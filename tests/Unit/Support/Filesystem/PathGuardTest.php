<?php

namespace Tests\Unit\Support\Filesystem;

use App\Support\Filesystem\PathGuard;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 경로 정리·포함 판정 헬퍼가 OS 와 무관하게 같은 결과를 내는지 봉인한다 (#144).
 *
 * 입력은 전부 문자열이라 Windows 개발 PC 에서도 리눅스 절대경로(`/srv/…`)·UNC 경로 결과를 그대로 잰다 —
 * 수동 정규화가 리눅스에서만 선행 `/` 를 잃던 결함은 Windows 에서 돌던 기존 테스트로는 보이지 않았다.
 *
 * 트러블슈팅 `[case:backend-55]` 의 회귀 그물이다.
 */
class PathGuardTest extends TestCase
{
    /**
     * 경로 형태별 입력과 정리 결과를 제공합니다.
     *
     * @return array<string, array{0: string, 1: string}> 데이터셋 이름 → [입력, 기대 결과]
     */
    public static function canonicalizeProvider(): array
    {
        return [
            '리눅스 절대경로 — 선행 / 보존' => ['/srv/g7/templates/x/layouts/a/../b.json', '/srv/g7/templates/x/layouts/b.json'],
            '드라이브 경로' => ['C:\\a\\..\\b', 'C:/b'],
            '소문자 드라이브 — 대문자로' => ['c:\\a\\b', 'C:/a/b'],
            'UNC — 앞 두 구분자 보존' => ['\\\\srv\\share\\a', '//srv/share/a'],
            '드라이브 없는 절대경로' => ['\\Users\\x\\..\\y', '/Users/y'],
            '중복 구분자·현재 폴더' => ['layouts/./x//y', 'layouts/x/y'],
            '루트 위로 못 올라감' => ['/a/../../b', '/b'],
        ];
    }

    /**
     * 정리 결과가 리눅스·Windows·UNC 루트를 지킨다.
     *
     * @scenario case=canonicalize_root
     *
     * @effects path_canonicalization_preserves_root_on_every_os
     *
     * @param  string  $input  입력 경로
     * @param  string  $expected  기대 결과
     * @return void 반환값 없음
     */
    #[DataProvider('canonicalizeProvider')]
    public function test_canonicalize_preserves_root(string $input, string $expected): void
    {
        $this->assertSame($expected, PathGuard::canonicalize($input));
    }

    /**
     * 기준 폴더·판정 경로·기대 포함 여부를 제공합니다.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}> 데이터셋 이름 → [기준, 경로, 기대]
     */
    public static function isWithinProvider(): array
    {
        return [
            '같은 경로' => ['/srv/x/layouts', '/srv/x/layouts', true],
            '하위' => ['/srv/x/layouts', '/srv/x/layouts/partials/a.json', true],
            '이름이 비슷한 형제 폴더' => ['/srv/x/layouts', '/srv/x/layouts-evil/a.json', false],
            '.. 로 빠져나감' => ['/srv/x/layouts', '/srv/x/layouts/../outside.json', false],
            '구분자 혼용' => ['C:\\x\\layouts', 'C:/x/layouts/a.json', true],
            '드라이브 대소문자 차이' => ['C:/x/layouts', 'c:/x/layouts/a.json', true],
            'Windows 형제 폴더' => ['C:\\x\\layouts', 'C:\\x\\layouts-evil\\a.json', false],
        ];
    }

    /**
     * 포함 판정이 폴더 경계를 지킨다 — 이름이 비슷한 형제 폴더는 밖이다.
     *
     * @scenario case=containment_boundary
     *
     * @effects path_containment_respects_directory_boundary
     *
     * @param  string  $base  기준 폴더
     * @param  string  $path  판정할 경로
     * @param  bool  $expected  기대 포함 여부
     * @return void 반환값 없음
     */
    #[DataProvider('isWithinProvider')]
    public function test_is_within_respects_directory_boundary(string $base, string $path, bool $expected): void
    {
        $this->assertSame($expected, PathGuard::isWithin($base, $path));
    }

    /**
     * 없는 파일은 가장 가까운 실재 상위 폴더의 실제 경로에 나머지를 이어 붙인다.
     *
     * 실재 기준 폴더(`realpath`)와 아직 없는 후보 경로가 같은 모양이어야 포함 판정이 어긋나지 않는다.
     *
     * @scenario case=resolve_missing_file
     *
     * @effects path_resolution_matches_realpath_of_existing_ancestor
     *
     * @return void 반환값 없음
     */
    public function test_resolve_uses_realpath_of_nearest_existing_ancestor(): void
    {
        $root = storage_path('framework/testing/path_guard_'.uniqid());
        File::makeDirectory($root.'/layouts/auth', 0777, true);
        File::put($root.'/layouts/auth/a.json', '{}');

        try {
            $realRoot = PathGuard::canonicalize((string) realpath($root));

            $this->assertSame($realRoot.'/layouts/auth/a.json', PathGuard::resolve($root.'/layouts/auth/a.json'));
            $this->assertSame($realRoot.'/layouts/auth/none/b.g7.html', PathGuard::resolve($root.'/layouts/auth/none/b.g7.html'));
            $this->assertSame($realRoot.'/outside.json', PathGuard::resolve($root.'/layouts/auth/../../outside.json'));
            $this->assertTrue(PathGuard::isWithin(PathGuard::resolve($root.'/layouts'), PathGuard::resolve($root.'/layouts/x/../y.json')));
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * 없는 UNC 경로는 상위 폴더를 거슬러 찾을 때도 UNC 루트(`//서버/공유`)를 지킨다.
     *
     * 네트워크 공유에 설치한 Windows 사이트에서 없는 조각이 드라이브 경로(`C://서버/…`)로 바뀌면 layouts 밖으로 판정돼
     * JSON 문서의 누락 조각 관용(#712)이 작동하지 않는다. 공유 루트 위로는 올라가지 않는다.
     *
     * @scenario case=resolve_missing_unc_file
     *
     * @effects unc_root_kept_when_resolving_missing_file
     *
     * @return void 반환값 없음
     */
    public function test_resolve_keeps_unc_root_for_missing_file(): void
    {
        $missing = '\\\\g7-no-such-host\\share\\templates\\acme\\layouts\\partials\\x.json';

        $this->assertSame('//g7-no-such-host/share/templates/acme/layouts/partials/x.json', PathGuard::resolve($missing));
        $this->assertTrue(PathGuard::isWithin('\\\\g7-no-such-host\\share\\templates\\acme\\layouts', PathGuard::resolve($missing)));
    }
}
