<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 웹 노출 자가 진단(B-4 — includes/exposure-check.php) 단위 테스트.
 *
 * 프로브 대상 산출의 세 계약을 고정한다 — ① 비밀 무유출(R3: `.env` 원문은 어떤 응답에도
 * 싣지 않는다) ② 디스크에 없는 파일은 대상에서 제외(부재 파일의 404 는 보호를 증명하지
 * 않는다) ③ storage sentinel 은 검사 전용 난수이며 정리 헬퍼가 지운다. symlink 실측은
 * 환경 의존이므로 반환 형태(bool|null)와 잔여물 0 만 계약이다.
 *
 * 효과 요약(마커 아님 — 평문): 프로브 대상의 비밀 무유출과 부재 파일 제외, sentinel 의 난수성과 정리, 링크 실측 형태,
 * 재지 못한 계열 보고, 진입 스윕과 정리 보고, 양성 대조, sentinel 대표성, 링크 실측 상한의 클라이언트 한정.
 * 표식은 각 테스트 메서드에 있다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerWebExposureCheckTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    /**
     * 번역 대역과 임시 BASE_PATH 를 갖추고, 함수 가용 판정 헬퍼 다음에 노출 점검 파일을 불러온다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-exposure-'.bin2hex(random_bytes(4)));
        }

        // installerHasFunction(SSoT) 의 출처 — exposure-check 가 이 판정을 쓴다 (R4 순서).
        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/exposure-check.php';
    }

    /**
     * BASE_PATH 가 시스템 임시 폴더 아래가 아니면 건너뛰고, 그 폴더가 없으면 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // R7 — temp 밖 BASE_PATH 로는 실 저장소 파일을 읽게 된다.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->markTestSkipped('BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerWebExposureCheckTest');
        }

        if (! is_dir(BASE_PATH)) {
            mkdir(BASE_PATH, 0755, true);
        }
    }

    /**
     * 대표 프로젝트 루트 픽스처를 만든다.
     *
     * @return void 반환값 없음
     */
    private function seedProjectFixture(): void
    {
        file_put_contents(BASE_PATH.'/.env.example', "# comment\nAPP_NAME=G7\nAPP_KEY=\nDB_PASSWORD=\n");
        file_put_contents(BASE_PATH.'/.env', "APP_NAME=G7\nDB_PASSWORD=super-secret-value-091\n");
        file_put_contents(BASE_PATH.'/composer.json', json_encode(['name' => 'gnuboard/g7', 'require' => ['php' => '^8.2']]));
    }

    /**
     * 저장소 폴더와 의존성 폴더가 디스크에 있으면 그 두 계열이 프로브 대상에 들어가고, 의존성 계열 마커가 오토로더 이름으로 잡히는지 확인한다.
     *
     * @scenario file_presence=present
     *
     * @effects exposure_probe_targets_include_repository_and_dependency_families
     *
     * @return void 반환값 없음
     */
    public function test_repository_and_dependency_families_are_probed_when_present(): void
    {
        // 이 두 계열은 **픽스처에 없으면 구조적으로 잴 수 없다.** 루트 `.htaccess` 의 숨김
        // 파일 차단은 파일 **이름**만 보므로 `/.git/config`(basename `config`)를 막지 못하고,
        // rewrite 부재 방어선은 `mod_alias` 안에 있어 그 모듈이 없는 서버에서 통째로 무효다.
        // 두 블록을 지워도 초록이던 자리라 픽스처를 함께 심는다.
        $this->seedProjectFixture();

        mkdir(BASE_PATH.'/.git', 0755, true);
        file_put_contents(BASE_PATH.'/.git/HEAD', 'ref: refs/heads/main
');
        mkdir(BASE_PATH.'/vendor', 0755, true);
        file_put_contents(BASE_PATH.'/vendor/autoload.php', '<?php
// ComposerAutoloaderInit stub
');

        $byPath = array_column(installerExposureProbeTargets(BASE_PATH), null, 'path');

        $this->assertArrayHasKey('/.git/HEAD', $byPath, '저장소를 그대로 배포한 동선에서 .git/ 은 반드시 재야 한다');
        $this->assertArrayHasKey('/vendor/autoload.php', $byPath, '의존성 원본 계열도 직접 재야 한다');
        $this->assertSame('ComposerAutoloaderInit', $byPath['/vendor/autoload.php']['marker']);
    }

    /**
     * 환경 견본, 패키지 정의, 저장소 sentinel 세 계열이 대상에 오르고, 각 마커가 디스크 파일에서 나오며 환경 계열은 키 이름까지만 싣는지 확인한다.
     *
     * @scenario target_file=env_example, file_presence=present, probe_target=storage_sentinel
     *
     * @effects exposure_probe_targets_carry_no_secrets, exposure_sentinel_is_random_and_cleaned_up
     *
     * @return void 반환값 없음
     */
    public function test_targets_cover_three_exposure_classes_with_disk_backed_markers(): void
    {
        $this->seedProjectFixture();

        $targets = installerExposureProbeTargets(BASE_PATH);
        $byPath = array_column($targets, null, 'path');

        $this->assertArrayHasKey('/.env.example', $byPath);
        $this->assertSame('APP_NAME=', $byPath['/.env.example']['marker'], '.env 계열 마커는 키 이름까지만 싣는다');

        $this->assertArrayHasKey('/composer.json', $byPath);
        $this->assertSame('gnuboard/g7', $byPath['/composer.json']['marker']);

        $sentinel = $this->sentinelTarget($targets);
        $this->assertNotNull($sentinel, 'storage 계열 대상이 있어야 한다');
        $this->assertStringStartsWith('G7-EXPOSURE-PROBE-', $sentinel['marker']);
        $this->assertStringContainsString(
            $sentinel['marker'],
            (string) file_get_contents(BASE_PATH.$sentinel['path']),
            'sentinel 파일 내용과 마커가 일치해야 브라우저가 원문 서빙을 판정할 수 있다'
        );
    }

    /**
     * sentinel 파일명이 **검사마다 다르다** (3차 라운드 H2).
     *
     * 이름을 고정하고 마커만 새로 만들면, 탭 두 개나 [다시 검사] 중복 호출이 겹칠 때 먼저
     * 받은 화면의 마커가 무효가 된다. 그 화면이 프로브하면 파일은 **실제로 서빙되는데도**
     * (= 노출) 마커가 달라 `exposed:false, judged:true` — 즉 「보호 확인됨」이다. D3 의
     * 판정이 정확히 반대로 뒤집힌다. 고유 이름이면 대상이 서로 달라 간섭이 성립하지 않는다.
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_sentinel_is_random_and_cleaned_up
     *
     * @return void 반환값 없음
     */
    public function test_sentinel_path_is_unique_per_call_so_concurrent_checks_cannot_alias(): void
    {
        $first = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $second = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first['path'],
            $second['path'],
            'sentinel 경로가 같으면 뒤 호출이 앞 호출의 대상을 덮어써 노출을 「보호 확인됨」으로 오판한다'
        );

        // 앞 호출의 대상이 **그대로 살아 있어야** 그 화면의 판정이 유효하다.
        $this->assertStringContainsString(
            $first['marker'],
            (string) file_get_contents(BASE_PATH.$first['path']),
            '뒤 호출이 앞 호출의 sentinel 을 무효화하면 안 된다'
        );
    }

    /**
     * 준비에 실패한 계열은 목록에서 조용히 빠지지 않고 `unmeasured` 로 보고된다 (3차 H3).
     *
     * 종전에는 sentinel 을 만들지 못하면 그 대상만 사라졌고, 남은 두 계열이 403 이면 화면이
     * 초록 「보호 확인됨」이 됐다 — storage 계열은 한 번도 재지 않았는데도. 재지 못한 축은
     * 「안전」이 아니라 판정 불능(D21)이다.
     *
     * @scenario probe_target=storage_sentinel, exposure_unknown_cause=unmeasured_only
     *
     * @effects exposure_unmeasured_family_blocks_safe_verdict
     *
     * @return void 반환값 없음
     */
    public function test_unpreparable_family_is_reported_as_unmeasured_not_silently_dropped(): void
    {
        $this->seedProjectFixture();

        // storage/installer 자리를 **파일**로 막아 디렉토리 생성·쓰기를 불가능하게 만든다
        // (권한 조작은 Windows 에서 재현되지 않는다).
        @mkdir(BASE_PATH.'/storage', 0777, true);
        file_put_contents(BASE_PATH.'/storage/installer', 'not a directory');

        $plan = installerExposureProbePlan(BASE_PATH);

        $this->assertNull($this->sentinelTarget($plan['targets']), '준비 실패한 계열은 프로브 대상이 될 수 없다');
        $this->assertContains(
            'storage/',
            array_column($plan['unmeasured'], 'label'),
            '재지 못한 계열은 unmeasured 로 드러나야 한다 — 조용히 빠지면 화면이 안전으로 읽는다'
        );

        // 이 픽스처에는 인스톨러 자산이 없으므로 대조 계열도 함께 승격된다(7차 라운드).
        // 그 사실이 이 단언에 들어오는 것은 의도된 결과다 — 종전에는 대조만 조용히
        // 빠져 「대조할 것이 없다」로 읽혔다.
        $this->assertContains('install/assets', array_column($plan['unmeasured'], 'label'));
    }

    /**
     * sentinel 권한이 **실제 storage 콘텐츠와 같은 등급**이다 (8차 P4-1②).
     *
     * 종전 계약은 형제 임시 파일(runtime.php)과 같은 `0600` 이었다. 그 규약을 여기에 그대로
     * 적용하면 대표성이 깨진다 — PHP-FPM 이 사이트 계정으로 돌고 정적 파일은 웹서버 계정이
     * 서빙하는 흔한 배치에서 **웹서버가 읽지 못해 403** 이 되는데, 화면은 403 을
     * 「보호됨(judged)」으로 읽는다. 실제 storage 파일은 기본 권한이라 그대로 서빙되는
     * 서버가 storage 계열만 초록이 되는 것이다. 0600 을 지키면서 그 403 만 판정 불능으로
     * 강등하는 길도 있으나, 그러면 정상 서버에서도 이 계열이 상시 unmeasured 가 되어 노출
     * 자동 차단(D3)이 언제나 수동 확인(D21)으로 강등된다 — 측정 자체가 사라진다.
     *
     * umask 는 환경마다 다르므로 상수와 비교하지 않는다. 같은 디렉토리에 같은 방식으로
     * 쓴 대조 파일과 **같은 권한**인지만 본다.
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_sentinel_represents_real_storage_content
     *
     * @return void 반환값 없음
     */
    public function test_sentinel_permission_matches_ordinary_storage_content(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX 퍼미션 비트가 없는 플랫폼이다');
        }

        $sentinel = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $this->assertNotNull($sentinel);

        $control = installerExposureProbeDir(BASE_PATH).'/perm-control-'.bin2hex(random_bytes(4));
        file_put_contents($control, "control\n");

        try {
            $this->assertSame(
                fileperms($control) & 0777,
                fileperms(BASE_PATH.$sentinel['path']) & 0777,
                'sentinel 이 실제 storage 파일보다 좁은 권한이면 웹서버가 못 읽어 403 이 되고, 화면은 그 403 을 「보호됨」으로 읽는다'
            );
        } finally {
            @unlink($control);
        }
    }

    /**
     * sentinel 이름에 **확장자가 없다** (8차 P4-1①).
     *
     * 이 계열이 재려는 것은 rewrite 부재 방어선의 **경로(디렉토리) 차단**이 살아 있는가다
     * (`vendor/` 를 함께 재는 사유와 같다 — 그 차단은 `mod_alias` 안에 있어 그 모듈이 없으면
     * 통째로 무효다). 이름이 `….txt` 면 요청이 그보다 앞선 **확장자 규칙**에 먼저 걸려,
     * 403 이 나와도 경로 차단의 생존을 증명하지 못한다. 정작 그 규칙만이 지키는 실물은
     * 확장자가 없거나(`storage/framework/sessions/<40hex>`) 확장자 목록 밖
     * (`storage/app/public/*.png`)이다.
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_sentinel_represents_real_storage_content
     *
     * @return void 반환값 없음
     */
    public function test_sentinel_name_carries_no_extension_so_it_measures_the_path_rule(): void
    {
        $sentinel = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $this->assertNotNull($sentinel);

        $basename = basename($sentinel['path']);

        $this->assertStringNotContainsString(
            '.',
            $basename,
            'sentinel 이름에 확장자가 있으면 확장자 규칙이 먼저 막아 경로 차단의 생존을 재지 못한다'
        );
    }

    /**
     * `vendor/` 가 아직 없으면 **재지 못한 계열**로 보고된다 (8차 P4-2).
     *
     * 무CLI 배포 동선(`vendor-bundle.zip`)에서는 이 화면 시점에 `vendor/` 가 아직 풀리지
     * 않았다 — 이 점검이 대상으로 하는 바로 그 호스팅에서 vendor 계열은 **언제나** 재지
     * 못하는데, 종전에는 화면에 한 줄도 뜨지 않고 조용히 빠졌다. 배포 동선에 따라 있을 수도
     * 없을 수도 있는 `.git/` 과 달리 이 계열은 배포본에 언제나 실린다.
     *
     * @scenario exposure_unknown_cause=unmeasured_only
     *
     * @effects exposure_absent_vendor_family_is_reported_as_unmeasured
     *
     * @return void 반환값 없음
     */
    public function test_absent_vendor_family_is_reported_as_unmeasured_not_silently_dropped(): void
    {
        $this->seedProjectFixture();

        $plan = installerExposureProbePlan(BASE_PATH);

        $byLabel = array_column($plan['unmeasured'], null, 'label');

        $this->assertArrayHasKey(
            'vendor/',
            $byLabel,
            'vendor 계열이 목록에서 조용히 빠졌다 — 재지 않은 축이 「보호 확인됨」에 묻힌다'
        );
        $this->assertSame(
            'not_deployed',
            $byLabel['vendor/']['reason'],
            '「원래 없다」가 아니라 「아직 배포되지 않았다」다 — 사유가 다르면 운영자가 할 일도 다르다'
        );
        $this->assertNotContains('/vendor/autoload.php', array_column($plan['targets'], 'path'));
    }

    /**
     * 프로브 대상 중 storage 계열(sentinel)을 골라낸다.
     *
     * 경로가 매 호출 고유하므로 고정 경로로 찾을 수 없다 — 접두사로 고른다.
     *
     * @param  array<int, array{path: string, marker: string, label: string}>  $targets  프로브 대상 목록
     * @return array{path: string, marker: string, label: string}|null sentinel 대상
     */
    private function sentinelTarget(array $targets): ?array
    {
        foreach ($targets as $target) {
            if (str_starts_with($target['path'], '/storage/installer/'.INSTALLER_EXPOSURE_PROBE_PREFIX)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * 진입 스윕은 **오래된** 잔여물만 걷는다 (4차 G3).
     *
     * 이 계열은 요청마다 파일을 만들므로 무인증 설치 창에서 무한히 쌓일 수 있다. 그래서 점검
     * 진입 시 정리하는데, **무조건 전부** 걷으면 탭 두 개나 [다시 검사] 중복 호출에서 뒤 호출이
     * 앞 호출의 대상을 지운다 — 앞 화면의 프로브가 404 를 받아 「보호 확인됨」이 되고, 그 파일은
     * 실제로 서빙되고 있었을 수도 있다. 3차 라운드가 고유 파일명으로 닫은 D3 판정 반전이
     * 정리 쪽으로 되살아나는 자리다.
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_entry_sweep_spares_concurrent_checks
     *
     * @return void 반환값 없음
     */
    public function test_entry_sweep_removes_only_stale_residue_so_concurrent_checks_survive(): void
    {
        $dir = BASE_PATH.'/storage/installer';
        @mkdir($dir, 0777, true);

        $fresh = $dir.'/'.INSTALLER_EXPOSURE_PROBE_PREFIX.'fresh.txt';
        $stale = $dir.'/'.INSTALLER_EXPOSURE_PROBE_PREFIX.'stale.txt';
        file_put_contents($fresh, 'fresh');
        file_put_contents($stale, 'stale');
        touch($stale, time() - 3600);

        $sweep = installerSweepStaleExposureProbe(BASE_PATH);

        // 반환은 형제 정리기(`installerCleanupExposureProbe`)와 같은 모양이다 — 지운 수만
        // 돌려주면 「지울 것이 없었다」와 「열거를 못 해 쓸지 못했다」가 둘 다 0 이라 구분되지 않는다.
        $this->assertSame(1, $sweep['removed']);
        $this->assertTrue($sweep['listed'], '열거에 성공했는데 실패로 보고했다');
        $this->assertFileDoesNotExist($stale, '오래된 잔여물은 걷어야 한다 — 안 걷으면 호출 수만큼 쌓인다');
        $this->assertFileExists($fresh, '진행 중일 수 있는 대상을 지우면 그 화면의 판정이 뒤집힌다');
    }

    /**
     * `glob` 이 막힌 환경에서 「지울 것이 없었다」와 「쓸지 못했다」가 구분된다 (4차 G3).
     *
     * 둘을 같은 `removed:0` 으로 뭉뚱그리면 정리가 통째로 무동작인 호스팅에서도 로그에
     * 「정리 완료 (0개)」가 남아, 운영자가 잔여물이 없다고 읽는다.
     *
     * @effects exposure_cleanup_reports_whether_it_could_sweep
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_reports_whether_it_could_sweep_at_all(): void
    {
        @mkdir(BASE_PATH.'/storage/installer', 0777, true);

        $result = installerCleanupExposureProbe(BASE_PATH);

        $this->assertArrayHasKey('swept', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertTrue($result['swept'], 'glob 이 있는 환경에서는 쓸었다고 보고해야 한다');
        $this->assertNull($result['reason']);
    }

    /**
     * 실제 환경 파일의 비밀 값이 프로브 대상 직렬화 결과 어디에도 실리지 않는지 확인한다.
     *
     * @scenario target_file=env_example, secret_channel=env_real_absent
     *
     * @effects exposure_probe_targets_carry_no_secrets
     *
     * @return void 반환값 없음
     */
    public function test_targets_never_carry_real_env_secrets(): void
    {
        $this->seedProjectFixture();

        $payload = json_encode(installerExposureProbeTargets(BASE_PATH));

        $this->assertStringNotContainsString('super-secret-value-091', (string) $payload, '실제 .env 내용이 프로브 대상에 실리면 R3 위반이다');
    }

    /**
     * 환경 견본과 패키지 정의가 없는 트리에서는 그 둘이 프로브 대상에서 빠지는지 확인한다.
     *
     * @scenario target_file=env_example
     *
     * @effects exposure_probe_targets_skip_missing_files
     *
     * @return void 반환값 없음
     */
    public function test_missing_files_are_skipped_not_probed(): void
    {
        // .env.example·composer.json 이 없는 트리 — 부재 파일의 404 는 보호를 증명하지 않으므로
        // 대상에서 빠져야 한다 (sentinel 만 남는다).
        $targets = installerExposureProbeTargets(BASE_PATH);
        $paths = array_column($targets, 'path');

        $this->assertNotContains('/.env.example', $paths);
        $this->assertNotContains('/composer.json', $paths);
    }

    /**
     * sentinel 마커가 호출마다 새 난수로 다시 만들어지는지 확인한다.
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_sentinel_is_random_and_cleaned_up
     *
     * @return void 반환값 없음
     */
    public function test_sentinel_regenerates_random_token_per_call(): void
    {
        $firstToken = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH))['marker'] ?? null;
        $secondToken = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH))['marker'] ?? null;

        $this->assertNotNull($firstToken);
        $this->assertNotNull($secondToken);
        $this->assertNotSame($firstToken, $secondToken, 'sentinel 토큰은 검사마다 재생성된다 — 고정 값은 캐시된 옛 응답과 구분되지 않는다');
    }

    /**
     * 정리는 **접두사 전체**를 걷는다 — 이름이 고유해졌으므로 단일 파일명 삭제로는 부족하고,
     * 링크 실측 임시 파일도 같은 디렉토리에 남을 수 있다 (3차 L4·L7).
     *
     * @scenario probe_target=storage_sentinel
     *
     * @effects exposure_sentinel_is_random_and_cleaned_up
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_removes_every_probe_residue(): void
    {
        $first = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $second = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertFileExists(BASE_PATH.$first['path']);
        $this->assertFileExists(BASE_PATH.$second['path']);

        // 링크 실측 잔여물도 같은 스윕이 걷는다 (정상 경로는 스스로 지우지만 중단되면 남는다).
        $orphan = BASE_PATH.'/storage/installer/'.INSTALLER_SYMLINK_PROBE_PREFIX.'target-orphan.txt';
        file_put_contents($orphan, 'leftover');

        // 레이트리밋 카운터도 설치 중에만 필요한 파일이다. 형제(`installer-state.json`)는
        // finalize 가 지우는데 이것만 정리 주체가 없어 설치가 끝난 사이트에 영구 잔존했고,
        // `.gitignore` 에도 없어 개발 트리에서는 `git status` 에 그대로 떴다 (5차 G3).
        $ratePath = installerProbeRateLimitStatePath();
        file_put_contents($ratePath, json_encode(['probe_exposure_sentinel' => ['started_at' => time(), 'count' => 1]]));

        $result = installerCleanupExposureProbe(BASE_PATH);

        $this->assertFileDoesNotExist(BASE_PATH.$first['path'], '정리는 앞선 검사의 sentinel 까지 걷는다');
        $this->assertFileDoesNotExist(BASE_PATH.$second['path']);
        $this->assertFileDoesNotExist($orphan, '링크 실측 잔여물도 같은 스윕 대상이다');
        $this->assertFileDoesNotExist($ratePath, '레이트리밋 카운터도 설치 산출물이다 — 같은 정리 지점이 걷는다');
        $this->assertSame(4, $result['removed'], 'sentinel 2 + 링크 잔여물 1 + 레이트리밋 카운터 1');
        $this->assertSame(0, $result['failed']);
    }

    /**
     * 설치 마무리가 **아닌** 정리는 진행 중인 점검을 건드리지 않는다.
     *
     * 중단(abort)·초기화(reset)는 요구사항 화면이 열려 있는 동안에도 부를 수 있는
     * **무인증 POST** 다. 그 정리가 갓 만들어진 sentinel 을 지우면 그 화면의 프로브가
     * 404 를 받아 「그 파일은 막혀 있다」로 확정한다 — 실제로는 서빙되고 있었을 수 있고,
     * 오류도 경고도 남지 않는다. 상한 상태를 지우면 요청 하나로 상한이 초기화된다.
     *
     * @scenario exposure_control=finalize
     *
     * @effects non_finalizing_cleanup_preserves_in_flight_probes
     *
     * @return void 반환값 없음
     */
    public function test_non_finalizing_cleanup_preserves_in_flight_probes_and_the_rate_limit(): void
    {
        $inFlight = $this->sentinelTarget(installerExposureProbeTargets(BASE_PATH));
        $this->assertNotNull($inFlight);
        $this->assertFileExists(BASE_PATH.$inFlight['path']);

        $ratePath = installerProbeRateLimitStatePath();
        file_put_contents($ratePath, json_encode(['probe_exposure_sentinel' => ['started_at' => time(), 'count' => 9]]));

        $result = installerCleanupExposureProbe(BASE_PATH, finalizing: false);

        $this->assertFileExists(
            BASE_PATH.$inFlight['path'],
            '중단·초기화가 진행 중인 점검의 sentinel 을 지우면 그 화면이 404 를 보호로 읽는다'
        );
        $this->assertFileExists(
            $ratePath,
            '무인증 경로가 상한 상태를 지우면 요청 하나로 상한이 초기화된다'
        );
        $this->assertSame(0, $result['removed']);

        @unlink(BASE_PATH.$inFlight['path']);
        @unlink($ratePath);
    }

    /**
     * 링크 실측이 참, 거짓, 판정 불가(null) 중 하나를 돌려주고 실측 산출물을 남기지 않는지 확인한다.
     *
     * @scenario symlink_probe=supported
     *
     * @effects symlink_probe_reports_capability_or_unknown
     *
     * @return void 반환값 없음
     */
    public function test_symlink_probe_returns_capability_or_unknown_and_leaves_no_residue(): void
    {
        $dir = BASE_PATH.'/storage/installer';

        $result = installerProbeSymlinkSupport($dir);

        // 환경 의존 실측 — 값 자체가 아니라 형태와 뒷정리가 계약이다.
        $this->assertTrue($result === null || is_bool($result));

        $residue = array_filter(
            glob($dir.'/'.INSTALLER_SYMLINK_PROBE_PREFIX.'*') ?: [],
            fn (string $p) => is_file($p) || is_link($p)
        );
        $this->assertSame([], array_values($residue), 'symlink 실측 산출물이 남으면 안 된다');
    }

    /**
     * 링크 실측의 상한은 **자기 클라이언트 창**으로만 판정한다 (8차 P4-5).
     *
     * 설치 창은 무인증이다. 전역 창까지 보면 원격자가 그 창을 채우는 것만으로 운영자의
     * 실측이 상시 `null` 이 되고, 화면의 안내 조건(`symlink_supported === false` 일 때만
     * 알린다)이 영영 성립하지 않아 **B-3 전환 안내가 한 번도 뜨지 않는다** — 오류도 흔적도
     * 남지 않는다. 형제(`probe_exposure_sentinel`)는 정확히 이 사유로 이미 자기 창만 본다.
     *
     * @effects exposure_symlink_probe_rate_limit_is_client_scoped
     *
     * @return void 반환값 없음
     */
    public function test_symlink_probe_rate_limit_does_not_read_the_shared_window(): void
    {
        // 클라이언트 식별자가 없으면(CLI 기본) 상한은 전역 카운터 하나로 되돌아가므로
        // 이 축 자체가 성립하지 않는다 — 실제 요청과 같게 원격 주소를 준다.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $dir = BASE_PATH.'/storage/installer';
        @mkdir($dir, 0777, true);

        if (installerProbeSymlinkSupport($dir) === null) {
            $this->markTestSkipped('이 환경은 링크 실측 자체가 판정 불가다 — 상한 축을 잴 수 없다');
        }

        // **전역** 창만 가득 채운다(원격자가 채운 상태). 자기 창은 비어 있다.
        file_put_contents(installerProbeRateLimitStatePath(), (string) json_encode([
            'probe_symlink' => ['started_at' => time(), 'count' => 100000],
        ]));

        $this->assertNotNull(
            installerProbeSymlinkSupport($dir),
            '전역 창이 차면 실측이 죽는다 — 남이 채운 창 때문에 링크 전환 안내가 영영 뜨지 않는다'
        );
    }

    /**
     * 요구사항 응답이 실제로 `web_exposure` 를 싣는다 (B-4 게이트의 서버 절반).
     *
     * 화면의 노출 게이트는 이 항목 하나에 매달려 있다 — 항목이 사라지면 화면은 판정 불능
     * (D21)으로 떨어져 운영자 확인을 요구하지만, 그 전에 **서버가 이 항목을 계속 내려주는지**
     * 를 재는 축이 없으면 배선이 빠진 것을 아무도 모른다. 요구사항 화면을 별도 프로세스에서
     * 실제로 실행해(대소문자·경로 가정 없이 런타임 그대로) 응답 형태를 고정한다.
     *
     * @scenario entry_context=api_script
     *
     * @effects requirements_response_carries_the_web_exposure_item
     *
     * @return void 반환값 없음
     */
    public function test_requirements_response_carries_web_exposure_item(): void
    {
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(dirname(__DIR__).'/Installer/stubs/requirements_probe.php')
            .' 2>&1';

        $output = [];
        $code = -1;
        exec($command, $output, $code);
        $raw = implode("\n", $output);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, '요구사항 응답이 JSON 이 아니다: '.substr($raw, 0, 200));

        $this->assertArrayHasKey(
            'web_exposure',
            $decoded,
            '요구사항 응답에서 web_exposure 가 사라졌다 — 화면의 D3 차단 게이트가 근거를 잃는다'
        );
        $this->assertTrue(
            $decoded['web_exposure']['required'] ?? false,
            'web_exposure 는 필수 항목이다 (D3 — 경고가 아니라 게이트)'
        );
        $this->assertIsArray(
            $decoded['web_exposure']['targets'] ?? null,
            '프로브 대상 목록이 배열이 아니면 화면이 판정 불능으로 떨어진다'
        );
        // `??` 는 null 을 부재로 접으므로 키 존재와 값을 나눠 본다 — 이 항목의 계약은
        // 「키는 있고 값은 null」(판정 주체가 브라우저라는 선언)이다.
        $this->assertArrayHasKey('detected', $decoded['web_exposure']);
        $this->assertNull(
            $decoded['web_exposure']['detected'],
            '판정 주체는 브라우저다 — 서버가 판정값을 단정하면 loopback 오판을 그대로 싣는다'
        );

        // `unmeasured` 와 `controls` 는 **fail-open 방지 축의 실체**다. 서버가 이 키를 떨어뜨리면
        // 화면은 빈 배열로 접고(`Array.isArray` 폴백) 그 축이 조용히 사라진다 — 재지 못한 계열도,
        // 위임이 죽었다는 사실도 판정에 실리지 않아 「보호 확인됨」 초록으로 수렴한다.
        // 키 존재를 단언하지 않으면 그 변이를 어떤 검사도 잡지 못한다.
        $this->assertArrayHasKey(
            'unmeasured',
            $decoded['web_exposure'],
            '재지 못한 계열 목록이 사라지면 「재지 않은 축」이 안전으로 묻힌다 (fail-open)'
        );
        $this->assertIsArray($decoded['web_exposure']['unmeasured']);

        $this->assertArrayHasKey(
            'controls',
            $decoded['web_exposure'],
            '양성 대조가 사라지면 위임 부재(mod_rewrite)·기준 URL 오도출이 전부 「보호 확인됨」이 된다'
        );
        $this->assertIsArray($decoded['web_exposure']['controls']);

        // 나머지 세 키도 같은 이유로 계약이다. 형제 넷만 잠그면 이 셋은 한 줄 삭제로
        // 조용히 사라지는데, 그 변이는 전 스위트가 초록인 채 통과한다.
        //
        //  - `absent_control` 부재 → 레이아웃 ② 정상 배치의 자동 판정(D3)이 성립하지 않아
        //    전 배치가 상시 운영자 확인(D21)으로 강등된다.
        //  - `symlink_supported` 부재 → 링크 가용 실측이 화면에 도달하지 않는다.
        //  - `server_kind` 부재 → 보호 설정 안내가 서버별로 갈리지 못하고 한 벌로 수렴한다.
        $this->assertArrayHasKey(
            'absent_control',
            $decoded['web_exposure'],
            '음성 대조가 사라지면 정상 배치의 자동 판정(D3)이 전 배치에서 D21 로 강등된다'
        );
        $this->assertIsArray($decoded['web_exposure']['absent_control']);
        $this->assertArrayHasKey('path', $decoded['web_exposure']['absent_control']);
        $this->assertArrayHasKey('label', $decoded['web_exposure']['absent_control']);

        $this->assertArrayHasKey(
            'symlink_supported',
            $decoded['web_exposure'],
            '링크 가용 실측이 응답에서 빠지면 그 축이 화면에 도달하지 않는다'
        );
        // 생산자 계약은 `bool|null` 이다 — `symlink` 차단 · `storage/installer` 쓰기 불가 ·
        // 상한 도달 중 하나만 성립해도 「판정 불가」로 `null` 이 나온다. `assertIsBool` 은
        // 그 정상 상태를 결함 없이 red 로 만든다 — 계약을 재는 검사가 생산자보다 좁으면,
        // 그 좁음이 곧 거짓 경보다 (23차 G3 라운드 D23-9).
        $this->assertTrue(
            is_bool($decoded['web_exposure']['symlink_supported'])
                || $decoded['web_exposure']['symlink_supported'] === null,
            'symlink_supported 는 bool 또는 null(판정 불가) 이어야 한다 — 받은 값: '
                .var_export($decoded['web_exposure']['symlink_supported'], true)
        );

        $this->assertArrayHasKey(
            'server_kind',
            $decoded['web_exposure'],
            '서버 종류가 빠지면 보호 설정 안내가 서버별로 갈리지 못한다'
        );
        // 값의 **형태**까지 잠근다 — 문자열이기만 하면 통과하면, 생산자가 미지의 철자를
        // 내보내도 초록이고 화면(`installer.js`)은 그것을 `other` 로 접어 서버별 안내가
        // 조용히 한 벌로 수렴한다 (23차 G3 라운드 D23-8).
        $this->assertContains(
            $decoded['web_exposure']['server_kind'],
            ['apache', 'litespeed', 'iis', 'nginx', 'other'],
            'server_kind 가 화면이 아는 집합 밖이다 — 그 값은 화면에서 other 로 접혀 '
                .'서버별 보호 설정 안내가 갈리지 못한다'
        );

        // 위 단언은 **이 환경이 내는 한 값**만 잰다. 테스트 환경의 `SERVER_SOFTWARE` 는 비어
        // 있어 언제나 `other` 분기로 떨어지므로, 나머지 네 분기는 한 번도 평가되지 않는다 —
        // 실측: `apache` 분기의 반환값을 `'Apache'` 로 바꿔도 이 클래스가 전부 초록이었다
        // (24차 G3 라운드 R24-C M12). 그래서 생산자를 **직접 구동**해 전 분기를 잰다.
        $known = ['apache', 'litespeed', 'iis', 'nginx', 'other'];
        $original = $_SERVER['SERVER_SOFTWARE'] ?? null;

        try {
            foreach ([
                'Apache/2.4.58 (Unix)',
                'LiteSpeed',
                'lsws/1.7',
                'nginx/1.24.0',
                'Microsoft-IIS/10.0',
                'Microsoft-HTTPAPI/2.0',
                'Caddy',
                '',
            ] as $software) {
                $_SERVER['SERVER_SOFTWARE'] = $software;

                $this->assertContains(
                    installerDetectServerKind(),
                    $known,
                    "SERVER_SOFTWARE '{$software}' 에 대해 생산자가 화면이 모르는 철자를 냈다 — "
                        .'화면은 그것을 other 로 접어 서버별 안내가 조용히 한 벌로 수렴한다'
                );
            }

            // 분기가 실제로 갈리는지도 잰다 — 전부 `other` 를 돌려줘도 위 단언은 통과한다.
            $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';
            $this->assertSame('apache', installerDetectServerKind());
            $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
            $this->assertSame('nginx', installerDetectServerKind());
            $_SERVER['SERVER_SOFTWARE'] = 'Microsoft-IIS/10.0';
            $this->assertSame('iis', installerDetectServerKind());
            // LiteSpeed 는 Apache 호환 표기를 함께 싣는 판본이 있어 순서가 중요하다.
            $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed (Apache/2.4 compatible)';
            $this->assertSame('litespeed', installerDetectServerKind());
        } finally {
            if ($original === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $original;
            }
        }
    }

    /**
     * 양성 대조 대상이 실제로 산출되고, 마커가 그 파일에서 도출된다.
     *
     * 대조는 「반드시 열려야 하는 주소」다. 목록이 비면 그 축은 선언만 있고 동작하지 않는다 —
     * 화면은 `controls: []` 를 「대조할 것이 없다」로 읽어 그대로 통과시킨다.
     *
     * @scenario exposure_control=reachable
     *
     * @effects exposure_positive_control_detects_broken_delegation
     *
     * @return void 반환값 없음
     */
    public function test_exposure_control_plan_targets_an_installer_asset_with_a_derived_marker(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $plan = installerExposureControlPlan($repoRoot);
        $controls = $plan['controls'];

        $this->assertNotEmpty($controls, '양성 대조 대상이 비면 위임 부재를 잡을 근거가 없다');
        $this->assertSame([], $plan['unmeasured'], '저장소 트리에서는 준비 실패가 없어야 한다');

        $control = $controls[0];

        $this->assertSame('/install/assets/css/installer.css', $control['path']);
        $this->assertNotSame('', $control['marker']);

        // 마커는 **그 파일에서** 나와야 한다 — 코드에 박아 두면 파일이 바뀌었을 때 대조가
        // 조용히 실패해 상시 판정 불능이 되고, 그 실패가 결함과 구분되지 않는다.
        $this->assertStringContainsString(
            $control['marker'],
            (string) file_get_contents($repoRoot.'/public/install/assets/css/installer.css'),
            '대조 마커가 대상 파일 내용에서 도출되지 않았다'
        );
    }

    /**
     * `.env.example` 이 없으면 그 계열은 **재지 못한 것**으로 보고된다.
     *
     * 이 파일은 배포본에 언제나 실린다. 없다는 것은 「원래 없는 파일」이 아니라 운영자가
     * `.env` 로 이름을 바꿨다는 뜻이고(흔한 설치 절차), 그러면 숨김 파일 축을 한 번도 재지
     * 못한 상태가 된다. 종전에는 그 계열이 목록에서 조용히 빠져, `*.json`·`storage/` 만 막고
     * dotfile 은 안 막는 규칙에서 `.env` 가 노출인데 화면이 초록이었다.
     *
     * @scenario target_file=env_example, exposure_unknown_cause=unmeasured_only
     *
     * @effects exposure_missing_env_example_is_reported_as_unmeasured
     *
     * @return void 반환값 없음
     */
    public function test_missing_env_example_is_reported_as_unmeasured_not_silently_dropped(): void
    {
        // `.env.example` 을 두지 않는다 (운영자가 `.env` 로 이름을 바꾼 상태)
        file_put_contents(BASE_PATH.'/composer.json', json_encode(['name' => 'g7/probe']));

        $plan = installerExposureProbePlan(BASE_PATH);

        $labels = array_column($plan['unmeasured'], 'label');
        $this->assertContains('.env', $labels, '.env 계열이 목록에서 조용히 빠졌다 — 재지 않은 축이 안전으로 묻힌다');

        $byLabel = array_column($plan['unmeasured'], null, 'label');
        $this->assertSame('missing', $byLabel['.env']['reason'] ?? null);
    }

    /**
     * `composer.json` 이 없으면 그 계열도 **재지 못한 것**으로 보고된다.
     *
     * 이 파일은 확장자 축(`*.json`)을 재는 **유일한** 프로브다. 형제 둘(`.env.example` ·
     * `vendor/autoload.php`)은 준비 실패를 전부 `unmeasured` 로 승격하는데 이 계열만
     * 조용히 빠지고 있었다 — 그러면 확장자 방어선이 통째로 죽은 서버에서도 남은 두 계열의
     * 403 만으로 화면이 「보호 확인됨」이 된다. 재지 못한 축은 안전이 아니라 판정 불능(D21)이다.
     *
     * @effects exposure_missing_composer_json_is_reported_as_unmeasured
     *
     * @return void 반환값 없음
     */
    public function test_missing_composer_json_is_reported_as_unmeasured_not_silently_dropped(): void
    {
        // `.env.example` 은 두고 `composer.json` 만 없는 상태 (확장자 축만 못 재는 배치)
        file_put_contents(BASE_PATH.'/.env.example', 'APP_NAME=G7
');

        $plan = installerExposureProbePlan(BASE_PATH);

        $labels = array_column($plan['unmeasured'], 'label');
        $this->assertContains(
            'composer.json',
            $labels,
            'composer.json 계열이 목록에서 조용히 빠졌다 — 확장자 축을 한 번도 재지 않고 초록이 된다'
        );

        $byLabel = array_column($plan['unmeasured'], null, 'label');
        $this->assertSame('missing', $byLabel['composer.json']['reason'] ?? null);
    }

    /**
     * 대조 자산이 없거나 마커를 못 뽑으면 **재지 못한 계열**로 보고한다.
     *
     * 조용한 빈 배열은 화면에서 「대조할 것이 없다」로 읽혀 그 분기를 건너뛰게 하고,
     * 그러면 위임이 죽은 서버가 다시 「보호 확인됨」으로 수렴한다 — 5차가 닫은 결함이
     * 준비 실패 쪽으로 되살아난다. 형제 계열(`targets`)은 전부 승격한다.
     *
     * @effects exposure_control_preparation_failure_is_reported_as_unmeasured
     *
     * @return void 반환값 없음
     */
    public function test_exposure_control_plan_reports_preparation_failure(): void
    {
        $empty = sys_get_temp_dir().'/g7-control-plan-'.bin2hex(random_bytes(4));
        mkdir($empty.'/public/install/assets/css', 0777, true);

        try {
            $plan = installerExposureControlPlan($empty);

            $this->assertSame([], $plan['controls']);
            $this->assertSame(
                [['label' => 'install/assets', 'reason' => 'missing']],
                $plan['unmeasured'],
                '대조를 준비하지 못한 사실이 사라지면 그 축은 조용히 통과한다'
            );

            file_put_contents($empty.'/public/install/assets/css/installer.css', 'body{color:red}');
            $plan = installerExposureControlPlan($empty);

            $this->assertSame([], $plan['controls']);
            $this->assertSame([['label' => 'install/assets', 'reason' => 'no_marker']], $plan['unmeasured']);
        } finally {
            @unlink($empty.'/public/install/assets/css/installer.css');
            @rmdir($empty.'/public/install/assets/css');
            @rmdir($empty.'/public/install/assets');
            @rmdir($empty.'/public/install');
            @rmdir($empty.'/public');
            @rmdir($empty);
        }
    }

    /**
     * 음성 대조는 **디스크에 있을 수 없는** 경로를 매번 새로 만든다.
     *
     * 레이아웃 ②는 루트 `.htaccess` 가 모든 주소를 앱으로 위임하므로, 파일이 서빙되지 않는
     * 주소도 200 앱 화면으로 돌아온다(P0 실측: `/vendor/autoload.php`·`/storage/...` 가 둘 다
     * `HTTP 200 text/html`). 403/404 만 보호로 읽으면 그 정상 배치가 상시 「판정 불능」이 되어
     * D3 자동 차단이 모든 배치에서 D21 로 강등된다. 그래서 「이 서버가 파일 없는 주소에
     * 무엇을 돌려주는가」를 함께 재고, 그 형태와 같은 응답을 받은 대상을 보호로 읽는다
     * (14차 G3 라운드).
     *
     * @effects exposure_absent_control_restores_automatic_judgement
     *
     * @return void 반환값 없음
     */
    public function test_absent_control_is_unique_extensionless_and_cannot_exist_on_disk(): void
    {
        $first = installerExposureAbsentControl();
        $second = installerExposureAbsentControl();

        $this->assertArrayHasKey('path', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertSame('absent', $first['label']);

        $this->assertStringStartsWith('/', $first['path']);
        $this->assertNotSame(
            $first['path'],
            $second['path'],
            '매 호출 고유해야 한다 — 이름을 고정하면 그 경로에 파일을 놓아 대조를 무력화할 수 있다'
        );

        // 확장자를 두지 않는다. 서버·앱이 확장자로 응답을 가르는 배치가 있어(실측: `.json` 은
        // 404, 확장자 없는 주소는 200 앱 화면) 대조가 대상과 다른 취급을 받으면 기준이 되지 못한다.
        $this->assertDoesNotMatchRegularExpression(
            '/\.[A-Za-z0-9]+$/',
            $first['path'],
            '음성 대조 경로에 확장자가 있으면 대상 계열과 다른 취급을 받는다'
        );

        $this->assertFileDoesNotExist(
            rtrim(BASE_PATH, '/\\').$first['path'],
            '음성 대조 대상이 디스크에 실재하면 그 대조는 「파일 없음」의 기준이 아니다'
        );
    }

    /**
     * 프로브 계획이 음성 대조를 **함께** 내려준다.
     *
     * 화면은 서버가 준 목록만 잰다 — 계획에서 빠지면 클라이언트는 그 대조를 던질 수 없고,
     * 승격 근거가 사라져 자동 판정이 다시 D21 로 떨어진다.
     *
     * @effects exposure_absent_control_restores_automatic_judgement
     *
     * @return void 반환값 없음
     */
    public function test_probe_plan_carries_the_absent_control(): void
    {
        $plan = installerExposureProbePlan(BASE_PATH);

        $this->assertArrayHasKey('absent_control', $plan);
        $this->assertSame('absent', $plan['absent_control']['label']);
        $this->assertStringStartsWith('/', $plan['absent_control']['path']);
    }

    /**
     * 음성 대조 이름은 **차단 가능한 함수에 기대지 않는다.**
     *
     * 이 함수는 형제 경로의 `no_random` **catch 블록 안에서도** 불린다. 무가드
     * `random_bytes()` 를 직접 부르면 CSPRNG 를 못 얻는 호스팅에서 그 두 번째 던짐을
     * 받을 곳이 없어 요구사항 응답 전체가 500 이 되고, 일부러 만든 `no_random` 강등
     * 경로와 그 다국어 문구가 통째로 사문이 된다. 폴백은 잡히는 자리에 있어야 한다.
     *
     * 이름 생성은 형제 SSoT(`installerUniqueSuffix`, CSPRNG 실패 시 폴백 보유)가 소유한다.
     * 이 검사는 그 위임을 소스 계약으로 고정한다 — 실행으로는 CSPRNG 부재를 만들 수 없다.
     *
     * @effects exposure_absent_control_name_survives_blocked_csprng
     *
     * @return void 반환값 없음
     */
    public function test_absent_control_name_does_not_call_random_bytes_directly(): void
    {
        // 주석을 **먼저** 걷어내고 코드만 판정한다. 이 함수의 독블록은 왜 무가드
        // `random_bytes` 를 쓰면 안 되는지를 그 이름과 함께 설명하므로, 원문을 그대로
        // 재면 검사가 자기 설명에 걸려 언제나 위반이 된다(자기충족). 걷어낸 뒤에
        // 함수를 찾아야 창이 주석에 먹히지 않는다 — 본문 도출은 트레이트가 한다.
        $code = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/exposure-check.php');
        $body = $this->functionBody($code, 'installerExposureAbsentControl');

        $this->assertNotSame('', $body, '음성 대조 생성 함수를 찾지 못했다');

        $this->assertStringNotContainsString(
            'random_bytes(',
            $body,
            '음성 대조 이름이 무가드 random_bytes 를 직접 부른다 — `no_random` catch 안에서 다시 던져 500 이 된다'
        );
        $this->assertStringContainsString(
            'installerUniqueSuffix(',
            $body,
            '이름 생성이 CSPRNG 폴백을 갖춘 형제 SSoT 를 경유하지 않는다'
        );
    }
}
