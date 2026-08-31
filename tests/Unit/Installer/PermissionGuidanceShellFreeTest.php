<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 권한 안내 무쉘 판본 (W7 · B-8) 과 Q7 판정.
 *
 * ## 무엇이 걸려 있는가
 *
 * 종전 권한 안내는 **쉘 명령 한 벌**이었다. 그 안내를 받는 사람의 다수는 SSH 를 쓸 수 없어,
 * 화면은 정상이지만 운영자에게는 실행할 수 없는 지시만 남는다 — 오류로 드러나지 않고
 * 「설치가 여기서 멈춘다」로만 나타난다.
 *
 * ## Q7 — 755 로 부족한 서버 구성이 실재하는가
 *
 * 실재한다. 판정은 관측이 아니라 **권한 모델 자체**에서 나온다: 0755 의 others 비트는 `r-x`
 * 이므로 웹 서버가 파일 소유자와 다른 계정으로 돌고 공유 그룹도 없으면 쓰기가 없다. FTP
 * 전용 계정에는 소유자·그룹을 바꿀 수단이 없으므로(그 안내는 보안 결정으로 제외된다) 남는
 * 선택지는 others 에 쓰기를 주는 값(707)뿐이다. 그래서 그 구성에서만 보조 값을 병기한다.
 *
 * P0 검증 환경은 Windows Apache 라 POSIX 권한 비트를 실행으로 재현할 수 없다 — 그래서
 * 이 축은 모델 판정 함수(`installerPermissionGrantsWrite`)로 고정한다.
 *
 * 커버 축: permission_guidance ftp·shell / os linux·windows / ownership matched·mismatched /
 * target directory·file / outcome repaired·guided
 *
 * 효과 요약(마커 아님 — 평문): 무쉘 판본 존재 · OS 분기 유지 · Q7 보조 값 · 직접 권한 부여.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class PermissionGuidanceShellFreeTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $this->repoRoot());
        }
        if (! defined('REQUIRED_DIRECTORY_PERMISSIONS')) {
            define('REQUIRED_DIRECTORY_PERMISSIONS', 0755);
        }
        if (! defined('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY')) {
            define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '755');
        }
        if (! defined('REQUIRED_DIRECTORIES')) {
            define('REQUIRED_DIRECTORIES', ['storage' => true]);
        }
        if (! defined('SUPPORTED_LANGUAGES')) {
            define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
        }
        if (! defined('INSTALLER_BASE_URL')) {
            define('INSTALLER_BASE_URL', '/install');
        }

        // 가드 판정 SSoT(`installerHasFunction`)는 execution-mode.php 가 소유한다 —
        // 권한 복구가 `chmod` 가용성을 그 함수로 판정하므로 함께 싣는다.
        require_once $this->repoRoot().'/public/install/includes/execution-mode.php';
        require_once $this->repoRoot().'/public/install/includes/functions.php';

        $GLOBALS['translations'] = require $this->repoRoot().'/public/install/lang/ko.php';
    }

    /**
     * FTP 절차가 표준 권한 값을 제시하고, 마지막에 재검사를 안내한다.
     *
     * @scenario target=directory, os=linux, ownership=matched
     *
     * @effects permission_guidance_ftp_steps_present_standard_value
     */
    public function test_FTP_절차가_표준_권한_값과_재검사를_안내한다(): void
    {
        $guide = installerFtpPermissionGuide(['./storage', './bootstrap/cache'], true, false);

        $joined = implode(' ', $guide['steps']);

        $this->assertStringContainsString('./storage', $joined);
        $this->assertStringContainsString('755', $joined, 'FTP 절차가 표준 권한 값을 제시하지 않는다');
        $this->assertStringContainsString('./bootstrap/cache', $joined, '나머지 대상 경로가 절차에 빠졌다');
        $this->assertStringContainsString('다시 검사', end($guide['steps']), '절차의 마지막이 재검사가 아니다');
        $this->assertSame('', $guide['note'], '소유 관계가 정상인 구성에 보조 값이 붙었다');
    }

    /**
     * 파일 대상은 디렉토리와 다른 값(644)을 제시하고 "하위 폴더 적용" 을 말하지 않는다.
     *
     * @scenario target=file, os=linux, ownership=matched
     *
     * @effects permission_guidance_ftp_steps_target_file_permission_value
     */
    public function test_파일_대상_절차가_파일용_값을_제시한다(): void
    {
        $guide = installerFtpPermissionGuide(['./.env'], false, false);
        $joined = implode(' ', $guide['steps']);

        $this->assertStringContainsString('644', $joined);
        $this->assertStringNotContainsString('하위 폴더', $joined, '파일에 하위 폴더 적용을 안내했다');
    }

    /**
     * Windows 분기를 유지한다 (§7 R40 — 판본을 늘리면 OS 분기가 누락되기 쉽다).
     *
     * @scenario target=directory, target=file, os=windows, ownership=matched
     *
     * @effects permission_guidance_keeps_windows_branch
     */
    public function test_Windows_에서는_숫자_값_대신_보안_탭_절차를_안내한다(): void
    {
        $guide = installerFtpPermissionGuide(['./storage'], true, true);
        $joined = implode(' ', $guide['steps']);

        $this->assertStringContainsString('보안', $joined, 'Windows 절차가 보안 탭을 안내하지 않는다');
        $this->assertStringNotContainsString('755', $joined, 'Windows 에 POSIX 숫자 값을 안내했다');
        $this->assertNotSame('', $guide['note'], 'Windows 보조 안내가 비었다');
    }

    /**
     * 소유 관계가 어긋난 구성에는 보조 값을 병기한다 (Q7).
     *
     * @scenario target=directory, target=file, os=linux, ownership=mismatched
     *
     * @effects permission_guidance_adds_fallback_value_on_ownership_mismatch
     */
    public function test_소유_관계가_어긋나면_보조_권한_값을_병기한다(): void
    {
        $directory = installerFtpPermissionGuide(['./storage'], true, false, true);
        $file = installerFtpPermissionGuide(['./.env'], false, false, true);

        $this->assertStringContainsString('707', $directory['note'], '디렉토리 보조 값이 없다');
        $this->assertStringContainsString('755', $directory['note'], '보조 값 안내가 표준 값을 함께 설명하지 않는다');
        $this->assertStringContainsString('606', $file['note'], '파일 보조 값이 없다');
    }

    /**
     * 무쉘 판본에는 소유자 변경 안내가 없다 (보안 결정).
     *
     * 소유자 변경은 RCE 시 코드·시크릿 변조 위험이 있고, 소유자 변경 권한이 없는 공유
     * 호스팅에서 반복 실패했다. FTP 판본은 퍼미션 값 안내로 한정한다.
     *
     * @scenario target=directory, target=file, os=windows, ownership=mismatched
     *
     * @effects permission_guidance_shell_free_version_omits_ownership_change
     */
    public function test_무쉘_판본에_소유자_변경_안내가_없다(): void
    {
        foreach ([[true, false], [true, true], [false, false], [false, true]] as [$isDirectory, $isWindows]) {
            foreach ([false, true] as $ownershipMismatch) {
                $guide = installerFtpPermissionGuide(['./storage'], $isDirectory, $isWindows, $ownershipMismatch);
                $text = implode(' ', $guide['steps']).' '.$guide['note'];

                foreach (['chown', 'chgrp', '소유자를 변경', 'sudo'] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        $text,
                        "무쉘 판본에 소유자 변경·관리자 권한 안내가 들어갔다: {$forbidden}"
                    );
                }
            }
        }
    }

    /**
     * Q7 판정 — 권한 값이 그 계정에 실제로 쓰기를 주는가.
     *
     * @param  int  $mode  퍼미션 비트
     * @param  bool  $isOwner  소유자인가
     * @param  bool  $isGroupMember  소유 그룹인가
     * @param  bool  $expected  쓰기가 허용되는가
     *
     * @effects permission_value_write_grant_follows_posix_model
     */
    #[DataProvider('writeGrantCases')]
    public function test_권한_값이_주는_쓰기_권한을_모델대로_판정한다(int $mode, bool $isOwner, bool $isGroupMember, bool $expected): void
    {
        $this->assertSame($expected, installerPermissionGrantsWrite($mode, $isOwner, $isGroupMember));
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: bool, 3: bool}>
     */
    public static function writeGrantCases(): array
    {
        return [
            '755 · 소유자' => [0755, true, false, true],
            '755 · 그룹' => [0755, false, true, false],
            // Q7 의 핵심 — 웹 서버가 소유자도 그룹도 아니면 755 로는 쓰기가 없다.
            '755 · 남' => [0755, false, false, false],
            '775 · 그룹' => [0775, false, true, true],
            '707 · 남' => [0707, false, false, true],
            '777 · 남' => [0777, false, false, true],
            '644 · 소유자' => [0644, true, false, true],
            '644 · 남' => [0644, false, false, false],
            '606 · 남' => [0606, false, false, true],
        ];
    }

    /**
     * 이미 쓸 수 있는 대상은 손대지 않는다 (§7 R41 — 운영자 의도를 덮어쓰지 않는다).
     *
     * @effects permission_repair_leaves_writable_targets_untouched
     */
    public function test_이미_쓸_수_있는_대상의_권한을_바꾸지_않는다(): void
    {
        $dir = sys_get_temp_dir().'/g7-perm-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            $before = fileperms($dir);
            // BASE_PATH 기준 상대 경로로만 받으므로 절대 경로를 상대로 환산해 넘긴다.
            $relative = $this->relativeToBase($dir);
            $result = installerRepairPermissions([$relative]);

            clearstatcache(true, $dir);
            $this->assertSame($before, fileperms($dir), '통과한 대상의 권한 비트를 바꿨다');
            $this->assertFalse($result[$relative], '손대지 않은 대상을 복구했다고 보고했다');
        } finally {
            @rmdir($dir);
        }
    }

    /**
     * 없는 경로는 조용히 건너뛴다 (실패는 무해해야 한다).
     *
     * @effects permission_repair_skips_missing_paths
     */
    public function test_없는_경로는_오류_없이_건너뛴다(): void
    {
        $result = installerRepairPermissions(['storage/__g7_missing_'.bin2hex(random_bytes(4))]);

        $this->assertCount(1, $result);
        $this->assertFalse(reset($result));
    }

    /**
     * 안내를 내는 화면이 **두 벌 모두** 그린다.
     *
     * 헬퍼만 있고 부르는 곳이 없으면 화면은 종전과 똑같다 — 그 상태가 단위 테스트로는
     * 전부 초록이므로 배선 자체를 여기서 잰다.
     *
     * @effects permission_guidance_renders_both_versions_on_every_surface
     */
    public function test_안내_화면이_두_판본을_모두_그린다(): void
    {
        $welcome = (string) file_get_contents($this->repoRoot().'/public/install/views/0-welcome.php');

        $this->assertStringContainsString('installerFtpPermissionGuide(', $welcome, 'Step 0 이 FTP 판본을 만들지 않는다');
        $this->assertStringContainsString('<details class="permission-guide" open>', $welcome, 'FTP 판본이 기본 펼침이 아니다');
        $this->assertSame(
            2,
            substr_count($welcome, '<details class="permission-guide"'),
            'Step 0 의 권한 안내가 두 벌이 아니다'
        );
        $this->assertSame(
            2,
            substr_count($welcome, '</details>'),
            'Step 0 의 아코디언이 닫히지 않았다'
        );

        $js = (string) file_get_contents($this->repoRoot().'/public/install/assets/js/installer.js');

        // 정의 1회 + 호출 N회 — 호출 수가 두 헬퍼에서 같아야 두 판본이 짝을 이룬다.
        $ftpCalls = substr_count($js, 'renderFtpPermissionGuide(') - 1;
        $shellCalls = substr_count($js, 'openShellPermissionGuide()') - 1;

        $this->assertGreaterThanOrEqual(4, $ftpCalls, 'Step 2 의 권한 안내 지점 일부가 FTP 판본을 그리지 않는다');
        $this->assertSame($shellCalls, $ftpCalls, 'FTP 판본과 쉘 판본의 배선 개수가 다르다 — 한쪽만 그리는 지점이 있다');
    }

    /**
     * 서버가 만든 절차가 화면까지 도달한다.
     *
     * 절차 문구는 서버가 만든다(경로 목록·OS·소유 관계 판정을 두 벌로 갖지 않기 위해).
     * 그래서 서버가 내보내는 키마다 소비처가 있어야 한다 — 없으면 그 지점만 조용히
     * 쉘 안내 한 벌로 남는다.
     *
     * @effects permission_guidance_server_steps_reach_the_screen
     */
    public function test_서버가_내보내는_절차_키를_화면이_전부_소비한다(): void
    {
        $api = (string) file_get_contents($this->repoRoot().'/public/install/api/check-configuration.php');
        $js = (string) file_get_contents($this->repoRoot().'/public/install/assets/js/installer.js');

        $this->assertSame(
            2,
            substr_count($api, "'ftp_guide' =>"),
            '디렉토리·파일 두 축 모두에서 FTP 절차를 내보내야 한다'
        );
        $this->assertSame(
            2,
            substr_count($api, "'ftp_guide_ownership' =>"),
            '소유 관계가 어긋난 축의 FTP 절차가 빠졌다'
        );

        foreach ([
            'directories.ftp_guide',
            'directories.ftp_guide_ownership',
            'required_files.ftp_guide',
            'required_files.ftp_guide_ownership',
        ] as $key) {
            $this->assertStringContainsString(
                $key,
                $js,
                "서버가 내보낸 절차를 화면이 소비하지 않는다: {$key}"
            );
        }
    }

    /**
     * 안내를 내기 전에 직접 권한 부여를 시도한다 (B-8).
     *
     * 성공하면 안내 화면 자체가 나오지 않는다 — 쉘이 없는 계정에서는 유일한 자동 복구
     * 수단이므로, 두 검사 지점(Step 0 · Step 2) 모두에서 검사보다 **먼저** 불러야 한다.
     *
     * @effects permission_repair_runs_before_the_check_on_both_surfaces
     */
    public function test_두_검사_지점이_검사_전에_직접_권한_부여를_시도한다(): void
    {
        $welcome = (string) file_get_contents($this->repoRoot().'/public/install/views/0-welcome.php');
        $api = (string) file_get_contents($this->repoRoot().'/public/install/api/check-configuration.php');

        $repairPos = strpos($welcome, 'installerRepairPermissions(');
        $checkPos = strpos($welcome, 'checkDirectoryPermissions(');
        $this->assertIsInt($repairPos, 'Step 0 이 직접 권한 부여를 시도하지 않는다');
        $this->assertIsInt($checkPos);
        $this->assertLessThan($checkPos, $repairPos, 'Step 0 이 검사 뒤에 권한 부여를 시도한다 — 그러면 안내가 먼저 뜬다');

        $this->assertSame(
            2,
            substr_count($api, 'installerRepairPermissions('),
            'Step 2 의 디렉토리·파일 두 축 중 한쪽이 직접 권한 부여를 건너뛴다'
        );
    }

    /**
     * `chmod` 가 막힌 호스팅에서도 죽지 않고 「복구 못 함」으로 끝난다.
     *
     * 이 안이 지원하려는 프로필이 정확히 그 환경이다. PHP 8 은 막힌 함수를 함수 테이블에서
     * 지우므로 무가드 호출은 `Error`(치명)이고 `@` 는 가드가 아니다 — 안내를 내려던 자리에서
     * 화면이 통째로 죽는다. `disable_functions` 는 PHP_INI_SYSTEM 이라 하위 프로세스로만 잰다.
     *
     * @effects permission_repair_survives_blocked_chmod
     */
    public function test_chmod_이_막힌_환경에서도_죽지_않는다(): void
    {
        $probe = $this->runRepairProbe('blocked', 'chmod');

        $this->assertSame(false, $probe['chmod_available'], '하네스가 chmod 를 실제로 막지 못했다');
        $this->assertFalse($probe['repaired'], 'chmod 를 쓸 수 없는데 복구했다고 보고했다');
    }

    /**
     * 쓰기 권한이 없는 디렉토리에 직접 권한을 부여해 복구한다 (B-8 의 존재 이유).
     *
     * 「성공하면 안내 자체가 필요 없다」가 이 기능의 목적이므로 그 분기를 실제로 태운다.
     * POSIX 권한 비트가 실효인 환경에서만 성립한다 — Windows 는 디렉토리의 `chmod` 가
     * 쓰기를 막지 않아 애초에 「쓰기 불가」 상태를 만들 수 없다.
     *
     * @effects permission_repair_grants_write_when_chmod_works
     */
    public function test_쓰기_불가_디렉토리를_직접_복구한다(): void
    {
        $base = $this->makeProbeBase();

        try {
            $target = $base.'/storage';
            mkdir($target, 0755, true);
            @chmod($target, 0500);
            clearstatcache(true, $target);

            if (installerDirectoryIsWritable($target)) {
                $this->markTestSkipped('POSIX 권한 비트가 실효가 아닌 환경 — 「쓰기 불가」 상태를 만들 수 없다');
            }

            $probe = $this->runRepairProbe('storage', null, $base);

            $this->assertTrue($probe['repaired'], '직접 권한 부여가 복구하지 못했다');
            $this->assertTrue($probe['writable_after'], '복구했다고 보고했는데 실제로는 쓸 수 없다');
        } finally {
            @chmod($base.'/storage', 0755);
            $this->removeTree($base);
        }
    }

    /**
     * 하네스 전용 임시 BASE_PATH.
     */
    private function makeProbeBase(): string
    {
        $dir = sys_get_temp_dir().'/g7-perm-repair-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return str_replace('\\', '/', $dir);
    }

    /**
     * 직접 권한 부여 하네스를 하위 프로세스로 실행한다.
     *
     * @param  string  $target  BASE_PATH 기준 상대 경로 ('blocked' 는 자동 생성한다)
     * @param  string|null  $disable  `disable_functions` 로 막을 함수
     * @param  string|null  $base  BASE_PATH (없으면 새로 만든다)
     * @return array<string, mixed> 하네스 응답
     */
    private function runRepairProbe(string $target, ?string $disable = null, ?string $base = null): array
    {
        if (! defined('PHP_BINARY') || PHP_BINARY === '') {
            $this->markTestSkipped('PHP_BINARY 를 알 수 없어 하위 프로세스를 띄울 수 없다');
        }

        $ownsBase = $base === null;
        $base ??= $this->makeProbeBase();

        if ($target === 'blocked') {
            $target = 'storage';
            if (! is_dir($base.'/storage')) {
                mkdir($base.'/storage', 0500, true);
            }
        }

        $command = escapeshellarg(PHP_BINARY)
            .($disable === null ? '' : ' -d '.escapeshellarg('disable_functions='.$disable))
            .' '.escapeshellarg(__DIR__.'/stubs/permission_repair_probe.php')
            .' '.escapeshellarg($this->repoRoot())
            .' '.escapeshellarg($base)
            .' '.escapeshellarg($target)
            .' dir 2>&1';

        $output = [];
        exec($command, $output);
        $raw = implode("\n", $output);

        if ($ownsBase) {
            @chmod($base.'/storage', 0755);
            $this->removeTree($base);
        }

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, '권한 부여 하네스 응답이 JSON 이 아니다: '.$raw);

        return $decoded;
    }

    /**
     * 소유자 변경 안내(`chown`·`chgrp`)는 **소유자 불일치 갈래에만** 있다 (§7 R39 의 실제 결정).
     *
     * R39 가 기록한 결정(`0d9ddf806`, 2026-04-14 12:52)의 본문은 「표준 안내에서 chown 의존성을
     * 없애 공유호스팅에서 FTP/chmod 단일 명령어로 끝나게 한다」이다. 「어디에도 두지 않는다」가
     * 아니다 — 같은 날 13:56 의 `654966874` 가 **소유자 ≠ 웹서버** 전용 갈래를 새로 만들면서
     * 그 안내를 다시 넣었고, 그 갈래는 `chmod` 만으로는 원리상 고칠 수 없는 구성을 담당한다
     * (전통적 Apache: 파일 소유자 = 배포 계정, 웹서버 = www-data).
     *
     * 그래서 잠글 것은 「chown 이 없다」가 아니라 **환경별 규칙**이다. 표준 안내 경로에 이것이
     * 다시 스며들면 공유호스팅 운영자에게 실행할 수 없는 지시가 돌아가고, 그 화면은 정상으로
     * 보인다 — 오류가 아니라 「설치가 여기서 멈춘다」로만 나타난다.
     *
     * 모집단은 손으로 적지 않는다. 안내를 **그리는** 파일(= 그 다국어 키를 `lang()` 으로 부르는
     * 파일)을 저장소에서 도출하고, 비면 실패한다 — 도출이 비면 단언이 통째로 공회전한다.
     *
     * @effects permission_guidance_ownership_change_confined_to_mismatch_branch
     */
    public function test_소유자_변경_안내는_소유자_불일치_갈래에만_있다(): void
    {
        $files = $this->guidanceRenderFiles();
        $this->assertNotEmpty($files, '권한 안내를 그리는 파일을 하나도 찾지 못했다 — 모집단 도출이 깨졌다');

        $checked = 0;

        foreach ($files as $path) {
            $lines = explode("\n", $this->stripSourceComments($path));

            foreach ($lines as $index => $line) {
                if (preg_match('/\b(?:chown|chgrp)\b/', $line) !== 1) {
                    continue;
                }

                $checked++;

                $nearestOwnership = $this->nearestPrecedingMatch(
                    $lines,
                    $index,
                    // 소유자 불일치 갈래를 여는 표지 — 에러 타입 리터럴과 그 갈래가 쓰는 식별자.
                    '/ownership_mismatch|[Oo]wnershipMismatchItems|ownerMatchesWebUser/'
                );

                $nearestOther = $this->nearestPrecedingMatch(
                    $lines,
                    $index,
                    // 표준(소유자 일치) 안내 갈래를 여는 표지.
                    '/failedDirPaths\.length|failedFilePaths\.length|missingFilePaths\.length'
                        ."|error_type'\\]\\s*===\\s*'(?:not_writable|not_readable|not_exists|subdirectory_issues)'/"
                );

                $where = basename($path).':'.($index + 1);

                $this->assertNotNull(
                    $nearestOwnership,
                    "소유자 변경 안내가 소유자 불일치 갈래 밖에 있다 ({$where}) — 표준 안내는 chmod 로 끝나야 한다"
                );
                $this->assertGreaterThan(
                    (int) $nearestOther,
                    $nearestOwnership,
                    "소유자 변경 안내가 표준 안내 갈래 안으로 들어갔다 ({$where}) — "
                        .'공유호스팅 운영자에게 실행할 수 없는 지시가 돌아간다'
                );
            }
        }

        $this->assertGreaterThan(0, $checked, '소유자 변경 안내를 한 줄도 보지 못했다 — 검사가 공회전했다');
    }

    /**
     * 소유자 불일치 안내는 **권장 → 대안 → 최후** 순서를 지킨다.
     *
     * 세 선택지는 안전도가 다르다 — 그룹만 바꾸면 소유자는 배포 계정으로 남고(권장), 소유자를
     * 웹서버로 넘기면 그 계정이 코드를 소유하며(대안), 777 은 누구에게나 쓰기를 준다(최후).
     * 재도입 커밋이 그 순서를 명시해 두었지만 순서 자체를 재는 것이 없어, 누군가 블록을
     * 재배열하면 가장 위험한 선택지가 첫 번째 안내가 되는데 화면은 정상으로 보인다.
     *
     * @effects permission_guidance_ownership_options_keep_safety_order
     */
    public function test_소유자_불일치_안내가_권장_대안_최후_순서를_지킨다(): void
    {
        $files = $this->guidanceRenderFiles();
        $this->assertNotEmpty($files, '권한 안내를 그리는 파일을 하나도 찾지 못했다 — 모집단 도출이 깨졌다');

        // 「최후」 선택지의 이름은 대상에 따라 갈린다 — 디렉토리는 777, 파일은 666 이 등가값이다.
        // 한쪽만 세면 다른 쪽 묶음이 두 개로 세어져 검사가 거짓 red 를 낸다.
        $permissive = ['777', '666'];
        $total = 0;

        foreach ($files as $path) {
            preg_match_all(
                "/lang\('ownership_mismatch_option_(group|chown|777|666)'\)/",
                $this->stripSourceComments($path),
                $matches
            );

            $seen = $matches[1];
            $name = basename($path);

            $this->assertNotEmpty($seen, "{$name} 이 소유자 불일치 선택지를 그리지 않는다 — 모집단 도출이 어긋났다");
            $this->assertSame(
                0,
                count($seen) % 3,
                "{$name} 의 소유자 불일치 선택지가 세 벌을 이루지 않는다 — 한 선택지가 빠졌다"
            );

            foreach (array_chunk($seen, 3) as $ordinal => $triple) {
                $this->assertSame(
                    ['group', 'chown'],
                    array_slice($triple, 0, 2),
                    "{$name} 의 소유자 불일치 선택지 {$ordinal} 번째 묶음이 권장(그룹)→대안(소유자) 순서가 아니다"
                );
                $this->assertContains(
                    $triple[2],
                    $permissive,
                    "{$name} 의 소유자 불일치 선택지 {$ordinal} 번째 묶음의 마지막이 최후 수단(777·666)이 아니다"
                );
            }

            $total += count($seen);
        }

        $this->assertGreaterThan(0, $total, '선택지를 한 벌도 보지 못했다 — 검사가 공회전했다');
    }

    /**
     * 권한 안내를 **그리는** 파일을 저장소에서 도출합니다.
     *
     * 다국어 사전(`lang/{ko,en}.php`)에도 같은 키가 있으므로 키 문자열만으로는 갈라지지
     * 않는다 — 사전은 키를 **정의**하고 화면은 `lang()` 으로 **부른다**. 호출 형태로 도출한다.
     *
     * @return array<int, string> 안내를 그리는 파일의 절대 경로 목록
     */
    private function guidanceRenderFiles(): array
    {
        $found = [];
        $root = $this->repoRoot().'/public/install';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, "lang('ownership_mismatch_option_chown')")) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 주석을 지운 소스를 반환합니다 (줄 수 보존).
     *
     * 주석에도 `chown` 이 등장한다 — 실제로 표준 안내 갈래 바로 위에 「chown 의존성 제거」라는
     * 주석이 있다. 지우지 않으면 그 줄이 위반으로 잡혀 검사가 거짓 red 를 낸다.
     *
     * @param  string  $path  소스 파일 경로
     * @return string 주석이 지워진 소스
     */
    private function stripSourceComments(string $path): string
    {
        $source = (string) file_get_contents($path);

        if (str_ends_with($path, '.php')) {
            $out = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                        ? str_repeat("\n", substr_count($token[1], "\n"))
                        : $token[1];

                    continue;
                }
                $out .= $token;
            }

            // 인라인 HTML 은 토크나이저가 통째로 넘기므로 HTML 주석은 따로 지운다.
            return (string) preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
                $out
            );
        }

        // JS — 블록 주석을 먼저 지우고(줄 수 보존), 줄 주석은 앞이 `:` 가 아닐 때만 지운다
        // (`https://` 같은 문자열을 자르지 않기 위해).
        $source = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source
        );

        return (string) preg_replace('#(^|[^:])//.*$#m', '$1', $source);
    }

    /**
     * `$index` 앞쪽에서 패턴에 맞는 **가장 가까운** 줄 번호를 찾습니다.
     *
     * @param  array<int, string>  $lines  줄 배열
     * @param  int  $index  기준 줄 (이 줄은 제외)
     * @param  string  $pattern  찾을 정규식
     * @return int|null 찾은 줄 인덱스 (없으면 null)
     */
    private function nearestPrecedingMatch(array $lines, int $index, string $pattern): ?int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (preg_match($pattern, $lines[$i]) === 1) {
                return $i;
            }
        }

        return null;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function relativeToBase(string $absolute): string
    {
        $base = str_replace('\\', '/', BASE_PATH);
        $path = str_replace('\\', '/', $absolute);

        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        // temp 가 프로젝트 밖이면 `..` 로 거슬러 올라가는 상대 경로를 만든다 — 이 함수의
        // 계약은 「BASE_PATH 기준 상대 경로」이므로 그대로 성립한다.
        $baseParts = explode('/', trim($base, '/'));

        return str_repeat('../', count($baseParts)).ltrim($path, '/');
    }
}
