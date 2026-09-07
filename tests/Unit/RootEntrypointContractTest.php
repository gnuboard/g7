<?php

namespace Tests\Unit;

use App\Extension\Helpers\AbstractRootMarkerFileUpdater;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\RootHtaccessUpdater;
use App\Extension\Helpers\RootIndexShim;
use App\Extension\Helpers\RootWebConfigUpdater;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 루트 진입점 2종(레이아웃 ② — B-2 · D25)의 배포 계약 테스트.
 *
 * 루트 `index.php` shim 과 루트 `.htaccess` 는 릴리즈 ZIP 최상위에 동봉되어 문서 루트를
 * 바꿀 수 없는 호스팅의 진입을 담당한다. 이 파일들의 존재·구성·업데이트 편입은 실행으로
 * 검증할 수 없는 배포 계약이므로(Apache rewrite 는 PHPUnit 밖) 소스 계약으로 잠근다.
 * 종단 동작(노출 차단·자산 200)은 P0 격리 환경 실측이 담당한다 (§9 S4).
 *
 * 커버 축 요약(마커 아님 — 평문): update_wiring — targets / delegation(autoload_block·normalization) / layout(delegated·subdirectory·standard).
 *
 * @scenario entry_file=root_index, update_wiring=targets, update_wiring=delegation, update_wiring=layout
 */
class RootEntrypointContractTest extends TestCase
{
    public function test_root_entry_files_exist(): void
    {
        $this->assertFileExists(base_path('index.php'), '루트 shim 은 릴리즈 ZIP 최상위 동봉 대상이다 (D25)');
        $this->assertFileExists(base_path('.htaccess'), '루트 보호 설정은 릴리즈 ZIP 최상위 동봉 대상이다 (D25)');
    }

    /**
     * 루트 진입 파일 2종이 **릴리즈 페이로드에서 제외되지 않는다** (D25).
     *
     * 「존재한다」만 재면 부족하다 — 파일은 개발자 디스크에 있는데 배포본에는 없을 수 있고,
     * 그러면 D25 의 「ZIP 최상위 동봉」이 성립하지 않는다. 그 상태의 증상은 조용하다:
     * 압축을 풀어도 진입점과 보호 규칙이 없어 사이트가 열리지 않거나, 열리더라도 프로젝트
     * 루트가 통째로 웹에 노출된다.
     *
     * 제외 경로는 두 곳이 정한다 — 공개 저장소 필터와 `git archive` 의 `export-ignore`
     * (`.gitattributes`). 이 테스트는 **배포본 안에서도 성립하는 후자**만 잰다(`git` 명령에
     * 기대지 않는다).
     *
     * 전자(필터 자신)는 배포본에 실리지 않는 내부 도구다. 공개 테스트가 그 파일을 읽으면
     * 배포본을 받은 쪽에서는 원리상 성립할 수 없는 검사가 되고, 「파일이 없으면 건너뛴다」
     * 가드는 그 상태를 통과와 구분하지 못한다. 그래서 그 축은 필터 옆의 내부 회귀
     * 테스트가 같은 파일 목록으로 잰다 — 이 테스트에는 두지 않는다.
     *
     * @effects root_entry_files_are_update_targets
     */
    public function test_root_entry_files_are_not_excluded_from_the_release_payload(): void
    {
        $rootFiles = ['index.php', '.htaccess'];

        $gitattributes = (string) file_get_contents(base_path('.gitattributes'));

        // `.gitattributes` 는 **glob 도 받는다.** 파일명 리터럴 줄만 보면 `* export-ignore`
        // 나 `*.php export-ignore` 한 줄로 두 파일이 `git archive` 산출물에서 빠지는데
        // 게이트는 초록이다 — 이 파일의 첫 줄이 이미 glob(`* text=auto eol=lf`)이라 그
        // 표기가 이 파일의 관례다. 그래서 **패턴을 실제로 적용**해 결과로 판정한다.
        $exportIgnorePatterns = [];
        foreach (preg_split('/\r\n|\n|\r/', $gitattributes) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, 'export-ignore')) {
                continue;
            }
            if (str_contains($line, '-export-ignore')) {
                continue;
            }
            $pattern = (string) (preg_split('/\s+/', $line)[0] ?? '');
            if ($pattern !== '') {
                $exportIgnorePatterns[] = ltrim($pattern, '/');
            }
        }

        foreach ($rootFiles as $file) {
            foreach ($exportIgnorePatterns as $pattern) {
                $this->assertFalse(
                    fnmatch($pattern, $file),
                    $file.' 가 export-ignore 패턴 `'.$pattern.'` 에 걸린다 — git archive 산출물에서 빠진다'
                );
            }
        }

        // gitignore 축의 모집단은 루트 진입 파일 **둘보다 넓다.** 릴리즈 페이로드는 git
        // 오브젝트에서 만들어지므로 무시된 파일은 두 목록(공개 저장소 제외 목록·export-ignore)
        // 어디에도 나타나지 않은 채 빠진다 — 그것이 이 축의 존재 이유인데, 정작 그 축만 둘을 봤다.
        // 배치를 성립시키는 나머지 자산이 같은 방식으로 빠지면 증상은 각각 다르지만 전부
        // 조용하다: `web.config` 가 빠지면 IIS 사이트가 위임 없이 프로젝트 루트를 열고,
        // `public/install/` 이 빠지면 신규 설치자가 인스톨러 404 를 받는다.
        $this->assertRootFilesAreNotIgnoredByGit(self::deploymentCriticalPaths());
    }

    /**
     * 배포본에 **반드시 실려야 하는** 경로를 배포 대상 목록에서 도출합니다.
     *
     * 손으로 열거하지 않는다 — 열거하면 새 자산이 늘 때 이 목록만 그대로 남아, 그 자산이
     * 게이트에 보이지 않는 채로 배포에서 빠질 수 있다. 도출 근거는 코어 업데이트가
     * 「함께 갱신한다」고 선언한 대상(`app.update.targets`)이며, 그중 이 안이 신설한
     * 배치 자산과 인스톨러를 고른다.
     *
     * @return list<string> 저장소 루트 상대 경로
     */
    private static function deploymentCriticalPaths(): array
    {
        /** @var list<string> $targets */
        $targets = (array) config('app.update.targets', []);

        // 배치를 성립시키는 자산 — 하나라도 빠지면 그 서버에서 사이트가 열리지 않거나
        // 프로젝트 루트가 노출된다.
        $wanted = ['index.php', '.htaccess', 'web.config', 'deploy', 'public'];

        $paths = array_values(array_intersect($targets, $wanted));

        // 인스톨러는 `public` 하위라 위 교집합에 개별 항목으로 잡히지 않는다. 그 디렉토리
        // 전체가 무시되는 형태를 따로 잰다 — 빠지면 신규 설치가 통째로 불가능해진다.
        $paths[] = 'public/install';

        return $paths;
    }

    /**
     * 루트 파일이 **`.gitignore` 에 걸리지 않는다.**
     *
     * 위 두 축(공개 저장소 제외 목록 · `export-ignore`)만으로는 이 절의 선언
     * (「파일은 개발자 디스크에 있는데 배포본에는 없을 수 있고」)이 성립하지 않는다.
     * 릴리즈 페이로드는 git 오브젝트에서 만들어지므로(`release-lib.sh` 가 격리 clone 의
     * 커밋 tree 를 읽는다), **무시된 파일은 어느 목록에도 나타나지 않은 채** 산출물에서
     * 빠진다. 그 상태의 증상은 조용하다 — 압축을 풀면 진입점과 보호 규칙이 없어
     * 프로젝트 루트가 통째로 웹에 노출된다.
     *
     * 추적 여부(`git ls-files`)는 **재지 않는다.** 아직 커밋되지 않은 신규 파일은
     * `git status` 에 그대로 드러나고 커밋 시점에 해소되는 정상 상태라, 그것까지 막으면
     * 이 검사가 작업 중 내내 red 로 상주해 아무 신호도 주지 못한다. 조용히 새는 축은
     * 무시 설정 하나뿐이고, 이 검사는 그 하나를 잰다.
     *
     * @param  list<string>  $rootFiles  루트 상대 경로 목록
     */
    private function assertRootFilesAreNotIgnoredByGit(array $rootFiles): void
    {
        // 배포본에는 `.git` 이 없다 — 그 환경에서 이 축은 잴 대상이 아니다.
        if (! is_dir(base_path('.git'))) {
            $this->assertTrue(true, '저장소 밖(배포본)에서는 이 축을 재지 않는다');

            return;
        }

        foreach ($rootFiles as $file) {
            $result = Process::path(base_path())->run('git check-ignore -- '.escapeshellarg($file));

            // `check-ignore` 는 무시되면 **0**, 아니면 **1** 을 돌려준다. 그 밖의 값
            // (128 = 저장소가 아님·`dubious ownership`, 129 = 잘못된 인자, 127 = git 부재)은
            // **판정이 아니라 실패**다. 종전 판정은 `assertNotSame(0, …)` 한 방향이라 그
            // 값들을 전부 통과시켰다 — git 이 PATH 에 없거나 도커 볼륨 마운트에서 소유권
            // 경고가 나는 CI 에서는 이 축이 **영구히 무조건 초록**이 되고, 그 사실을 알려
            // 주는 신호가 없다. `.git` 디렉토리 존재 확인은 바이너리 가용성을 보장하지 않는다.
            $this->assertContains(
                $result->exitCode(),
                [0, 1],
                'git check-ignore 가 판정을 돌려주지 않았다 (exit '.$result->exitCode().') — '
                .'이 축은 그 상태에서 아무것도 재지 못한다. 그대로 두면 「무시되지 않음」과 '
                .'구분되지 않아 배포 누락을 영원히 놓친다: '.trim($result->errorOutput())
            );

            $this->assertNotSame(
                0,
                $result->exitCode(),
                $file.' 가 .gitignore 에 걸린다 — 릴리즈 페이로드는 git 에서 만들어지므로 '
                .'그 파일이 배포본에서 조용히 빠지고, 레이아웃 ② 사이트는 프로젝트 루트가 통째로 열린다'
            );
        }
    }

    /**
     * @effects root_shim_delegates_to_public_front_controller
     */
    public function test_root_shim_delegates_to_public_front_controller_without_duplicating_autoload(): void
    {
        $shim = (string) file_get_contents(base_path('index.php'));

        // 위임 — 실제 프런트 컨트롤러는 public/index.php 하나다.
        //
        // 판정은 런타임과 **같은 지점**(`RootIndexShim`)을 쓴다. 종전에는 이 축이 자기
        // 정규식을 갖고 있었고 런타임은 공백까지 고정한 리터럴을 봤다 — 표기가 갈리면
        // 이 게이트는 초록인데 매 코어 업데이트마다 거짓 경고 + 쓸모없는 사본이 쌓였다
        // (14차 G3 라운드).
        $this->assertTrue(
            RootIndexShim::matches($shim),
            '루트 shim 은 public/index.php 로 위임해야 한다'
        );

        // R38 승계는 복제가 아니라 위임으로 이룬다 — shim 에 오토로드 블록 사본이 생기면
        // 두 사본이 어긋나는 순간 확장이 조용히 죽는다.
        $this->assertStringNotContainsString('addPsr4', $shim, '확장 오토로드 블록을 shim 에 복제하지 않는다 (R38 — 위임 승계)');

        // 위임 대상 부재 방어 (G3 회차 15). 전송 중단·부분 압축 해제로 `public/index.php` 가
        // 없으면 PHP 의 fatal 안내가 **프로젝트 루트의 절대 경로**를 화면에 싣는다. 가드는
        // 위임 **앞**에 있어야 하고(뒤면 도달하지 않는다), 안내문은 경로를 싣지 않는다.
        $guardAt = strpos($shim, "is_file(__DIR__.'/public/index.php')");
        $delegateAt = strpos($shim, "require __DIR__.'/public/index.php'");
        $this->assertNotFalse($guardAt, 'shim 에 위임 대상 부재 가드가 없다 — 부재 시 fatal 이 절대 경로를 유출한다');
        $this->assertNotFalse($delegateAt);
        $this->assertLessThan($delegateAt, $guardAt, '가드가 위임 뒤에 있다 — require 가 먼저 fatal 로 끝나 가드에 도달하지 않는다');
        $this->assertStringContainsString('http_response_code(503)', $shim, '부재는 서버 측 사정이다 — 503 으로 알린다');
        $this->assertDoesNotMatchRegularExpression(
            '/echo\s+[^;]*__DIR__/',
            $shim,
            '안내문에 __DIR__ 가 실린다 — 경로 유출을 막으려는 가드가 스스로 경로를 낸다'
        );

        // 위임 대상이 실제로 그 블록을 갖고 있는지 (R38 의 실체 확인).
        $front = (string) file_get_contents(public_path('index.php'));
        $this->assertStringContainsString('addPsr4', $front);
        $this->assertStringContainsString('src_classmap', $front);
        $this->assertStringContainsString('vendor_autoloads', $front);
    }

    /**
     * @scenario mount_shape=server_alias, deployment_layout=docroot_project_root
     *
     * @effects delegation_rule_hands_the_in_site_path_to_the_entry_script
     */
    public function test_root_htaccess_carries_managed_block_and_delegation_rule(): void
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        // 코어 업데이트가 교체하는 마커 블록 (D25) — 갱신기가 이 마커로 범위를 잡는다.
        $this->assertNotNull(
            RootHtaccessUpdater::extractBlock($htaccess),
            '루트 .htaccess 는 갱신기가 인식하는 G7 마커 블록을 가져야 한다 (D25)'
        );

        // 모든 요청을 public/ 아래로 위임하는 규칙 — 프로젝트 루트 파일이 직접 서빙되지 않는다.
        $this->assertStringContainsString('public/$1', $htaccess, '루트 요청을 public/ 으로 위임하는 rewrite 가 있어야 한다');
        $this->assertStringContainsString('(?!public/)', $htaccess, '이미 public/ 으로 향한 요청은 재위임하지 않아야 한다 (루프 방지)');

        // 위임 규칙은 **사이트 안에서의 경로**를 진입 스크립트에 넘겨야 한다.
        //
        // 이 값이 없으면 `public/index.php` 의 마운트 접두사 복원 분기가 통째로 죽는다 —
        // 그리고 죽어도 아무것도 울지 않는다. 그 분기는 `Alias` 마운트에서만 결과가 달라지고
        // (다른 배치에서는 폴백 값과 같다), 그 배치에서 실패하는 모습은 예외가 아니라
        // 「미설치 안내가 사이트 밖으로 보낸다」는 조용한 오동작이기 때문이다.
        // 그래서 소비처(PHP)가 아니라 **생산처(이 규칙)** 를 여기서 고정한다.
        $this->assertMatchesRegularExpression(
            '~RewriteRule\s+\^\(\(\?!public/\)\.\*\)\$\s+public/\$1\s+\[[^]]*\bE=G7_ROUTE:\$1\b~',
            $htaccess,
            '위임 규칙이 사이트 안 경로를 G7_ROUTE 로 넘기지 않는다 — Alias 마운트에서 진입 스크립트가 접두사를 복원할 근거를 잃는다'
        );

        // mod_rewrite 부재 시의 마지막 방어선 — 숨김 파일(.env 등) 직접 접근 차단.
        $this->assertStringContainsString('<FilesMatch "^\.">', $htaccess, 'mod_rewrite 없는 서버에서도 숨김 파일은 차단되어야 한다');

        // SSL 인증서 자동 갱신(ACME HTTP-01)은 문서 루트의 `.well-known/` 을 읽는다 —
        // 블랭킷 위임이 이 경로까지 가져가면 갱신이 조용히 실패한다.
        $this->assertStringContainsString('^\.well-known/', $htaccess, '.well-known/ 은 위임에서 제외되어야 한다 (ACME HTTP-01)');

        // **순서가 곧 판정이다.** 예외 규칙이 위임 규칙보다 뒤에 있으면 위임이 먼저
        // 매치해 `[L]` 로 끝나므로 그 예외는 도달하지 않는다 — 존재만 재면 그 상태가
        // 그대로 통과한다. 증상은 인증서 갱신이 만료 직전까지 조용히 실패하는 것이다.
        // 판정은 **지시자 줄만** 본다. 파일 전문을 훑으면 위쪽 설명 주석에 같은 패턴을
        // 적는 편집이 들어올 때 게이트가 그 주석 위치를 잡아 초록이 된다 — 형제 축
        // (`RedirectMatch 403` 대조)은 이미 `directiveLines()` 를 쓰는데 이 축만 원문
        // `strpos` 였다.
        $orderSource = implode("\n", self::directiveLines(base_path('.htaccess')));

        $acmeAt = strpos($orderSource, '^\.well-known/');
        $delegateAt = strpos($orderSource, '^((?!public/)');

        $this->assertIsInt($acmeAt, '루트 .well-known 예외 규칙을 찾지 못했다 — 이 순서 판정이 성립하지 않는다');
        $this->assertIsInt($delegateAt, '루트 위임 규칙을 찾지 못했다 — 이 순서 판정이 성립하지 않는다');
        $this->assertLessThan(
            $delegateAt,
            $acmeAt,
            '.well-known/ 예외가 위임 규칙보다 뒤에 있다 — 위임이 먼저 매치해 [L] 로 끝나므로 '
            .'그 예외는 도달하지 않고 SSL 인증서 자동 갱신이 조용히 실패한다'
        );

        // 정본 주소 고정은 **이 파일이 소유하지 않는다.** mod_rewrite 의 디렉토리별 설정은
        // 상속되지 않으므로, `public/` 이 자기 `RewriteEngine On` 을 선언하는 이상
        // `/public/…` 요청에는 그쪽 규칙집이 이 파일을 대체한다 — 여기 적은 `^public/…`
        // 규칙은 평가될 기회 자체가 없다(P0 Apache 실측: 301 이 아니라 200).
        // 판정은 **지시자 줄**로 한다 — 주석에 그 패턴을 설명으로 적는 것은 정상이고,
        // 금지 대상은 실제로 등록되는 `RewriteRule` 이다.
        $directives = implode("\n", array_filter(
            // 개행 분리에 `\R` 을 쓰지 않는다 — UTF-8 모드가 아니면 그 이스케이프가
            // **바이트 0x85**(NEL)도 개행으로 본다. 한국어 주석의 흔한 글자가 그 바이트로
            // 끝나(`종` = EC A2 85) 한 줄이 글자 중간에서 쪼개지고, 뒷조각은 주석 표식을
            // 잃어 아래 필터를 그대로 지나간다 — 「주석은 보지 않는다」는 이 판정의 전제가
            // 깨진다. 실제 줄 종결자만 본다.
            preg_split('~\r\n|\r|\n~', $htaccess) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')
        ));

        $this->assertDoesNotMatchRegularExpression(
            '~RewriteRule\s+\^public/~',
            $directives,
            '정본 주소 301 을 루트 .htaccess 에 두면 죽은 규칙이 된다 — public/.htaccess 가 소유한다'
        );

        // 배치 판정을 파일시스템 조회로 만드는 형태는 **이 파일에서도** 금지다. 종전에는
        // 그 판정이 `public/.htaccess` 한 파일에만 돌아, 위임 규칙에 배치 조회를 붙이는
        // 편집이 들어와도 아무것도 울리지 않았다 — 그 위임은 이 파일이 소유하므로 여기가
        // 그 형태가 들어오기 가장 쉬운 자리다.
        $this->assertDirectivesDoNotProbeTheFilesystemForLayout(
            self::directiveLines(base_path('.htaccess')),
            '루트 .htaccess'
        );
    }

    /**
     * 정본 주소 고정(`/public/…` → `/…` 301)이 실제로 평가되는 파일에 있고, 조건을 갖춘다.
     *
     * 이 축이 필요한 이유: `.htaccess` 는 Apache 만 해석하므로 PHPUnit·vitest 어느 것도
     * 그 파일을 **실행**할 수 없다. 그래서 「규칙이 적혀 있다」와 「규칙이 동작한다」가
     * 갈릴 수 있고, 실제로 갈려 있었다 — 루트 파일에 적힌 판본은 여섯 라운드 동안
     * 한 번도 발화하지 않았다. 여기서 고정하는 것은 **어느 파일이 소유하는가**와
     * **어떤 조건이 어떤 규칙에 붙어 있는가** 둘이며, 발화 여부는 P0 Apache 실측이 소유한다.
     *
     * 판정은 **지시자 줄만** 본다. 종전 판본은 파일 전문에 `assertStringContainsString` 을
     * 걸었는데 조건 문자열이 바로 위 설명 주석에도 그대로 있어, `RewriteCond` 세 줄을
     * 지워도 초록이었다 — 그 상태의 실제 결과는 사이트 전 요청 무한 301 이다. 조건은
     * 규칙과 **연속 블록**이어야 의미가 있으므로 인접성까지 고정한다.
     *
     * @scenario entry_file=public_htaccess, canonical_address=layout_gate, mount_prefix_shape=contains_public_segment, route_path_shape=contains_public_segment
     *
     * @effects canonical_address_rule_is_owned_by_the_file_that_evaluates_it, canonical_address_targets_come_from_the_decoded_request_uri, slash_correction_targets_come_from_the_original_request_line, mount_prefix_public_segment_is_not_mistaken_for_the_site_public_dir, route_path_public_segment_survives_the_canonical_redirect
     */
    public function test_canonical_address_rule_lives_where_it_is_evaluated(): void
    {
        $directives = self::directiveLines(base_path('public/.htaccess'));

        // (0) 규칙집을 켜는 한 줄. 이것이 없으면 아래 모든 `RewriteCond`/`RewriteRule` 이
        //     **한 줄도 평가되지 않는다** — 프런트 컨트롤러 위임까지 죽어 사이트 전 라우트가
        //     404 가 되고, 레이아웃 ② 에서는 루트 위임도 함께 죽어 프로젝트 루트가 그대로
        //     서빙된다. 종전에는 이 한 줄을 지워도 저장소의 어떤 검사도 red 가 되지 않았다
        //     (지시자 목록을 리터럴로 대조하는 판정에 이 이름이 한 번도 없었다).
        $this->assertContains(
            'RewriteEngine On',
            $directives,
            'public/.htaccess 에 RewriteEngine On 이 없다 — 아래 규칙이 한 줄도 평가되지 않는다'
        );

        // (A) 레이아웃 판정은 **파일시스템도 문서 루트도 조회하지 않는다.**
        //
        //     종전 판본은 `%{DOCUMENT_ROOT}` 아래에 `public/index.php` 가 있는지로 배치를
        //     갈랐다. 그 값은 웹서버의 `DocumentRoot` 지시어이지 사이트가 실제로 놓인
        //     위치가 아니다 — 운영자가 문서 루트 **밖**을 `Alias` 로 여는 배치에서는 두
        //     조회가 함께 빗나가 판정이 거짓이 되고 (B)(C)(D) 가 통째로 꺼진다. 별칭 대상이
        //     문서 루트와 **같은** 디렉토리이면 우연히 성립하므로, 그 형태로만 재면 통과한다
        //     (16차 실측이 그 배치였다). 4마운트 실측: 문서 루트·물리 하위 폴더·별칭(안)은
        //     301, 별칭(밖)만 200 — 제2 주소가 열린 상태.
        //
        //     지금은 mod_rewrite 가 per-directory 규칙에 넘겨 주는 **매치 문자열**로 가른다.
        //     레이아웃 ②·하위 폴더·별칭에서는 URL 의 `/public/` 이 이 디렉토리 자신이라
        //     매치 문자열에 남지 않고, 표준 배치에서는 하위 디렉토리를 가리키므로 남는다.
        //     그래서 세 규칙의 패턴 `!^public/` 이 곧 배치 판정이다.
        //     판정은 **리터럴 한 개**가 아니라 「파일시스템으로 위치를 캐묻는 형태」 전부를
        //     본다. `%{DOCUMENT_ROOT}` 만 막으면 `%{ENV:DOCUMENT_ROOT}`·`%{REQUEST_FILENAME}`
        //     로 표기만 바꾼 같은 판정이 그대로 통과하고, 그 상태의 증상은 이 절이 고친
        //     결함(별칭 마운트에서 규칙 전체가 꺼짐)과 완전히 같다.
        //     `%{REQUEST_FILENAME}` 자체는 금지 대상이 아니다 — (C)(D) 가 `-d`/`!-d` 로
        //     디렉토리 여부를 묻는 것은 배치 판정이 아니라 슬래시 보정의 근거다. 막는 것은
        //     **문서 루트에서 파생된 위치**와 **진입 스크립트 존재로 배치를 가르는 형태** 둘이다.
        $this->assertDirectivesDoNotProbeTheFilesystemForLayout($directives, 'public/.htaccess');

        // (B-0) 인코딩으로 가린 `/public/` 은 거부한다. **(B) 보다 앞에 있어야 한다** —
        //       뒤에 두면 (B) 가 먼저 301 을 내보내 이 거부는 영원히 도달하지 않는다.
        // 아래 다섯 블록은 변수에 담아 두고 (F) 가 그 목록에서 하한을 **도출**한다 —
        // 리터럴 하한은 블록이 조용히 줄어드는 것을 보지 못한다.
        $blockB0 = [
            'RewriteCond %{ENV:REDIRECT_STATUS} ^$',
            'RewriteCond %{REQUEST_URI} ^(.*?)/public/(.*)$ [NC]',
            'RewriteCond %{THE_REQUEST} !^[A-Z]+\s((?:/[^/\s?](?:[^\s?]*[^/\s?])?)?)/public/ [NC]',
            'RewriteRule !^public/ - [R=404,L]',
        ];

        $this->assertDirectiveBlock($directives, $blockB0, '인코딩으로 가린 /public/ 을 거부하는 규칙이 조건과 함께 있지 않다');

        // (B) 직접 요청의 정본 주소 301. 내부 리다이렉트(위임)를 제외하지 않으면 무한 왕복이다.
        //
        //     목적지는 **디코딩된 `%{REQUEST_URI}`** 에서 만든다. 이 규칙은 직접 요청 전용이라
        //     위임으로 값이 다시 쓰이기 전이고, 그 시점의 `REQUEST_URI` 는 마운트 접두사를
        //     그대로 갖는다. 요청 라인 원문에서 만들면 **마운트 접두사 자신의 `public`
        //     세그먼트**를 사이트의 공개 폴더로 오인해, 접두사에 그 이름이 든 배치에서
        //     사이트 **밖** 주소로 301 이 나간다(실측: `/my/public/site/%70ublic/favicon.ico`
        //     → `Location: /my/site/%70ublic/…`).
        //
        //     자를 **자리**는 그 값의 정규식 탐욕성이 아니라 per-directory **매치 문자열**이
        //     정한다. `%{REQUEST_URI}` 만 보면 한 URL 에 `/public/` 이 여러 번 나올 때 탐욕
        //     매칭이 마지막 것을 고르고, 그러면 라우트 자신의 `public` 세그먼트가 잘린다
        //     (실측: 레이아웃 ② `/public/board/public/1` → `Location: /public/board/1`,
        //     표준 배치 `/board/public/1` → `Location: /board/1`). 그래서 조건이 검사
        //     문자열에 `$1` 을 이어 붙이고 패턴이 꼬리를 백레퍼런스로 대조한다.
        $blockB = [
            'RewriteCond %{ENV:REDIRECT_STATUS} ^$',
            'RewriteCond %{REQUEST_URI} !//',
            'RewriteCond %{REQUEST_URI}::$1 ^((?:/[^/].*)?)/public/(.*)::\2$ [NC]',
            'RewriteRule ^(?!public/)(.*)$ %1/%2 [R=301,L]',
        ];

        $this->assertDirectiveBlock($directives, $blockB, '직접 요청의 정본 주소 301 규칙이 조건과 함께 있지 않다');

        $this->assertLessThan(
            array_search('RewriteRule ^(?!public/)(.*)$ %1/%2 [R=301,L]', $directives, true),
            array_search('RewriteRule !^public/ - [R=404,L]', $directives, true),
            '거부 규칙이 정본 301 규칙보다 뒤에 있다 — 301 이 먼저 매치해 거부가 도달하지 않는다'
        );

        // (C) 위임된 디렉토리 요청 — mod_dir 이 `/public` 을 실은 주소를 공표하기 전에 앞선다.
        $blockC = [
            'RewriteCond %{REQUEST_FILENAME} -d',
            'RewriteCond %{REQUEST_URI} ^(.*?)/public/(.*[^/])$ [NC]',
            'RewriteCond %{THE_REQUEST} ^[A-Z]+\s(/[^/\s?](?:[^\s?]*[^\s?/])?)[\s?]',
            'RewriteRule !^public/ %1/ [R=301,NE,L]',
        ];

        $this->assertDirectiveBlock($directives, $blockC, '위임된 디렉토리 요청의 슬래시 보정이 없다 — mod_dir 이 /public 주소를 공표한다');

        // (D) 위임된 비-디렉토리 요청의 트레일링 슬래시 — 정본 주소로 돌린다.
        //     이 규칙이 `/public` 을 실어 돌려보내면 (B) 와 맞물려 무한 301 이 된다(실측).
        //
        //     첫 조건은 **루트 실존 디렉토리의 위임을 제외**한다. 루트 `.htaccess` 가 `-d`
        //     위임에 `G7_ROOTDIR` 표식을 싣는데, 그 요청(`/app/` → `public/app/`)은 `public/`
        //     아래에 디렉토리가 없어 `!-d` 를 통과하고, 여기서 슬래시를 떼면 mod_dir 가 다시
        //     붙여 무한 301 이 된다(P0 실측 재현). 표식이 없던 시절의 대안은 루트의
        //     `DirectorySlash Off` 였는데 그것은 마운트 루트 자신(`/g7sub`)을 403 으로 만든다.
        $blockD = [
            'RewriteCond %{ENV:REDIRECT_G7_ROOTDIR} !=1',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteCond %{REQUEST_URI} ^(.*?)/public/(.+)/$ [NC]',
            'RewriteCond %{THE_REQUEST} ^[A-Z]+\s(/[^/\s?](?:[^\s?]*[^\s?/])?)/[\s?]',
            'RewriteRule !^public/ %1 [R=301,NE,L]',
        ];

        $this->assertDirectiveBlock(
            $directives,
            $blockD,
            '위임된 요청의 트레일링 슬래시 정규화가 정본 주소로 돌지 않는다. '
            .'`REDIRECT_G7_ROOTDIR` 의 `REDIRECT_` 는 **한 겹**이다 — 루트 `.htaccess` 의 위임은 내부 리다이렉트 1홉이라 '
            .'Apache 가 환경변수에 그 접두를 한 번 붙인다. 위임 홉이 늘면(루트 규칙이 두 번 위임하거나 중간 규칙집이 끼면) '
            .'이 조건의 접두도 함께 늘려야 한다 — 그러지 않으면 조건이 빈 값과 비교돼 루트 실존 디렉토리 요청이 무한 301 로 돌아간다'
        );

        // (E) 표준 배치의 스톡 규칙은 **직접 요청에만** 남는다.
        //     빈 세그먼트 가드(`!//`)는 형제 (B) 와 같은 이유로 함께 선다 — 그 축 자체는
        //     `test_every_public_htaccess_redirect_guards_empty_segments` 가 모집단
        //     도출형으로 소유하고, 여기서는 순서와 함께 리터럴로도 고정한다.
        $blockE = [
            'RewriteCond %{ENV:REDIRECT_STATUS} ^$',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteCond %{REQUEST_URI} !//',
            'RewriteCond %{REQUEST_URI} (.+)/$',
            'RewriteRule ^ %1 [L,R=301]',
        ];

        $this->assertDirectiveBlock($directives, $blockE, '스톡 트레일링 슬래시 규칙에 내부 리다이렉트 제외 또는 빈 세그먼트 가드가 없다');

        // (F) 성질 판정 — 리터럴을 같이 고쳐도 지나갈 수 없는 축.
        //
        //     `%{THE_REQUEST}` 는 **요청 라인 원문**이라 두 가지가 따라온다. 위 리터럴만
        //     고정하면 다음 편집이 이 둘을 조용히 잃을 수 있으므로 성질로도 잠근다.
        //
        //     현재 이 필터가 잡는 세 줄((B-0)(C)(D))은 위 `assertDirectiveBlock` 이 이미
        //     **전문 리터럴**로 고정하고 있어, 그 세 줄만 놓고 보면 아래 성질 단언이
        //     단독으로 발화할 기회가 없다(리터럴이 먼저 red 를 낸다). 그것은 결함이 아니라
        //     **2선 방어**다 — 이 축이 유일한 방어선이 되는 경우는 ① 새로 추가되는
        //     `%{THE_REQUEST}` 조건 ② 리터럴 목록을 함께 고치는 편집 두 가지이고, 후자가
        //     정확히 이 축을 만든 이유다. 다음 라운드가 「사문」으로 오독하지 않도록
        //     그 판정을 여기 남긴다 (7차 G3 라운드 E14).
        //
        //       * 백레퍼런스는 `/` 로 시작하는 형태만 받는다. RFC 9112 는 서버가
        //         `GET http://호스트/경로 HTTP/1.1`(absolute-form)도 받아들이게 하므로,
        //         앵커가 없으면 그 호스트가 백레퍼런스에 실려 `Location:` 의 목적지를
        //         **요청자가 지정**한다(실측: `Location: http://evil.example/x`).
        //       * `NE` 가 없으면 이미 인코딩된 값을 mod_rewrite 가 한 번 더 이스케이프해
        //         `%20` 이 `%2520` 으로 나간다(실측). 마운트 종류와 무관하게 걸린다.
        $theRequestConds = array_values(array_filter(
            $directives,
            static fn (string $line): bool => str_starts_with($line, 'RewriteCond %{THE_REQUEST}')
        ));

        // 하한은 리터럴이 아니라 **위에서 리터럴로 고정한 블록들**에서 도출한다. 종전에는
        // `3` 이 손으로 적혀 있었는데, 그 숫자는 블록 목록과 아무 연결이 없어 새 블록이
        // `%{THE_REQUEST}` 조건을 들여와도 따라 오르지 않고(그 조건은 성질 판정을 받지
        // 않은 채 남는다), 반대로 블록에서 그 조건이 빠지면 리터럴을 같이 내려 주면 그만이었다.
        // 이제 하한이 블록 정의에 묶여 있어 그 두 방향이 모두 자동으로 반영된다.
        $pinnedTheRequestConds = collect([$blockB0, $blockB, $blockC, $blockD, $blockE])
            ->flatten()
            ->filter(static fn (string $line): bool => str_starts_with($line, 'RewriteCond %{THE_REQUEST}'))
            ->count();

        $this->assertGreaterThan(
            0,
            $pinnedTheRequestConds,
            '하한 도출이 비었다 — 위 블록에서 THE_REQUEST 조건이 통째로 사라졌다'
        );

        $this->assertGreaterThanOrEqual(
            $pinnedTheRequestConds,
            count($theRequestConds),
            'THE_REQUEST 조건이 위 블록이 고정한 개수보다 적다 — 이 검사가 그만큼 덜 잰다'
        );

        foreach ($theRequestConds as $line) {
            $this->assertTrue(
                str_contains($line, '\s(/') || str_contains($line, '\s((?:/'),
                '`'.$line.'` 의 백레퍼런스가 `/` 로 앵커되지 않았다 — absolute-form 요청 라인의 호스트가 Location 에 실린다'
            );

            // 앵커의 **존재**만으로는 부족하다. `/` 한 글자만 캡처되면 목적지가 `//호스트/…`
            // 로 시작해 브라우저가 그것을 프로토콜 상대 URL 로 읽는다 — 즉 목적지 호스트를
            // 요청자가 지정한다(실측: 수정 전 `GET //public/evil.example/p` → `Location:
            // //evil.example/p`). 그래서 앵커 다음 글자와 그룹의 마지막 글자를 **둘 다**
            // 비-슬래시로 묶는다. 그 제약이 사라지면 이 단언이 red 가 된다.
            $this->assertStringContainsString(
                '[^/',
                $line,
                '`'.$line.'` 의 백레퍼런스가 `/` 로 시작·종료할 수 있다 — 목적지가 `//호스트/…` 가 되어 프로토콜 상대 리다이렉트가 열린다'
            );
        }

        // 레이아웃 판정을 패턴으로 갖는 규칙이 몇 개인지부터 고정한다.
        //
        // 표기는 둘이고 묻는 것은 하나다 — `!^public/`(부정)과 `^(?!public/)(.*)$`(선행 부정
        // + 캡처)는 「매치 문자열이 `public/` 로 시작하지 않는다」로 같다. 뒤엣것만 그 값을
        // `$1` 로 돌려주므로 (B) 가 그 형태를 쓴다. 한 표기만 세면 (B) 가 표기를 바꾸는 순간
        // 개수가 3 이 되어 red 가 나는데, 그건 결함이 아니라 판정식이 표기를 모르는 것이다.
        $isLayoutGate = static fn (string $line): bool => str_starts_with($line, 'RewriteRule !^public/')
            || str_starts_with($line, 'RewriteRule ^(?!public/)');

        $layoutRules = array_values(array_filter($directives, $isLayoutGate));

        $this->assertCount(
            4,
            $layoutRules,
            '레이아웃 판정을 패턴으로 갖는 규칙이 4개가 아니다 — (B-0)(B)(C)(D) 중 하나가 판정을 잃었다'
        );

        // `NE` 의 필요 여부는 **목적지를 어느 값에서 만들었는가**가 정한다. `%N` 은 마지막으로
        // 매치한 조건의 백레퍼런스이므로, 규칙 **직전 조건**이 무엇인지가 곧 그 답이다.
        //
        //   * `%{THE_REQUEST}` — 요청 라인 **원문**(인코딩된 값). NE 가 없으면 mod_rewrite 가
        //     한 번 더 이스케이프해 `%20` 이 `%2520` 으로 나간다(실측).
        //   * `%{REQUEST_URI}` — 서버가 **디코딩한** 값. NE 를 붙이면 반대로 인코딩이 모자라게
        //     나가 공백·한글이 든 주소가 깨진다.
        //
        // 한 방향으로만 재면 반대쪽 실수가 그대로 통과하므로 **양방향**을 단언한다. 종전
        // 판본은 「NE 가 있는가」만 봤고, 그래서 디코딩된 값에서 목적지를 만드는 규칙에 NE 가
        // 붙는 실수는 잡히지 않았다.
        $redirectRules = 0;

        foreach ($directives as $index => $line) {
            if (! $isLayoutGate($line) || ! str_contains($line, 'R=301')) {
                continue;
            }

            $source = null;

            for ($back = $index - 1; $back >= 0; $back--) {
                if (str_starts_with($directives[$back], 'RewriteCond ')) {
                    $source = $directives[$back];
                    break;
                }
            }

            $this->assertNotNull(
                $source,
                '`'.$line.'` 앞에 조건이 없다 — 목적지가 어느 값에서 왔는지 판정할 수 없다'
            );

            $redirectRules++;

            if (str_starts_with($source, 'RewriteCond %{THE_REQUEST}')) {
                $this->assertStringContainsString(
                    'NE',
                    $line,
                    '`'.$line.'` 은 요청 라인 원문에서 목적지를 만드는데 NE 가 없다 — %20 이 %2520 으로 나간다'
                );

                // 캡처 그룹은 **시작과 끝 둘 다** 비-슬래시로 묶여야 한다. 「`[^/` 가
                // 어딘가 있다」로 재면 한쪽만 풀어도 통과하는데, 시작이 풀리면 목적지가
                // `//호스트/…`(프로토콜 상대 = 오픈 리다이렉트)가 되고 끝이 풀리면 목적지
                // 끝에 슬래시가 남아 (C)(D) 가 서로 되던진다. 두 실수는 방향이 달라
                // 한 축으로는 잡히지 않는다.
                $this->assertMatchesRegularExpression(
                    '~\(/\[\^/~',
                    $source,
                    '`'.$source.'` 의 캡처 그룹 **시작**이 비-슬래시로 앵커되지 않았다 — 프로토콜 상대 리다이렉트가 열린다'
                );

                $this->assertMatchesRegularExpression(
                    '~\[\^[^\]]*/[^\]]*\]\)?\)~',
                    $source,
                    '`'.$source.'` 의 캡처 그룹 **끝**이 비-슬래시로 앵커되지 않았다 — 목적지 끝에 슬래시가 남아 (C)(D) 가 서로 되던진다'
                );

                continue;
            }

            $this->assertStringStartsWith(
                'RewriteCond %{REQUEST_URI}',
                $source,
                '`'.$line.'` 의 목적지 출처가 THE_REQUEST 도 REQUEST_URI 도 아니다 — 이 검사가 판정할 수 없는 축이다'
            );

            // 디코딩된 값에서 만든 목적지도 같은 앵커 성질을 갖는다. 접두사 그룹이 `/` 한
            // 글자를 허용하면 목적지가 `//호스트/…` 가 되고 브라우저는 그것을 프로토콜 상대
            // URL 로 읽는다 — 즉 목적지 호스트를 요청자가 지정한다.
            $this->assertStringContainsString(
                '[^/',
                $source,
                '`'.$source.'` 의 접두사 그룹이 비-슬래시로 앵커되지 않았다 — 프로토콜 상대 리다이렉트가 열린다'
            );

            // 자를 **자리**는 매치 문자열이 정해야 한다. 디코딩된 URI 하나만 보면 한 주소에
            // `/public/` 이 여러 번 나올 때 어느 것이 사이트의 공개 폴더인지 알 방법이 없다 —
            // 탐욕 매칭은 마지막 것을, 게으른 매칭은 첫 것을 고르는데 **둘 다 어떤 배치에서는
            // 틀린다**: 접두사에 `public` 이 든 마운트는 첫 것이 접두사의 것이고, 라우트에
            // `public` 이 든 주소는 마지막 것이 라우트의 것이다. 실측으로 나온 형태는
            // 레이아웃 ② `/public/board/public/1` → `Location: /public/board/1`(접두는 남고
            // 라우트 세그먼트가 사라짐)과, 이 규칙이 관여하지 말아야 할 표준 배치에서의
            // `/storage/public/img.png` → `Location: /storage/img.png` 다. 오류도 로그도 없이
            // 다른 화면이 열릴 뿐이라 응답 코드만 보는 검사로는 드러나지 않는다.
            //
            // 유일한 정답은 서버가 이미 계산해 둔 per-directory 매치 문자열이므로, 조건이 그
            // 값을 검사 문자열 꼬리에 이어 붙이고 패턴이 백레퍼런스로 대조해야 한다.
            $this->assertStringContainsString(
                '::$1',
                $source,
                '`'.$source.'` 가 자를 자리를 매치 문자열에 묶지 않았다 — 탐욕성이 자리를 정하면 라우트의 public 세그먼트가 잘린다'
            );

            $this->assertStringContainsString(
                '::\2$',
                $source,
                '`'.$source.'` 에 꼬리 대조 백레퍼런스가 없다 — `::$1` 을 이어 붙이기만 하고 대조하지 않으면 아무것도 묶이지 않는다'
            );

            // `$1` 을 쓰려면 규칙 패턴이 **캡처하는 긍정 패턴**이어야 한다. mod_rewrite 는
            // 부정 패턴(`!…`)에 백레퍼런스를 주지 않으므로 `$1` 이 빈 문자열이 되고, 그러면
            // 위 조건이 영영 매치하지 않아 정본 주소 301 이 통째로 사라진다 — 제2 주소가
            // 오류 없이 다시 열리는 형태라 이 축이 없으면 그 회귀가 조용히 통과한다.
            $pattern = explode(' ', $line)[1] ?? '';

            $this->assertFalse(
                str_starts_with($pattern, '!'),
                '`'.$line.'` 은 조건이 `$1` 을 쓰는데 패턴이 부정형이다 — 백레퍼런스가 없어 정본 주소 301 이 한 번도 발화하지 않는다'
            );

            // **캡처하는** 그룹이어야 한다. 여는 괄호의 존재만 보면 `(?!public/)` 같은
            // 비캡처 그룹이 그 자리를 채워, `$1` 이 비는 바로 그 회귀를 통과시킨다 —
            // 실제로 이 규칙의 패턴이 선행부정으로 시작하므로 그 형태가 우연이 아니다.
            //
            // 판정은 리터럴 대조가 아니라 **실제 캡처 개수**로 한다. 「`(` 뒤에 `?` 가
            // 없다」만 보면 두 방향으로 틀린다:
            //   · 위조 통과 — `[(]`(문자 클래스 안의 괄호)와 `\(`(이스케이프된 괄호)는
            //     캡처 그룹이 하나도 없는데 그 대조를 충족한다
            //   · 정당한 형태 거부 — 명명 그룹 `(?<rest>…)` 은 PCRE 에서 번호도 함께
            //     받는 **캡처** 그룹이라 `$1` 이 정상 발화하는데 `?` 때문에 걸린다
            $this->assertGreaterThan(
                0,
                self::countCapturingGroups($pattern),
                '`'.$line.'` 의 패턴에 **캡처** 그룹이 없다 — 비캡처 그룹만 있으면 `$1` 이 비어 '
                .'조건이 영영 매치하지 않고 정본 주소 301 이 한 번도 발화하지 않는다'
            );

            $this->assertStringNotContainsString(
                'NE',
                $line,
                '`'.$line.'` 은 디코딩된 값에서 목적지를 만드는데 NE 가 붙었다 — 공백·한글이 인코딩되지 않은 채 나간다'
            );

            // 빈 세그먼트가 든 주소는 이 규칙이 다루지 않는다는 것을 **조건으로** 밝혀야
            // 한다. 접두사 그룹의 앵커만으로는 부족하다 — `%2f` 를 디코딩하는 서버 설정
            // (`AllowEncodedSlashes On`)에서는 디코딩된 URI 안에 `//` 가 생겨 앵커가
            // 의도와 다른 자리를 잡는다. 이 가드가 그 입력을 규칙 밖으로 밀어낸다.
            $hasEmptySegmentGuard = false;

            for ($back = $index - 1; $back >= 0 && str_starts_with($directives[$back], 'RewriteCond '); $back--) {
                if (str_contains($directives[$back], '%{REQUEST_URI} !//')) {
                    $hasEmptySegmentGuard = true;
                    break;
                }
            }

            $this->assertTrue(
                $hasEmptySegmentGuard,
                '`'.$line.'` 앞에 빈 세그먼트 가드(`RewriteCond %{REQUEST_URI} !//`)가 없다 — '
                .'인코딩된 슬래시를 디코딩하는 서버에서 앵커가 다른 자리를 잡는다'
            );
        }

        $this->assertSame(
            3,
            $redirectRules,
            '레이아웃 판정을 갖는 301 규칙이 3개가 아니다 — (B)(C)(D) 중 하나가 사라졌다'
        );
    }

    /**
     * `public/.htaccess` 의 **모든** 301 규칙이 빈 세그먼트 가드를 갖는다.
     *
     * 위 형제 검사(`test_..._redirect_rules_...`)는 성질 단언을 **레이아웃 게이트 규칙에만**
     * 걸었다 — 필터가 `RewriteRule !^public/` · `^(?!public/)` 로 시작하는 줄만 보므로,
     * 그 형태가 아닌 301 은 앵커·`NE`·`!//` 어느 축에도 걸리지 않았다. 그래서 같은 파일의
     * 스톡 슬래시 보정 (E)(`RewriteRule ^ %1 [L,R=301]`)이 모집단 밖이었고, 형제 (B) 가
     * 실측 근거까지 적어 둔 그 가드가 (E) 에만 없었다 (9차 G3 라운드 D-2·D-3).
     *
     * 증상은 형제와 같다 — `%{REQUEST_URI} (.+)/$` 는 탐욕·무앵커라 `//evil.example/p/`
     * 에서 `%1` 이 `//evil.example/p` 가 되고, `Location: //evil.example/p` 는 브라우저가
     * **프로토콜 상대 URL** 로 읽어 목적지 호스트를 요청자가 지정한다. 정상 이용 경로에
     * 빈 세그먼트는 없으므로 가드로 잃는 것이 없다.
     *
     * 이 검사는 「어느 규칙이 게이트인가」를 묻지 않는다 — **목적지를 `%{REQUEST_URI}`
     * 캡처에서 만드는 301 전부**가 모집단이다. 새 301 이 어떤 형태로 추가돼도 그 축에
     * 들어온다.
     *
     * @scenario deployment_layout=docroot_public, protection=htaccess_ok
     *
     * @effects canonical_redirects_never_emit_a_protocol_relative_destination
     */
    public function test_every_public_htaccess_redirect_guards_empty_segments(): void
    {
        // 모집단은 **배포되는 `.htaccess` 전부**다.
        //
        // 축 이름은 「목적지를 `%{REQUEST_URI}` 캡처에서 만드는 301 **전부**」인데
        // 종전 모집단은 `public/.htaccess` 한 파일이었다. 루트 파일에 같은 형태의
        // 301 이 생기면 그 축이 통째로 밖에 남는다 — 지금은 루트에 그런 301 이 0건이라
        // 위반이 없지만, 「없다」를 고정하는 것이 없었다 (10차 G3 라운드 D10-5).
        $files = ['public/.htaccess', '.htaccess'];
        $directives = [];

        foreach ($files as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, $relative.' 이 없다 — 모집단이 줄면 이 축이 조용히 비어진다');

            foreach (self::directiveLines($path) as $line) {
                $directives[] = $line;
            }
        }

        $directives = array_values($directives);

        $measured = 0;

        foreach ($directives as $index => $line) {
            if (! str_starts_with($line, 'RewriteRule ') || ! str_contains($line, 'R=301')) {
                continue;
            }

            // 목적지를 `%{REQUEST_URI}` 캡처(`%N`)에서 만드는 규칙만 대상이다. 요청 라인
            // 원문(`%{THE_REQUEST}`)에서 만드는 (C)(D) 는 그 조건 자신이 `[^/` 앵커로
            // 빈 세그먼트를 이미 배제하며, 그 축은 형제 검사가 소유한다.
            $fromRequestUri = false;

            for ($back = $index - 1; $back >= 0 && str_starts_with($directives[$back], 'RewriteCond '); $back--) {
                if (str_starts_with($directives[$back], 'RewriteCond %{THE_REQUEST}')) {
                    break;
                }
                if (str_contains($directives[$back], '%{REQUEST_URI}') && str_contains($directives[$back], '(')) {
                    $fromRequestUri = true;
                    break;
                }
            }

            if (! $fromRequestUri) {
                continue;
            }

            $measured++;
            $hasGuard = false;

            for ($back = $index - 1; $back >= 0 && str_starts_with($directives[$back], 'RewriteCond '); $back--) {
                if (str_contains($directives[$back], '%{REQUEST_URI} !//')) {
                    $hasGuard = true;
                    break;
                }
            }

            $this->assertTrue(
                $hasGuard,
                '`'.$line.'` 앞에 빈 세그먼트 가드(`RewriteCond %{REQUEST_URI} !//`)가 없다 — '
                .'`//호스트/경로/` 요청에서 목적지가 프로토콜 상대 URL 이 되어 요청자가 리다이렉트 호스트를 지정한다'
            );
        }

        // 모집단이 비면 위 루프가 한 번도 돌지 않아 이 검사가 조용히 초록이 된다.
        // 하한은 리터럴이 아니라 **현재 파일이 실제로 갖는 형태**에서 온다 — (B) 와 (E)
        // 둘이 이 축에 들어오므로 2 미만이면 그 자체가 회귀다.
        $this->assertGreaterThanOrEqual(
            2,
            $measured,
            'REQUEST_URI 캡처로 목적지를 만드는 301 이 2개 미만이다 — 이 검사가 아무것도 재지 않는다'
        );
    }

    /**
     * 두 `.htaccess` 가 상위 `AllowOverride` 등급을 요구하는 지시어를 쓰지 않는다.
     *
     * `Options`(Options 등급)·`Require`(AuthConfig 등급)는 저가형 공유 호스팅에서 흔한
     * `AllowOverride FileInfo Limit Indexes` 조합에서 「not allowed here」로 **그 디렉토리
     * 하위 전 요청을 500** 으로 만든다(실측). 레이아웃 ② 는 모든 요청을 `public/` 으로
     * 위임하므로 두 파일 중 어느 쪽이 걸려도 사이트 전체가 죽고, 인스톨러조차 뜨지 않아
     * 원인을 알려 줄 화면이 없다. `<IfModule>` 은 모듈 유무만 보고 권한 등급은 보지 못해
     * 가드가 되지 않는다(감싼 채로도 500 이 나는 것을 실측했다).
     *
     * @scenario deployment_layout=docroot_project_root, protection=htaccess_ok
     *
     * @effects root_protection_survives_restricted_allow_override
     */
    public function test_htaccess_avoids_directives_needing_elevated_allow_override(): void
    {
        // 목록을 `Options|Require` 로 좁히면 **같은 500 을 내는 형제들이 통과한다.**
        // `AuthType`·`AuthName`·`Auth*File`·`Satisfy` 는 전부 AuthConfig 등급이고
        // `php_value`·`php_flag` 는 Options 등급이다 — 제한 `AllowOverride` 에서
        // 「not allowed here」로 같은 사이트 전체 장애를 낸다.
        foreach (['.htaccess', 'public/.htaccess'] as $relative) {
            foreach (self::directiveLines(base_path($relative)) as $line) {
                $this->assertDoesNotMatchRegularExpression(
                    '~^(Options|Require|AuthType|AuthName|Auth\w*File|Satisfy|php_value|php_flag|php_admin_value|php_admin_flag)\s~i',
                    $line,
                    $relative.' 가 `'.$line.'` 를 쓴다 — 제한 AllowOverride 호스팅에서 이 디렉토리 하위 전 요청이 500 이 된다'
                );
            }
        }

        // 줄 연속(`\`)으로 지시어 이름을 쪼개면 위 판정을 지나가지만 Apache 는 이어 붙여
        // 해석한다. 배포되는 두 파일에는 그 형태를 두지 않는다.
        //
        // 판정식을 손으로 적지 않는다 — 이 자리는 **이스케이프가 두 번 어긋난 이력**이 있다.
        //   ① 처음에는 `\`+LF 리터럴이라 CRLF 파일에서 영영 매치하지 않았다.
        //   ② 그것을 고치며 넣은 `'~\\\R~'` 는 홑따옴표 해석 결과가 `~\\R~` — PCRE 개행
        //      클래스 `\R` 이 아니라 **리터럴 백슬래시 + 문자 R** 을 찾는다. 저장소의 두
        //      `.htaccess` 에는 그 글자쌍이 없어(실측 0건) 언제나 공허하게 통과했다.
        // 그래서 패턴을 **바이트로 조립**한다. 조립한 값이 실제로 그 형태를 잡는지도
        //   ③ 그 뒤 판본은 개행 클래스로 `\R` 을 썼는데, `u` 수식자가 없으면 PCRE 는
        //      **바이트 모드**로 판정해 0x85(NEL)까지 개행으로 본다 — 이 저장소가 스스로
        //      금지한 형태다(저장소 규약 「한글 텍스트를 정규식으로 자를 때」). 한글에서
        //      실제 오탐이 되지는 않지만(0x85 는 연속 바이트라 `\` 뒤에 올 수 없다 — 실측),
        //      규약을 만든 자리가 그 규약을 어기고 있으면 규약이 죽는다. 실제 줄 종결자만
        //      보도록 명시 대안으로 적고, 그 성질까지 아래에서 잰다.
        // 같은 자리에서 확인해, 다음 편집이 세 번째로 어긋나면 여기서 red 가 된다.
        $backslash = chr(92);
        $lineContinuation = '~'.$backslash.$backslash.'(?:'.$backslash.'r'.$backslash.'n|'.$backslash.'r|'.$backslash.'n)~';

        $this->assertMatchesRegularExpression(
            $lineContinuation,
            'Opt'.$backslash."\nions",
            '줄 연속 판정식이 역슬래시+개행을 잡지 못한다 — 이스케이프가 또 어긋났다'
        );
        $this->assertMatchesRegularExpression(
            $lineContinuation,
            'Opt'.$backslash."\r\nions",
            '줄 연속 판정식이 CRLF 형태를 잡지 못한다'
        );
        $this->assertDoesNotMatchRegularExpression(
            $lineContinuation,
            "Options -Indexes\n",
            '줄 연속 판정식이 정상 줄을 위반으로 본다'
        );
        $this->assertDoesNotMatchRegularExpression(
            $lineContinuation,
            'Opt'.$backslash.chr(0x85).'ions',
            '줄 연속 판정식이 0x85(NEL)를 개행으로 본다 — 바이트 모드 개행 클래스가 되돌아왔다'
        );

        foreach (['.htaccess', 'public/.htaccess'] as $relative) {
            $this->assertDoesNotMatchRegularExpression(
                $lineContinuation,
                (string) file_get_contents(base_path($relative)),
                $relative.' 에 줄 연속이 있다 — 지시어 이름이 쪼개지면 등급 판정을 지나간다'
            );
        }

        // 개행 **형식**은 고정하지 않는다. `.gitattributes` 가 `* text=auto eol=lf` 로
        // 정규화하므로 워킹트리의 CRLF 는 로컬 산출물이고, 커밋 뒤 모든 클론에서 LF 가
        // 된다 — 형식을 단언하면 그 순간부터 저장소 전체가 거짓 빨강이 된다. 위 판정식이
        // 개행 종류를 가리지 않으므로 형식을 알 필요도 없다.

        // 루트 `.htaccess` 의 규칙집을 켜는 한 줄. `public/.htaccess` 쪽은 정본 주소 축이
        // 따로 고정하지만, 이쪽은 어떤 검사도 요구한 적이 없었다 — 지우면 위임이 죽어
        // 프로젝트 루트(.env·vendor·artisan)가 그대로 서빙되는데 `<IfModule !mod_rewrite.c>`
        // 방어선은 **모듈이 있으므로** 평가되지 않아 아무것도 막지 않는다.
        $this->assertContains(
            'RewriteEngine On',
            self::directiveLines(base_path('.htaccess')),
            '루트 .htaccess 에 RewriteEngine On 이 없다 — 위임이 죽고 rewrite 부재 방어선도 평가되지 않는다'
        );

        // 프런트 컨트롤러 위임은 `public/.htaccess` 가 소유한다. 이 세 줄이 사라지면
        // 사이트의 **전 라우트가 404** 인데, 종전에는 어떤 검사도 그것을 보지 않았다.
        $publicLines = self::directiveLines(base_path('public/.htaccess'));
        $frontController = array_values(array_filter(
            $publicLines,
            static fn (string $line): bool => str_starts_with($line, 'RewriteRule ^ index.php')
        ));
        $this->assertNotEmpty(
            $frontController,
            'public/.htaccess 에 프런트 컨트롤러 위임 규칙이 없다 — 그 서버의 전 라우트가 404 가 된다'
        );

        $root = self::directiveLines(base_path('.htaccess'));
        $public = self::directiveLines(base_path('public/.htaccess'));

        // 숨김 파일 차단은 FileInfo 등급(mod_alias)으로 낸다. `.well-known/` 은 예외여야
        // 한다 — ACME(HTTP-01) 인증서 자동 갱신이 문서 루트의 그 경로를 읽는다.
        $dotGuard = null;
        foreach ($root as $line) {
            if (str_starts_with($line, 'RedirectMatch 403') && str_contains($line, '\.')) {
                $dotGuard = $line;
                break;
            }
        }

        $this->assertNotNull($dotGuard, '숨김 파일 차단이 FileInfo 등급 수단으로 있지 않다');
        $this->assertStringContainsString(
            'well-known',
            (string) $dotGuard,
            'ACME 갱신 경로가 예외로 빠져 있지 않다 — 인증서 자동 갱신이 조용히 실패한다'
        );

        // 디렉토리 목록 노출 차단은 Indexes 등급(`IndexIgnore`)으로 낸다.
        $this->assertContains('IndexIgnore *', $public, 'public/ 의 디렉토리 목록 차단이 없다');

        // 루트에도 같은 차단이 있어야 한다. 요청 파일명이 **디렉토리 자신**이면 per-dir 의
        // 위임 규칙이 적용되지 않아 mod_autoindex 가 응답 주체가 될 수 있고(mod_dir 의
        // 자동 슬래시는 기본값 On 으로 둔다 — 아래 단언 참조), mod_rewrite 가 없는 서버에서는
        // 위임 자체가 없어 프로젝트 루트가 디렉토리 요청의 응답 주체가 된다 — 그 자리에 목록
        // 차단이 없으면 `.env`·`composer.json` 등 **파일 이름**이 통째로 나열되고, 확장자
        // 차단을 우회할 이름을 그대로 알려 준다. 종전에는 `public/` 만 잰 채 루트가 이 축
        // 밖이었다 (13차 G3 라운드 W4-1). 이 주석은 한때 「루트가 `DirectorySlash Off` 를
        // 켠다」고 적었는데 같은 메서드가 바로 아래에서 그 지시어를 **금지**한다 — 서사가
        // 규칙과 어긋나 있던 자리다 (G3 회차 14 W4-3).
        $this->assertContains('IndexIgnore *', $root, '프로젝트 루트의 디렉토리 목록 차단이 없다');

        // `DirectorySlash Off` 는 쓰지 않는다. 요청 파일명이 **디렉토리 자신**이면 per-dir
        // 규칙이 적용되지 않아 위임이 닿지 않고 mod_autoindex 로 떨어지는데, 그 상태에서
        // 슬래시 없는 마운트 루트(`/g7sub`)는 전부 403 이었다(P0 Apache 7마운트 실측). 종전
        // 주석 「위임이 전부 처리하므로 mod_dir 는 할 일이 없다」는 거짓이었다 — 마운트 루트
        // 자신은 위임이 닿지 않는다. 대신 루트 실존 디렉토리의 위임에 표식(`G7_ROOTDIR`)을
        // 실어 `public/.htaccess` (D) 가 그 요청의 슬래시를 떼지 않게 한다(무한 301 차단).
        $this->assertNotContains(
            'DirectorySlash Off',
            $root,
            'DirectorySlash Off 는 슬래시 없는 마운트 루트를 403 으로 만든다(실측) — 무한 301 은 G7_ROOTDIR 표식으로 막는다'
        );

        $rootDirDelegation = null;
        foreach ($root as $index => $line) {
            if (preg_match('~^RewriteRule\s+\^\(\(\?!public/\)\.\*\)\$\s+public/\$1\s+\[[^]]*\bE=G7_ROOTDIR:1\b~', $line) === 1) {
                $rootDirDelegation = $index;
                break;
            }
        }

        $this->assertNotNull(
            $rootDirDelegation,
            '루트 실존 디렉토리 위임이 G7_ROOTDIR 표식을 싣지 않는다 — public/.htaccess (D) 가 그 요청의 슬래시를 떼어 무한 301 이 된다'
        );
        $this->assertSame(
            'RewriteCond %{REQUEST_FILENAME} -d',
            $root[$rootDirDelegation - 1] ?? null,
            'G7_ROOTDIR 위임은 `-d` 조건 바로 뒤에 있어야 한다 — 조건 없이 모든 요청에 표식을 실으면 (D) 가 통째로 꺼진다'
        );
        $this->assertContains(
            'RewriteCond %{ENV:REDIRECT_G7_ROOTDIR} !=1',
            $public,
            'public/.htaccess (D) 가 REDIRECT_G7_ROOTDIR 를 제외하지 않는다 — 표식을 싣기만 하고 읽는 곳이 없다'
        );

        // 접근 거부 폴백은 Limit 등급(mod_access_compat)으로 낸다.
        $this->assertContains('Order allow,deny', $root, 'mod_alias 부재 서버의 접근 거부 폴백이 없다');
    }

    /**
     * mod_rewrite 패턴에서 **번호를 받는 캡처 그룹**의 개수를 셉니다.
     *
     * 「`(` 가 있다」가 아니라 「`$1` 이 실제로 채워진다」를 재기 위한 것이다. 세지 않는 것:
     *   · `\(` — 이스케이프된 리터럴 괄호
     *   · `[...(...]` — 문자 클래스 안의 괄호
     *   · `(?:` `(?!` `(?=` `(?<!` `(?<=` `(?#` `(?i)` — 비캡처·선행/후행 판정·설정
     * 세는 것: `(` 일반 그룹과 `(?<name>` · `(?'name'` · `(?P<name>` 명명 그룹
     * (PCRE 에서 명명 그룹은 번호도 함께 받아 `$1` 이 정상 발화한다).
     *
     * @param  string  $pattern  `RewriteRule` 의 패턴 토큰
     * @return int 번호를 받는 그룹 개수
     */
    private static function countCapturingGroups(string $pattern): int
    {
        $count = 0;
        $inClass = false;
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                $i++; // 다음 문자는 이스케이프 대상 — 구조로 읽지 않는다

                continue;
            }

            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char !== '(') {
                continue;
            }

            $next = $pattern[$i + 1] ?? '';

            if ($next !== '?') {
                $count++;

                continue;
            }

            // `(?` 로 시작하는 것 중 **명명 그룹**만 번호를 받는다.
            $after = substr($pattern, $i + 2);

            if (preg_match("~^(?:P?<[A-Za-z_]|'[A-Za-z_])~", $after) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 배치 판정을 **파일시스템 조회로** 만드는 형태를 전부 거부합니다.
     *
     * 종전 판정은 변수 이름 **리터럴 3개**(`%{DOCUMENT_ROOT}`·`%{ENV:DOCUMENT_ROOT}`·
     * `%{SCRIPT_FILENAME}`)와 `public/index.php` 한 리터럴만 봤다. 그런데 이 절이
     * 선언하는 것은 「파일시스템으로 위치를 캐묻는 형태 **전부**」다. 실제로 그 선언과
     * 구현 사이에는 다음이 남아 있었고, 전부 폐기된 배치 판정을 그대로 재구성한다:
     *
     *  - `%{CONTEXT_DOCUMENT_ROOT}` — Apache 2.4 의 정규 변수. 별칭 마운트에서
     *    `%{DOCUMENT_ROOT}` 와 같은 함정을 갖는다
     *  - `%{ENV:SCRIPT_FILENAME}` — 같은 값을 환경변수 표기로 돌려 읽는다
     *  - 대소문자 변형(`%{document_root}`) — Apache 의 변수 이름 조회는 대소문자를
     *    구분하지 않는다
     *  - **진입 스크립트 이름을 대지 않고** `-d` 로 「이 아래 `public/` 이 있는가」를
     *    묻는 형태(`%{REQUEST_FILENAME}/public -d`) — 리터럴을 피해 같은 판정을 만든다
     *
     * 막지 **않는** 것: `%{REQUEST_FILENAME}` 자체와 그 `-d`/`!-d`/`-f`/`!-f` 조건.
     * (C)(D) 가 디렉토리 여부를 묻는 것은 배치 판정이 아니라 슬래시 보정의 근거이고,
     * 프런트 컨트롤러 폴백도 같은 조건을 쓴다. 가르는 기준은 **묻는 대상이 사이트의
     * 공개 폴더인가**다 — 경로 뒤에 `public` 세그먼트를 붙여 묻는 순간 배치 판정이 된다.
     *
     * @param  list<string>  $directives  지시자 줄 목록
     * @param  string  $label  실패 메시지에 실을 파일 이름
     */
    private function assertDirectivesDoNotProbeTheFilesystemForLayout(array $directives, string $label): void
    {
        // 변수 이름으로 문서 루트·진입 스크립트 위치를 캐묻는 형태. `%{ENV:…}` 접두와
        // 대소문자 변형을 함께 흡수한다 — 리터럴 대조로는 표기만 바꾼 같은 판정이 통과한다.
        $probePattern = '~%\{(?:ENV:)?(?:CONTEXT_)?(?:DOCUMENT_ROOT|SCRIPT_FILENAME|SCRIPT_URL)\}~i';

        // 파일시스템 조건(`-f`/`-d`)의 **대상 경로**에 사이트의 공개 폴더 이름이 들어간
        // 형태. `%{REQUEST_FILENAME}/public -d` 처럼 변수 표기를 피해도 같은 판정이다.
        $publicProbePattern = '~^RewriteCond\s+\S*public(?:/[^\s]*)?\s+!?-[fd]\b~i';

        foreach ($directives as $line) {
            $this->assertDoesNotMatchRegularExpression(
                $probePattern,
                $line,
                $label.' 가 `'.$line.'` 로 파일시스템에 위치를 캐묻는다 — 그 값은 별칭 '
                .'마운트에서 사이트 위치가 아니다. 배치 판정은 mod_rewrite 가 per-directory '
                .'규칙에 넘기는 매치 문자열(`!^public/`)이 소유한다'
            );

            $this->assertDoesNotMatchRegularExpression(
                $publicProbePattern,
                $line,
                $label.' 가 `'.$line.'` 로 공개 폴더의 존재를 물어 배치를 가른다 — 변수 표기를 '
                .'피했을 뿐 같은 축이다'
            );

            // 조회 대상 파일명으로 배치를 가르는 형태도 같은 축이다.
            $this->assertDoesNotMatchRegularExpression(
                '~public/index\.php.*!?-[fd]\b~i',
                $line,
                $label.' 가 `'.$line.'` 로 진입 스크립트의 존재를 물어 배치를 가른다 — 같은 축이다'
            );
        }
    }

    /**
     * `.htaccess` 의 **지시자 줄만** 돌려줍니다 (주석·빈 줄 제거, 공백 정규화).
     *
     * 주석에 규칙을 설명으로 적는 것은 정상이므로, 판정이 그것을 지시자로 오인하면
     * 규칙을 지워도 게이트가 초록이 된다.
     *
     * @param  string  $path  `.htaccess` 절대 경로
     * @return list<string> 지시자 줄 목록
     */
    private static function directiveLines(string $path): array
    {
        // 개행 분리에 `\R` 을 쓰지 않는다 — 그 이스케이프는 UTF-8 모드가 아니면
        // **바이트 0x85**(NEL)도 개행으로 본다. 한국어 주석의 흔한 글자들이 그 바이트를
        // 마지막에 갖고 있어(`종` = EC A2 85), 주석 한 줄이 중간에서 쪼개지고 뒷조각이
        // `#` 로 시작하지 않아 **유령 지시자 줄**로 목록에 섞였다. 그러면 아래 연속 블록
        // 판정이 주석 내용에 따라 흔들린다. 실제 줄 종결자만 본다.
        $lines = preg_split('~\r\n|\r|\n~', (string) file_get_contents($path)) ?: [];

        $normalized = array_map(
            static fn (string $line): string => (string) preg_replace('~\s+~', ' ', trim($line)),
            $lines
        );

        return array_values(array_filter(
            $normalized,
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
        ));
    }

    /**
     * 지시자 목록 안에 주어진 줄들이 **그 순서로 연속**해 나타나는지 단언합니다.
     *
     * 조건과 규칙이 떨어져 있으면 그 조건은 다른 규칙에 붙거나 아무 데도 붙지 않는다 —
     * 존재만 보는 검사로는 그 상태를 구분할 수 없다.
     *
     * @param  list<string>  $directives  지시자 줄 목록
     * @param  list<string>  $block  연속해 나타나야 하는 줄들
     * @param  string  $message  실패 메시지
     */
    private function assertDirectiveBlock(array $directives, array $block, string $message): void
    {
        $this->assertStringContainsString(
            implode("\n", $block),
            implode("\n", $directives),
            $message
        );
    }

    /**
     * 방어선이 의존하는 모듈과 그 부재 시의 잔여 위험이 파일에 **선언되어** 있다.
     *
     * 루트 `.htaccess` 의 세 방어 규칙은 각각 다른 모듈(`mod_alias` · `mod_access_compat`)
     * 에 의존하고, 그 모듈이 없으면 조용한 no-op 이 된다. 대체 수단이 없으므로(`Options`·
     * `Require` 는 제한 `AllowOverride` 에서 전 요청을 500 으로 만든다) 남는 것은 **선언**
     * 뿐인데, 선언되지 않은 잔여 위험은 다음 사람에게 「없는 것」으로 읽힌다. 실제로 그
     * 상태였다 — 완화책(인스톨러 노출 점검)은 `.htaccess` 무시 축에만 적혀 있었고 모듈
     * 부재 축에는 없었다 (7차 G3 라운드 A6·A7).
     *
     * 이 검사는 선언의 **존재**를 잠근다. 선언을 지우면 red 가 된다.
     *
     * @scenario deployment_layout=docroot_project_root, apache_modules=absent
     *
     * @effects root_htaccess_declares_its_residual_risk
     */
    public function test_root_htaccess_declares_the_residual_risk_of_missing_modules(): void
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        foreach (['mod_alias', 'mod_access_compat'] as $module) {
            $this->assertMatchesRegularExpression(
                '/#[^\n]*'.preg_quote($module, '/').'/',
                $htaccess,
                '루트 .htaccess 가 `'.$module.'` 의존을 주석으로 선언하지 않는다 — '
                .'그 모듈이 없는 서버에서 방어 규칙이 조용한 no-op 이 되는데 그 사실을 알 통로가 없다'
            );
        }

        $this->assertStringContainsString(
            '잔여 위험',
            $htaccess,
            '루트 .htaccess 에 잔여 위험 선언이 없다 — 대체 수단이 없는 축은 선언이 유일한 전달 통로다'
        );
    }

    /**
     * mod_rewrite 가 없는 서버의 방어선이 숨김 파일 하나에 그치지 않는다.
     *
     * `<FilesMatch "^\.">` 는 **파일 이름**만 본다 — `.env` 는 막지만 `storage/logs/*.log`,
     * `vendor/`, `composer.json`, `.git/config`(이름이 `config` 다)는 그대로 서빙된다.
     * 위임이 성립하지 않는 서버에서 그 목록이 계획서 §4 B-1② 의 하드닝 대상이다.
     * 이 규칙들은 `<IfModule !mod_rewrite.c>` 안에 둔다 — rewrite 가 있는 정상 서버에서
     * 경로 패턴이 `/api/modules/...` 같은 정상 라우트와 충돌하지 않게 하기 위해서다.
     *
     * @effects rewrite_absent_defense_line_is_derived_from_the_deployed_tree, rewrite_absent_defense_line_covers_every_deployed_root_file
     */
    public function test_root_htaccess_guards_sensitive_paths_without_mod_rewrite(): void
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        $start = strpos($htaccess, '<IfModule !mod_rewrite.c>');
        $this->assertNotFalse($start, 'mod_rewrite 부재 서버 전용 방어 블록이 없다');
        // 블록 안에 중첩 IfModule 이 있으므로 첫 닫힘이 아니라 관리 블록 끝까지 자른다.
        $end = strpos($htaccess, '# END G7', $start);
        $this->assertNotFalse($end, '방어 블록이 G7 관리 블록 밖에 있다 — 코어 업데이트가 갱신하지 못한다');
        $fallback = substr($htaccess, $start, $end - $start);

        // 기대 목록을 **손으로 적지 않는다.** 이 방어선은 두 라운드 연속으로 불완전했다 —
        // 1차가 만들 때 `lang-packs` 가 빠졌고(2차가 발견), 3차에는 `docs` 가 빠져 있었다.
        // 원인은 결함 하나가 아니라 **도출 근거의 부재**다: 배포 트리에 최상위 디렉토리가
        // 하나 늘 때 이 목록과 저 목록을 사람이 같이 고쳐야 한다는 사실을 아무것도 강제하지
        // 않았다. 그래서 배포 대상의 SSoT(`update.targets`)에서 도출한다 (3차 G3 라운드 M3).
        //
        // `storage`·`vendor`·`node_modules` 는 targets 에 없지만(각각 런타임 데이터·별도
        // 파이프라인 산출물·개발 의존성) 배포본에 실재하고 노출되면 가장 위험하므로 더한다.
        // `public` 은 제외한다 — 그 디렉토리가 곧 서빙 대상이다.
        //
        // 이 축은 **하한**이다. 배포 대상 목록에서 도출하는 한 「대상이 아닌 최상위
        // 디렉토리가 늘어나는 경우」는 영원히 보이지 않고, 그것을 손으로 더한 셋이 메우고
        // 있었다 — 즉 파일 축이 이미 갖고 있던 「실제 트리에서 도출」이 디렉토리 축에만
        // 없었다. 그 사각으로 테스트 산출물 둘이 열려 있었다. 트리 도출은 아래 개발
        // 체크아웃 전용 블록이 담당한다 (14차 G3 라운드).
        $deployedDirs = collect((array) config('app.update.targets'))
            ->map(static fn ($target) => explode('/', trim((string) $target, '/'))[0])
            ->filter(static fn (string $top) => $top !== '' && is_dir(base_path($top)))
            ->merge(['storage', 'vendor', 'node_modules'])
            ->reject(static fn (string $top) => $top === 'public')
            ->unique()
            ->values();

        // 하한은 **실측값**이다. 종전에는 `10` 이 적혀 있었는데 실제 도출은 18 이라,
        // 배포 대상 목록이 절반으로 잘려도 이 검사는 초록이었다 — 「모집단이 비면 아무것도
        // 재지 않는다」를 막으려고 둔 숫자가 정작 그 축소를 못 보고 있었다.
        //
        // 이 축만은 도출로 바꿀 수 없다. 목록의 원천이 `update.targets` 하나뿐이라 그
        // 원천에서 하한을 다시 뽑으면 자기 자신을 재는 순환이 되고, 형제 축이 쓰는 「실제
        // 트리」는 여기서 근거가 되지 못한다 — 이 단언은 `markTestSkipped` 앞이라 배포된
        // 설치본에서도 돌고, 그 루트에는 운영자가 둔 디렉토리가 있어 트리 도출이 목록보다
        // **커질** 수 있다(그러면 코어의 결함이 아닌데 red 가 된다). 그래서 리터럴로 두되
        // 실측값에 맞춘다. 목록이 정당하게 늘어나는 것은 허용한다(`OrEqual`).
        $this->assertGreaterThanOrEqual(
            18,
            $deployedDirs->count(),
            '배포 대상에서 도출한 최상위 디렉토리가 18개 미만이다 — 목록이 줄었거나 도출이 깨졌다'
        );

        foreach ($deployedDirs as $dir) {
            $this->assertMatchesRegularExpression(
                '/[(|]'.preg_quote($dir, '/').'[)|]/',
                $fallback,
                $dir.'/ 가 mod_rewrite 부재 방어선에 없다 — 그 서버에서 그대로 열린다. '
                .'방어선은 `/이름/` 을 통째로 맞추므로 `lang` 항목이 `/lang-packs/` 를 대신하지 못한다'
            );
        }

        // 파일 축도 **도출**한다. 종전에는 확장자 네 개(`json`·`lock`·`md`·`log`)를 손으로
        // 적고 `assertStringContainsString` 으로 봤는데, 그 형태에는 두 겹의 사각이 있었다:
        //  ① 부분 문자열 검사라 규칙이 사라지고 설명 주석만 남아도 통과한다.
        //  ② 디렉토리 축만 targets 에서 도출하고 **파일 축은 도출하지 않아**, 최상위 파일이
        //     하나 늘어도 아무것도 울지 않았다. 실제로 `vendor-bundle.zip`(vendor 전량 15MB)·
        //     빌드 설정 `*.js`/`*.ts`·확장자 없는 `artisan` 이 그 사이로 그대로 서빙됐다.
        //
        // 여기서는 배포 대상 목록(`update.targets`)의 **파일 항목**을 열거해 각각이 막히는지
        // 본다 — 디렉토리 축과 같은 SSoT 이며 배포본 안에서도 성립한다. 진입점(최상위 `.php`)과
        // 점 파일은 대상이 아니다.
        //
        // 「실제 최상위 트리」에서 도출하는 형제 축(배포 대상이 아닌데 디스크에 실재하는
        // 파일·디렉토리 — `vendor-bundle.zip`·`playwright-report` 가 그렇게 열려 있었다)은
        // 공개 저장소 필터의 제외 목록을 읽어야 성립한다. 그 필터는 배포본에 실리지 않는
        // 내부 도구라, 공개 테스트가 그것을 읽으면 배포본에서는 원리상 성립할 수 없는 검사가
        // 되고 존재 가드는 그 상태를 통과와 구분하지 못한다. 그래서 그 축은 필터 옆의 내부
        // 회귀 테스트가 같은 규칙으로 잰다 — 이 테스트에는 두지 않는다.
        $rootFiles = collect((array) config('app.update.targets'))
            ->map(static fn ($target) => trim((string) $target, '/'))
            ->reject(static fn (string $target) => $target === '' || str_contains($target, '/'))
            ->filter(static fn (string $target) => is_file(base_path($target)))
            // 최상위 `.php` 는 이 방어선의 대상이 **아니다** — 원리상 담을 수 없다.
            //
            // 이 방어선이 막는 것은 「PHP 로 실행되지 않아 원문이 그대로 나가는 것」이다
            // (`artisan` 이 그 예다). `.php` 는 실행되므로 그 축에 해당하지 않고, 확장자
            // 목록에 `php` 를 넣으면 **`index.php` 가 함께 막혀** 그 서버에서 사이트가
            // 통째로 열리지 않는다. 종전에는 최상위 `.php` 가 `index.php` 하나뿐이라
            // 그 이름만 예외로 두어도 성립했으나, 그것은 이름의 문제가 아니라 확장자의
            // 문제였다. 대신 아래에서 「최상위 `.php` 는 자기 진입 가드를 갖는다」를
            // 적극 조건으로 요구한다 — 예외를 넓히면서 의무를 없애지 않는다.
            ->reject(static fn (string $target) => strtolower((string) pathinfo($target, PATHINFO_EXTENSION)) === 'php')
            // 이름이 점으로 시작하는 파일은 블록 밖 `RedirectMatch 403`(mod_alias) 이 이미 막는다.
            // 종전에는 `<FilesMatch "^\.">` + `Require` 였는데, `Require` 는 AuthConfig 등급을
            // 요구해 제한 AllowOverride 호스팅에서 전 요청 500 을 냈다(8차 라운드 실측).
            ->reject(static fn (string $target) => str_starts_with($target, '.'))
            ->unique()
            ->values();

        // 모집단이 비면 아래 순회가 아무것도 재지 않는다 — 배포 대상 목록에는 최상위 파일이
        // 여럿(`artisan`·`composer.json`·빌드 설정 등) 있으므로 0 은 도출 실패다.
        $this->assertGreaterThan(0, $rootFiles->count(), '배포 대상 목록에서 최상위 파일을 하나도 도출하지 못했다');

        // 확장자 규칙과 확장자 없는 파일 규칙을 각각 실제 패턴에서 읽어 낸다 (주석으로 만족 불가).
        preg_match('/<FilesMatch "\(\?i\)\\\\\.\(([^)]+)\)\$">/', $fallback, $extMatch);
        $this->assertNotEmpty($extMatch, '확장자 기반 차단 규칙을 찾지 못했다 — 규칙 본문이 사라졌는가');
        $blockedExtensions = collect(explode('|', $extMatch[1]))->map(static fn (string $e) => strtolower(trim($e)));

        preg_match('/<FilesMatch "\^\(([^)]+)\)\$">/', $fallback, $nameMatch);
        $blockedNames = $nameMatch === []
            ? collect()
            : collect(explode('|', $nameMatch[1]))->map(static fn (string $n) => trim($n));

        foreach ($rootFiles as $name) {
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            if ($ext === '') {
                $this->assertTrue(
                    $blockedNames->contains($name),
                    $name.' 는 확장자가 없어 확장자 규칙으로 걸리지 않는다 — 이름 기반 차단 규칙에 넣어야 한다'
                );

                continue;
            }

            // `ya?ml` 처럼 정규식으로 두 형태를 겸하는 항목이 있으므로 패턴으로 판정한다.
            $covered = $blockedExtensions->contains(
                static fn (string $pattern) => preg_match('/^'.$pattern.'$/i', $ext) === 1
            );

            $this->assertTrue(
                $covered,
                $name.' 가 mod_rewrite 부재 방어선에 걸리지 않는다 (확장자 `'.$ext.'`) — '
                .'그 서버에서 원문이 그대로 서빙된다'
            );
        }

        // 최상위 `.php` 의 짝 의무 — 확장자 방어선이 담을 수 없는 만큼 자기 가드를 요구한다.
        //
        // `index.php` 는 위임만 하는 shim 이고 설치 게이트는 위임 대상(`public/index.php`)이
        // 소유한다. 그 외의 최상위 `.php`(현재는 압축 풀기 부트스트랩)는 **설치가 끝난
        // 사이트에서 스스로 닫혀야** 한다 — 그러지 않으면 운영 사이트에 인증 없는 표면이
        // 남는다. 두 완료 신호를 모두 보는지까지 본다: 락 파일만 보면 `.env` 표식만 있는
        // 사이트가, 표식만 보면 락 파일만 있는 사이트가 각각 열린다.
        //
        // 도출 근거가 「이 디스크의 최상위」라 이 축은 개발 체크아웃에서만 뜻이 있다 — 배포된
        // 설치본의 루트에는 운영자가 둔 `.php` 가 있을 수 있고, 그때 red 가 되는 것은 코어의
        // 결함이 아니다. 배포본에는 `.git` 이 없으므로 그것으로 가린다(형제 축과 같은 기준).
        if (! is_dir(base_path('.git'))) {
            $this->assertTrue(true, '저장소 밖(배포본)에서는 최상위 .php 진입 가드 축을 재지 않는다');

            return;
        }

        $rootPhpFiles = collect((array) scandir(base_path()))
            ->filter(static fn (string $name) => $name !== '.' && $name !== '..' && is_file(base_path($name)))
            ->filter(static fn (string $name) => strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'php')
            ->reject(static fn (string $name) => $name === 'index.php')
            ->values();

        foreach ($rootPhpFiles as $name) {
            $source = (string) file_get_contents(base_path($name));

            foreach (['g7_installed', 'INSTALLER_COMPLETED'] as $signal) {
                $this->assertStringContainsString(
                    $signal,
                    $source,
                    $name.' 가 설치 완료 신호(`'.$signal.'`)를 보지 않는다 — '
                    .'운영 사이트에 인증 없는 최상위 진입점이 남는다'
                );
            }
        }
    }

    /**
     * @effects root_entry_files_are_update_targets
     */
    public function test_root_entry_files_are_core_update_targets(): void
    {
        $targets = (array) config('app.update.targets');
        $protected = (array) config('app.update.protected_paths');

        // 목록을 손으로 적지 않고 **보호 파일 SSoT 에서 도출**한다.
        //
        // 종전에는 `index.php`·`.htaccess` 두 이름만 적혀 있어 `web.config` 를 보지 않았다.
        // 그래서 롤백 소실 판정은 상수를 순회하는데(`CoreBackupHelper::ROOT_PROTECTION_FILES`)
        // 「배포 대상인가 / 보호 경로가 아닌가」 축은 그 상수를 소비하지 않았고, 네 번째
        // 보호 파일을 상수에 더하면 롤백 경고만 따라오고 **업데이트가 그 파일을 배포하지
        // 않아도 아무것도 울지 않는** 반쪽 상태였다 (15차 G3 라운드).
        $this->assertNotEmpty(CoreBackupHelper::ROOT_PROTECTION_FILES);

        foreach (CoreBackupHelper::ROOT_PROTECTION_FILES as $rootFile) {
            $this->assertContains(
                $rootFile,
                $targets,
                "루트 보호 파일 {$rootFile} 은 코어 업데이트가 함께 갱신해야 한다 (D25)"
            );

            // protected_paths 에 들어가면 업데이트에서 통째로 제외되어 D25 의 「함께 갱신」이 깨진다.
            $this->assertNotContains($rootFile, $protected);
        }
    }

    /**
     * `public_path()` 는 앱 루트를 따라간다 — 부팅 시점에 절대경로로 못박지 않는다 (B-2).
     *
     * `usePublicPath()` 로 값을 넣으면 그 값은 `setBasePath()` 로 바뀌지 않는다(Laravel 의
     * `publicPath()` 는 저장된 값을 우선한다). 그러면 `setBasePath()` 로 격리한 테스트에서
     * `storage_path()` 만 임시 트리를 가리키고 `public_path()` 는 **실 저장소**를 가리켜,
     * `public/storage` 를 복구하는 코드가 개발자의 실제 링크를 대상으로 돈다 —
     * 정상 symlink 면 no-op 로 살지만 junction·일반 디렉토리면 `storage.broken.*` 로
     * 옮겨진 뒤 임시 디렉토리를 가리키게 되고, 그 임시 디렉토리는 tearDown 이 지운다.
     *
     * 그래서 기본 배치에서는 오버라이드를 걸지 않는다. 문서 루트 분리 배치는 진입 파일이
     * `G7_PUBLIC_PATH` 를 정의했을 때만 고정한다 (bootstrap/app.php).
     */
    /**
     * `G7_PUBLIC_PATH` seam 은 **진입 파일이 없는 문맥**(콘솔·테스트)에서도 도달 가능해야 한다.
     *
     * 그 자리의 주석은 「CLI·웹·테스트가 같은 값을 본다」고 선언하는데, 값을 넘길 통로가
     * 상수뿐이면 그 선언은 웹에서만 참이다 — 문서 루트 분리 배치에서 웹은 운영자의 public
     * 디렉토리를 보고 콘솔은 앱 루트의 `public/` 을 봐, `storage:link` 복구처럼 두 문맥이
     * 함께 쓰는 코드가 서로 다른 트리를 대상으로 돈다(오류 없이 링크만 엉뚱한 곳에 생긴다).
     * 실제 오버라이드 동작은 아래 케이스가 재므로, 여기서는 **통로의 존재**를 고정한다.
     */
    public function test_public_path_seam_is_reachable_without_an_entry_file(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString(
            "defined('G7_PUBLIC_PATH')",
            $bootstrap,
            '진입 파일이 상수로 값을 넘기는 통로는 그대로 유지된다'
        );
        $this->assertStringContainsString(
            "\$_SERVER['G7_PUBLIC_PATH']",
            $bootstrap,
            '웹서버 환경변수(SetEnv·fastcgi_param)는 $_SERVER 로만 실리는 SAPI 가 있다'
        );
        $this->assertStringContainsString(
            "getenv('G7_PUBLIC_PATH')",
            $bootstrap,
            '진입 파일이 없는 콘솔·테스트 문맥이 값을 넘길 통로가 없으면 그 자리의 선언이 웹에서만 참이 된다'
        );

        // 세 통로가 **있기만** 하면 초록인 것으로는 부족하다 — 값을 읽고 버려도 통과한다.
        // 읽은 값이 실제로 `usePublicPath()` 에 도달하는지, 그리고 그 호출이 세 통로
        // **뒤**에 오는지까지 본다.
        $this->assertStringContainsString(
            '$app->usePublicPath(',
            $bootstrap,
            '세 통로가 읽은 값이 앱에 도달하지 않으면 그 seam 은 아무것도 하지 않는다'
        );

        $lastRead = max(
            strpos($bootstrap, "defined('G7_PUBLIC_PATH')"),
            strpos($bootstrap, "\$_SERVER['G7_PUBLIC_PATH']"),
            strpos($bootstrap, "getenv('G7_PUBLIC_PATH')")
        );

        $this->assertGreaterThan(
            $lastRead,
            strpos($bootstrap, '$app->usePublicPath('),
            '적용이 읽기보다 앞에 있다 — 그러면 뒤 통로의 값이 반영되지 않는다'
        );

        // 값이 비었을 때 걸지 않는 성질도 계약이다 — 기본값으로라도 채우면 앱 루트를
        // 갈아 끼우는 지점에서 `public_path()` 만 실 저장소를 가리킨다.
        $this->assertMatchesRegularExpression(
            '/is_string\(\$g7PublicPath\)[\s\S]{0,80}?usePublicPath\(/',
            $bootstrap,
            '빈 값에도 usePublicPath 를 걸면 격리 트리에서 public_path 만 실 저장소를 가리킨다'
        );
    }

    /**
     * @effects public_path_follows_app_root_and_is_not_pinned_at_boot
     */
    public function test_public_path_follows_base_path_and_is_not_pinned_at_boot(): void
    {
        $original = base_path();
        $fake = storage_path('app/testing/public_path_contract_'.uniqid('', true));

        try {
            app()->setBasePath($fake);

            $this->assertSame(
                $fake.DIRECTORY_SEPARATOR.'public',
                public_path(),
                'public_path() 가 앱 루트를 따라가지 않는다 — 부팅 시점 절대경로 고정은 테스트 격리를 깨고 실 저장소 public/ 을 대상으로 만든다'
            );
        } finally {
            app()->setBasePath($original);
        }
    }

    /**
     * 위임 흔적 정규화가 **위임된 요청에만** 작용해야 합니다 (B-2 · 레이아웃 ③ 회귀 방지).
     *
     * `public/index.php` 는 루트 `.htaccess` 위임 때문에 `SCRIPT_NAME` 이 `/public/index.php`
     * 인데 브라우저는 `/` 에 있는 상태를 정규화한다. 그 판정이 위임 없는 배치까지 잡으면
     * 반대 방향으로 깨진다 — 레이아웃 ③(서브디렉토리 + URL 에 `/public` 노출)의 생성 URL 이
     * 전부 `/public` 접두를 잃는다.
     *
     * 판정 로직은 **사본을 만들지 않고** 프로덕션 소스 구간(`region:delegation-normalization`)을
     * 그대로 떼어 평가한다. 사본을 두면 소스가 바뀌어도 테스트가 옛 규칙을 계속 통과시킨다.
     *
     * @scenario mount_shape=docroot_relative, base_path=subpath
     *
     * @effects mount_prefix_is_recovered_without_guessing_when_script_name_lost_it
     */
    #[DataProvider('delegationNormalizationCases')]
    public function test_delegation_normalization_applies_only_to_delegated_requests(
        string $scriptName,
        string $requestUri,
        string $expectedScriptName,
        string $why,
        array $extraServer = []
    ): void {
        $source = (string) file_get_contents(base_path('public/index.php'));

        $start = strpos($source, '// region:delegation-normalization');
        $end = strpos($source, '// endregion:delegation-normalization');
        $this->assertNotFalse($start, '프로덕션 소스에 정규화 구간 표식이 없다 — 이 테스트는 그 구간을 그대로 평가한다');
        $this->assertNotFalse($end);

        $region = substr($source, $start, $end - $start);
        $this->assertStringContainsString('$g7ScriptDir', $region, '떼어낸 구간이 비면 이 테스트는 아무것도 재지 않는다');

        $server = array_merge(
            ['SCRIPT_NAME' => $scriptName, 'PHP_SELF' => $scriptName, 'REQUEST_URI' => $requestUri],
            $extraServer
        );

        $evaluate = static function (array $serverVars, string $code): array {
            $_SERVER = array_merge($_SERVER, $serverVars);
            eval($code);

            return $_SERVER;
        };

        $originalServer = $_SERVER;

        try {
            $result = $evaluate($server, $region);
            $this->assertSame($expectedScriptName, $result['SCRIPT_NAME'], $why);
        } finally {
            $_SERVER = $originalServer;
        }
    }

    /**
     * `SCRIPT_NAME` 키 자체가 없는 요청에서도 정규화 구간이 터지지 않는다 (G3 회차 15 W2-7).
     *
     * CLI 성 SAPI·비표준 FPM 설정은 그 키를 비울 수 있다. 구간은 `?? '/index.php'` 폴백으로
     * 읽고, 쓰기는 `isset` 가드로 건너뛴다 — 키가 없던 요청에 새 키를 만들어 내지 않는다.
     */
    public function test_delegation_normalization_survives_a_missing_script_name(): void
    {
        $source = (string) file_get_contents(base_path('public/index.php'));
        $start = strpos($source, '// region:delegation-normalization');
        $end = strpos($source, '// endregion:delegation-normalization');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $region = substr($source, $start, $end - $start);

        $originalServer = $_SERVER;

        try {
            unset($_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF']);
            $_SERVER['REQUEST_URI'] = '/g7sub/admin';
            $_SERVER['REDIRECT_G7_ROUTE'] = 'admin';

            eval($region);

            $this->assertArrayNotHasKey('SCRIPT_NAME', $_SERVER, '없던 키를 만들어 내면 뒤 코드가 그 값을 근거로 삼는다');
            $this->assertArrayNotHasKey('PHP_SELF', $_SERVER);
        } finally {
            $_SERVER = $originalServer;
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4?: array<string, mixed>}>
     */
    public static function delegationNormalizationCases(): array
    {
        return [
            // ── `Alias` 마운트 ──────────────────────────────────────────────
            // 이 배치는 `SCRIPT_NAME` 에 마운트 접두사를 싣지 않는다(실측 — 물리적 하위
            // 디렉토리 마운트는 싣는다). 그래서 위 다섯 케이스와 달리 정규화 결과가 빈
            // 문자열이 되고, 미설치 안내가 `/install` 즉 사이트 밖으로 나간다.
            // 복원 근거는 위임 규칙이 넘긴 사이트 안 경로 하나뿐이다 — 브라우저 주소만으로는
            // `/g7sub/admin` 의 어디까지가 마운트인지 구분할 수 없다.
            '별칭 마운트 · 위임된 일반 요청' => [
                '/public/index.php', '/g7sub/admin', '/g7sub/index.php',
                '위임 규칙이 넘긴 사이트 안 경로를 빼면 마운트 접두사가 나온다',
                ['REDIRECT_G7_ROUTE' => 'admin'],
            ],
            '별칭 마운트 · 마운트 루트 요청' => [
                '/public/index.php', '/g7sub/', '/g7sub/index.php',
                '사이트 안 경로가 비면 요청 경로 전체가 마운트다 (빈 값도 그대로 전달되는 것을 실측했다)',
                ['REDIRECT_G7_ROUTE' => ''],
            ],
            '별칭 마운트 · 중첩 내부 리다이렉트' => [
                '/public/index.php', '/g7sub/admin/users', '/g7sub/index.php',
                '내부 리다이렉트를 지날 때마다 REDIRECT_ 가 덧붙으므로 단계를 올려 가며 찾아야 한다',
                ['REDIRECT_REDIRECT_G7_ROUTE' => 'admin/users'],
            ],
            '별칭 마운트 · 빈 값 + 깊은 경로 → 추정하지 않는다' => [
                '/public/index.php', '/g7sub/admin/users', '/index.php',
                '사이트 안 경로가 비었는데 요청이 마운트 루트 형태가 아니면 그 값은 근거가 아니다 — 전체를 마운트로 단정하면 사이트 전 주소가 한 단계 깊어진다',
                ['REDIRECT_G7_ROUTE' => ''],
            ],
            // ── 표기 차이 ────────────────────────────────────────────────────
            // 브라우저 주소는 퍼센트 인코딩된 원문이고, 위임 규칙이 넘긴 값은 웹서버가
            // 디코딩한 경로다. 길이로 꼬리를 대조하면 이 조합은 **언제나 어긋나** 접두사가
            // 사라지고, 그 화면의 이동·자산 주소가 통째로 사이트 밖을 가리킨다.
            '별칭 마운트 · 인코딩된 경로 (공백)' => [
                '/public/index.php', '/g7sub/my%20page', '/g7sub/index.php',
                '인코딩 표기 차이로 꼬리 대조가 어긋나면 안 된다 — 세그먼트 수로 뺀다',
                ['REDIRECT_G7_ROUTE' => 'my page'],
            ],
            '별칭 마운트 · 인코딩된 경로 (한글 · 다단)' => [
                '/public/index.php', '/g7sub/%ED%95%9C%EA%B8%80/%EA%B8%80', '/g7sub/index.php',
                '한글 경로도 같다 — 접두사는 브라우저 주소에서 잘라 오므로 원문 표기를 유지한다',
                ['REDIRECT_G7_ROUTE' => "\xED\x95\x9C\xEA\xB8\x80/\xEA\xB8\x80"],
            ],
            '별칭 마운트 · 세그먼트 수가 모자라면 폴백' => [
                '/public/index.php', '/admin', '/index.php',
                '넘겨받은 값이 요청 경로보다 깊으면 뺄 수 없다 — 추정하지 않고 폴백한다',
                ['REDIRECT_G7_ROUTE' => 'a/b/c/d'],
            ],
            // 깊이는 맞는데 **값이 다른** 라우트. 위 '꼬리 불일치' 케이스는 계산 결과와
            // 폴백 값이 우연히 같아(둘 다 빈 접두사) 근거 검사를 지워도 초록이었다 —
            // 여기서는 둘이 갈라지므로 검사가 실제로 무는지 드러난다.
            '별칭 마운트 · 깊이는 맞고 값이 다르면 추정하지 않는다' => [
                '/public/index.php', '/g7sub/admin', '/index.php',
                '넘겨받은 값이 요청 꼬리와 다르면 세그먼트 수가 맞아도 근거가 아니다 — 후보를 요청 경로에서 잘라 만들기 때문에 「머리인가」만 보는 검사는 실패할 수 없다',
                ['REDIRECT_G7_ROUTE' => 'wrong'],
            ],
            '꼬리 불일치 → 추정하지 않는다' => [
                '/public/index.php', '/g7sub/admin', '/index.php',
                '넘겨받은 값이 요청 경로의 꼬리와 맞지 않으면 폴백한다 — 어긋난 값으로 접두사를 지어내지 않는다',
                ['REDIRECT_G7_ROUTE' => 'something/else'],
            ],
            '문서 루트 마운트 · 값이 있어도 결과 불변' => [
                '/public/index.php', '/admin', '/index.php',
                '루트 마운트에서는 뺄셈 결과가 빈 문자열이라 폴백 값과 같다 — 이 분기는 그 배치를 바꾸지 않는다',
                ['REDIRECT_G7_ROUTE' => 'admin'],
            ],
            '하위 폴더 마운트 · 값이 있어도 결과 불변' => [
                '/g7/public/index.php', '/g7/admin', '/g7/index.php',
                '물리적 하위 폴더는 SCRIPT_NAME 에 접두사가 실려 폴백만으로도 옳다 — 뺄셈 결과도 같아야 한다',
                ['REDIRECT_G7_ROUTE' => 'admin'],
            ],

            // ── 경계 (G3 회차 15 W2-7) ──────────────────────────────────────
            // 소비처는 `parse_url(PHP_URL_PATH)` 로 경로만 본다 — 쿼리스트링이 꼬리 대조에
            // 섞이면 `?x=1` 이 붙은 모든 요청에서 접두사가 사라진다.
            '별칭 마운트 · 쿼리스트링이 붙은 요청' => [
                '/public/index.php', '/g7sub/admin?tab=users&page=2', '/g7sub/index.php',
                '쿼리스트링은 경로가 아니다 — 꼬리 대조 전에 떼어야 한다',
                ['REDIRECT_G7_ROUTE' => 'admin'],
            ],
            // 위임 규칙이 넘긴 값에 역슬래시가 섞여도(Windows Apache · 운영자 규칙이 만든 값)
            // 슬래시로 정규화해 본다.
            '별칭 마운트 · G7_ROUTE 역슬래시' => [
                '/public/index.php', '/g7sub/admin/users', '/g7sub/index.php',
                '역슬래시는 슬래시로 정규화한 뒤 세그먼트로 가른다',
                ['REDIRECT_G7_ROUTE' => 'admin\\users'],
            ],
            // 깊이 탐색 상한은 4겹이다 — 그 밖의 값은 근거로 쓰지 않고 폴백한다.
            '별칭 마운트 · REDIRECT_ 4겹 (상한 안)' => [
                '/public/index.php', '/g7sub/admin', '/g7sub/index.php',
                '4겹까지는 찾는다',
                ['REDIRECT_REDIRECT_REDIRECT_REDIRECT_G7_ROUTE' => 'admin'],
            ],
            '별칭 마운트 · REDIRECT_ 5겹 (상한 밖) → 폴백' => [
                '/public/index.php', '/g7sub/admin', '/index.php',
                '상한 밖의 값은 읽지 않는다 — 읽지 않으면 폴백이고, 폴백은 추정이 아니다',
                ['REDIRECT_REDIRECT_REDIRECT_REDIRECT_REDIRECT_G7_ROUTE' => 'admin'],
            ],
            // 문자열이 아닌 값(운영자 규칙집·FPM 설정이 만든 배열)은 근거가 아니다.
            '별칭 마운트 · G7_ROUTE 비문자열 → 폴백' => [
                '/public/index.php', '/g7sub/admin', '/index.php',
                '문자열이 아닌 값은 건너뛴다 — 형변환 경고나 fatal 없이 폴백해야 한다',
                ['REDIRECT_G7_ROUTE' => ['admin']],
            ],

            '위임된 일반 요청' => ['/public/index.php', '/admin', '/index.php', '위임된 요청은 /public 세그먼트를 벗겨야 한다'],
            '위임된 index.php 요청' => ['/public/index.php', '/index.php', '/index.php', '스크립트 이름이 URI 에 든 요청이 이 정규화의 실제 대상이다'],
            '레이아웃 ③ 일반 요청' => ['/public/index.php', '/public/admin', '/public/index.php', '위임이 없는 배치는 접두를 그대로 둔다'],
            '레이아웃 ③ 디렉토리 요청(슬래시 없음)' => ['/public/index.php', '/public', '/public/index.php', '트레일링 슬래시 없는 디렉토리 요청도 그 디렉토리 안이다 — 위임으로 오판하면 base path 가 사라진다'],
            '서브디렉토리 레이아웃 ③ 디렉토리 요청' => ['/g7/public/index.php', '/g7/public', '/g7/public/index.php', '서브디렉토리 마운트에서도 같다'],
            '표준 배치' => ['/index.php', '/admin', '/index.php', '표준 배치는 이 분기에 들어가지도 않는다'],
        ];
    }

    /**
     * 마커 파일 집합(`.htaccess`·`web.config`)이 **두 분기 모두**에서 갱신기를 지난다.
     *
     * 종전에는 `.htaccess` 리터럴 한 줄이 그 분기였다 — `web.config` 는 그 옆에서 통째 복사로
     * 떨어져, 운영자가 IIS 규칙을 덧붙이면 다음 업데이트가 말없이 지웠다(오류 없음). 분기 조건을
     * 리터럴이 아니라 보호 파일 SSoT 에서 도출한 집합으로 바꾸고, 그 집합의 각 원소가 정상 targets
     * 분기와 자동 발견 폴백 분기 양쪽에서 갱신기를 지나는지를 여기서 잠근다 (G3 회차 16 W2 C-1).
     *
     * @scenario server_kind=iis_webconfig, update_targets_state=stale_without_root_files, htaccess_operator_state=modified_outside_block
     *
     * @effects root_marker_files_are_updated_by_the_block_updater_in_both_apply_branches
     */
    public function test_apply_update_routes_root_marker_files_through_the_block_updater(): void
    {
        // 집합은 SSoT 에서 **도출**한다 — 손으로 적으면 네 번째 마커 파일이 상수에 더해져도 이
        // 검사가 그것을 보지 않는다. 집합의 원소는 전부 보호 파일 목록 안이어야 하고, 갱신기는
        // 같은 추상화를 상속해야 한다(판정 로직 사본 금지).
        $updaters = CoreBackupHelper::ROOT_MARKER_FILE_UPDATERS;
        $this->assertGreaterThanOrEqual(2, count($updaters), '마커 파일 집합이 둘 미만이다 — web.config 가 다시 통째 교체로 떨어졌다');

        foreach ($updaters as $file => $updater) {
            $this->assertContains($file, CoreBackupHelper::ROOT_PROTECTION_FILES, $file.' 은 보호 파일 목록 밖이다 — 마커 파일 집합은 그 목록에서 도출한다');
            $this->assertTrue(
                is_subclass_of($updater, AbstractRootMarkerFileUpdater::class),
                $updater.' 가 공통 추상화를 상속하지 않는다 — 판정 로직 사본은 한쪽에만 들어간 보강을 다른 쪽에서 빠뜨린다'
            );
            $this->assertSame($updater, CoreBackupHelper::rootMarkerFileUpdater($file));
        }

        $this->assertNull(CoreBackupHelper::rootMarkerFileUpdater('index.php'), '루트 index.php 는 마커 블록이 없는 통째 교체 파일이다');

        // applyUpdate 를 실행으로 검증하면 실 저장소 base_path 에 쓴다 — 소스 계약으로 잠근다.
        // 정상 targets 분기와 자동 발견 폴백 분기 **둘 다** 갱신기를 경유해야 한다 (한쪽만
        // 고치면 구버전 targets 로 도는 첫 업데이트가 운영자 규칙을 덮어쓴다).
        $source = (string) file_get_contents(base_path('app/Services/CoreUpdateService.php'));

        // 호출 **개수** 만 세면 두 호출이 어느 분기에 있는지, 그 분기가 실제로 파일 복사
        // 경로인지를 아무것도 보지 못한다. 각 분기의 판정문 바로 뒤에 갱신기 호출이 오고,
        // 그 else 가 통째 복사인지를 확인한다. 판정문은 리터럴 파일명이 아니라 SSoT 조회여야 한다.
        foreach ([
            'CoreBackupHelper::rootMarkerFileUpdater($normalizedTarget)' => '정상 targets 분기',
            'CoreBackupHelper::rootMarkerFileUpdater($normalized)' => '자동 발견 폴백 분기',
        ] as $branch => $label) {
            $at = strpos($source, $branch);
            $this->assertNotFalse($at, $label.'의 마커 파일 판정이 SSoT 조회가 아니다 — 리터럴 파일명이면 web.config 가 통째 복사로 되돌아간다 (D25)');

            // 창 크기는 두 판정문 사이의 본문을 담을 만큼 — 형제 파일(`index.php`)의
            // 통보 배선이 else 안에 함께 들어온다.
            $body = substr($source, $at, 700);
            $this->assertStringContainsString(
                '::apply(',
                $body,
                $label.'이 갱신기를 경유하지 않는다 — 운영자가 블록 밖에 적은 서버 규칙이 덮인다 (D25)'
            );
            // else 는 **실패를 경고로 잡는 복사 경로**여야 한다. 종전에는 여기서
            // `FilePermissionHelper::copyFile()` 를 직접 불렀는데 그 헬퍼는 `void` 라
            // `File::copy()` 의 실패를 판정하지 않는다.
            $this->assertStringContainsString(
                'copyRootFileOrWarn(',
                $body,
                $label.'의 else 가 실패를 경고로 잡지 않는다 — 루트 파일 쓰기 실패가 업데이트 전체를 중단시킨다'
            );
            $this->assertLessThan(
                strpos($body, 'copyRootFileOrWarn('),
                strpos($body, '::apply('),
                $label.'에서 갱신기가 else 쪽에 있다 — 조건이 뒤집혔다'
            );

            // 형제 파일(루트 `index.php`)도 같은 통로로 사실을 싣는다.
            $this->assertStringContainsString(
                'recordRootIndexReplacement(',
                $body,
                $label.'이 루트 index.php 교체 사실을 통보 통로에 싣지 않는다'
            );
            $this->assertLessThan(
                strpos($body, 'copyRootFileOrWarn('),
                strpos($body, 'recordRootIndexReplacement('),
                $label.'에서 통보가 복사 뒤에 있다 — 교체 전 내용을 읽어야 판정할 수 있다'
            );
        }

        // 리터럴 파일명 분기가 되살아나면 집합 도출이 사문화된다.
        $this->assertStringNotContainsString("=== '.htaccess') {", $source, '루트 .htaccess 판정이 리터럴로 되돌아갔다 — 마커 파일 집합 도출을 우회한다');
    }

    /**
     * 루트 `web.config` 는 형제 `.htaccess` 와 **대칭인 마커 블록**을 갖는다 (G3 회차 16 W2 C-1).
     *
     * 마커는 XML 주석 한 줄이고 블록은 `<configuration>` 의 자식 자리에 있어, 운영자가 블록
     * 밖에 다른 자식 요소(`<system.web>`·`<location>`)를 두어도 교체 결과가 유효한 XML 로 남는다.
     * 블록 밖에 규칙이 남아 있으면 코어 갱신이 그 규칙을 놓친다 — 그래서 `<system.webServer>`
     * 전체가 블록 안이어야 한다.
     *
     * @scenario server_kind=iis_webconfig, htaccess_operator_state=pristine
     *
     * @effects root_web_config_carries_a_managed_block_symmetric_to_htaccess
     */
    public function test_root_web_config_carries_a_managed_block_as_xml_comments(): void
    {
        $webConfig = (string) file_get_contents(base_path('web.config'));

        $block = RootWebConfigUpdater::extractBlock($webConfig);
        $this->assertNotNull($block, '루트 web.config 는 갱신기가 인식하는 G7 마커 블록을 가져야 한다');

        // 블록이 `<system.webServer>` 전체를 감싼다 — 블록 밖에 규칙이 남으면 코어 갱신이 놓친다.
        $this->assertStringContainsString('<system.webServer>', $block);
        $this->assertStringContainsString('</system.webServer>', $block);
        $outside = str_replace($block, '', $webConfig);
        $this->assertStringNotContainsString('<rule ', $outside, '블록 밖에 재작성 규칙이 있다 — 코어 갱신이 그 규칙을 놓친다');
        $this->assertStringNotContainsString('<system.webServer', $outside);

        // 블록은 `<configuration>` 의 자식 자리다 — 그 밖(선언·루트 요소 밖)이면 교체가 XML 을 깬다.
        // 머리말 주석이 그 태그 이름을 인용하므로 **마지막** 출현(실제 루트 요소)으로 잰다.
        $blockAt = (int) strpos($webConfig, $block);
        $this->assertLessThan($blockAt, (int) strrpos($webConfig, '<configuration>'));
        $this->assertGreaterThan($blockAt + strlen($block) - 1, (int) strrpos($webConfig, '</configuration>'));

        // 마커가 파일 전체에서 정확히 한 벌이다 — 머리말이 마커 줄을 컬럼 0 에 인용하면 두 벌로 읽힌다.
        $this->assertSame(1, preg_match_all('/^<!--\s+BEGIN G7\s+-->/m', $webConfig));
        $this->assertSame(1, preg_match_all('/^<!--\s+END G7\s+-->/m', $webConfig));

        // 머리말이 「통째 교체」를 더 이상 선언하지 않는다 — 그 문장이 남으면 운영자가 블록 밖에
        // 규칙을 두지 않고 다른 파일로 옮긴다(보존 규칙이 사문화된다).
        $this->assertStringNotContainsString('통째로 교체', $webConfig, 'web.config 머리말이 옛 규약(통째 교체)을 선언한다');
        $this->assertStringContainsString('블록 **밖**', $webConfig, 'web.config 머리말이 블록 밖 보존 규칙을 안내하지 않는다');
    }

    /**
     * 교체되는 루트 `index.php` 는 **사본이 실제로 남고**, 통보가 그 경로를 싣는다.
     *
     * 배선·순서만 재는 위 검사로는 부족했다 — 통보 문구가 「그 내용은 백업에만 남습니다」
     * 였는데, 성공 경로는 그 통보 직후 백업을 **무조건** 지운다(보존 옵션이 없다). 즉
     * 실행할 수 없는 안내였고, 운영자가 프로젝트 루트에 두던 자체 `index.php` 를 되찾을
     * 수단이 실제로는 없었다. 오류도 경고도 남지 않는다 — 파일이 조용히 사라질 뿐이다.
     *
     * 사본 위치(`storage/app/core-update/<타임스탬프>/index.php`)는 `update.targets` 밖이자
     * `update.protected_paths` 안이라 적용·자동 발견 폴백·롤백 prune 어느 쪽도 건드리지
     * 않는다. 이 테스트는 그 사본이 남는지와 그 경로가 문구에 실리는지를 함께 잠근다.
     *
     * 대조군을 같은 메서드에 둔다 — 코어 shim 을 교체할 때는 통보도 사본도 없어야 한다.
     * 그것이 없으면 「언제나 사본을 남기고 언제나 경고하는」 구현으로도 초록이 된다.
     *
     * @effects replaced_root_index_is_recoverable_after_the_backup_is_deleted
     */
    public function test_replaced_root_index_is_copied_aside_and_the_copy_path_reaches_the_operator(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-root-index-'.bin2hex(random_bytes(4));
        $site = $work.DIRECTORY_SEPARATOR.'site';
        $storage = $work.DIRECTORY_SEPARATOR.'storage';
        mkdir($site, 0755, true);
        mkdir($storage, 0755, true);

        $destination = $site.DIRECTORY_SEPARATOR.'index.php';
        $original = "<?php\n// 이 사이트가 프로젝트 루트에 두던 자체 랜딩 스크립트\nheader('Location: /shop');\n";
        file_put_contents($destination, $original);

        // 사본 위치는 `DIRECTORY_SEPARATOR` 로 직접 조립한다 — `glob()` 는 패턴의 구분자를
        // 그대로 돌려주므로 Windows 에서 서비스가 만든 경로와 문자열이 어긋나 「사본이 없다」는
        // 거짓 빨강이 된다.
        $copyRoot = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update';

        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($storage);

        try {
            $service = new CoreUpdateService;
            (new \ReflectionMethod($service, 'recordRootIndexReplacement'))->invoke($service, $destination);

            $warnings = $service->getLastRootFileWarnings();
            $this->assertCount(1, $warnings, '코어 판본이 아닌 루트 진입 파일의 교체는 반드시 통보된다');

            $this->assertDirectoryExists(
                $copyRoot,
                '교체 직전 내용의 사본이 남지 않으면 되찾을 수단이 없다 — 성공 경로는 백업을 무조건 지운다'
            );
            $stamps = array_values(array_diff((array) scandir($copyRoot), ['.', '..']));
            $this->assertCount(1, $stamps, '사본 디렉토리가 정확히 하나 만들어져야 한다');

            $copy = $copyRoot.DIRECTORY_SEPARATOR.$stamps[0].DIRECTORY_SEPARATOR.'index.php';
            $this->assertFileExists($copy);
            $this->assertSame(
                $original,
                (string) file_get_contents($copy),
                '사본 내용이 교체 직전 원본과 달라지면 되찾아도 소용이 없다'
            );

            $this->assertStringContainsString(
                $copy,
                $warnings[0],
                '통보가 사본 경로를 싣지 않으면 운영자는 그 사본이 있다는 사실을 알 수 없다'
            );
            $this->assertNotSame(
                __('settings.core_update.root_index_replaced_not_preserved', ['path' => $destination]),
                $warnings[0],
                '사본을 남겼는데 「남기지 못했다」로 보고하면 운영자가 복구를 포기한다'
            );

            // 대조군 — 코어 shim 을 교체하는 정상 경로에는 통보도 사본도 없다.
            file_put_contents($destination, "<?php\n\nrequire __DIR__.'/public/index.php';\n");

            $shimService = new CoreUpdateService;
            (new \ReflectionMethod($shimService, 'recordRootIndexReplacement'))->invoke($shimService, $destination);

            $this->assertSame([], $shimService->getLastRootFileWarnings(), '코어 판본 교체는 알릴 것이 없다');
            $this->assertCount(
                1,
                array_diff((array) scandir($copyRoot), ['.', '..']),
                '코어 판본을 교체할 때도 사본을 남기면 매 업데이트마다 쓸모없는 사본이 쌓인다'
            );
        } finally {
            app()->useStoragePath($originalStoragePath);
            $this->removeDirectory($work);
        }
    }

    /**
     * 테스트 임시 디렉토리를 재귀 삭제합니다.
     *
     * @param  string  $path  삭제할 절대 경로
     */
    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }

    /**
     * `upgrades/` 최상위 스텝의 버전이 **릴리즈 버전보다 앞서지 않는다.**
     *
     * 발견 경로는 `version_compare($version, $toVersion, '<=')` 다 — 스텝 버전이 릴리즈
     * 버전보다 크면 그 스텝은 **한 번도 실행되지 않는다.** 이 안이 만든 7.1.0 스텝은
     * 7.0.x 가 문서 루트 **안**에 남긴 공개 파일 사본을 걷어 내는 일을 하므로, 릴리즈가
     * 7.0.9 로 나가면 그 사본이 영구히 제2 URL 로 서빙된다 — 오류도 경고도 없다.
     *
     * 코어 버전이 아직 bump 되지 않은 working 단계에서는 스텝이 앞서 있는 것이 정상이므로,
     * 이 검사는 **그 사실을 드러내되 막지 않는다**: 스텝 버전이 앞서면 릴리즈 전에 스텝
     * 파일명을 실제 릴리즈 버전으로 맞춰야 한다는 것을 메시지로 못박는다.
     *
     * @effects upgrade_step_version_must_not_outrun_the_release
     */
    public function test_upgrade_step_versions_are_reachable_from_the_declared_core_version(): void
    {
        $coreVersion = (string) config('app.version');
        $this->assertNotSame('', $coreVersion);

        $steps = (array) glob(base_path('upgrades/Upgrade_*.php'));

        // 모집단이 비면 아래 단언은 `0 <= 1` 이라 **언제나 통과**한다 — 검사했다는 것과
        // 볼 것이 없었다는 것이 구분되지 않는다. 이 파일의 다른 축은 전부 이 대조를 갖고
        // 있는데 이 축만 없었다. 경로 규칙이 바뀌거나 디렉토리가 사라지면 여기서 red 가 된다.
        $this->assertNotEmpty(
            $steps,
            'upgrades/Upgrade_*.php 도출이 비었다 — 이 검사가 아무것도 재지 않는다 (경로 규칙이 실물과 어긋났다)'
        );

        $ahead = [];
        foreach ($steps as $path) {
            $name = basename((string) $path, '.php');
            $version = str_replace('_', '.', substr($name, strlen('Upgrade_')));

            if (version_compare($version, $coreVersion, '>')) {
                $ahead[] = $name.' (스텝 '.$version.' > 코어 '.$coreVersion.')';
            }
        }

        // 앞서 있는 스텝이 있으면 그 사실 자체는 정상(미릴리스 working 단계)이지만,
        // **릴리즈 버전을 확정할 때 그 이름을 맞춰야 한다**는 것을 기록으로 남긴다.
        $this->assertLessThanOrEqual(
            1,
            count($ahead),
            '코어 버전보다 앞선 업그레이드 스텝이 둘 이상이다 — 그중 어느 것도 실행되지 않는다: '.implode(', ', $ahead)
        );
    }

    /**
     * 미설치 안내의 목적지는 **JS 문자열 인코더**로 실린다.
     *
     * `<script>` 안은 HTML 파서가 실체 참조를 해석하지 않는 raw text 라, 그 자리에
     * `htmlspecialchars()` 를 쓰면 보호도 되지 않고 값도 바뀐다 — 주소에 `&quot;` 가
     * 그대로 들어가거나, JS 가 `\i`·`A` 을 해석해 **경로가 조용히 다른 값**이
     * 된다(실측). 오류가 나지 않고 다른 주소로 이동하는 형태다.
     *
     * 목적지의 출처가 요청 경로에서 잘라 온 값이라 요청자 영향을 받는다
     * (10차 G3 라운드 D10-8).
     *
     * @effects install_redirect_destination_uses_a_javascript_encoder
     */
    public function test_install_redirect_uses_a_javascript_string_encoder(): void
    {
        $source = (string) file_get_contents(base_path('public/index.php'));

        // 주석을 걷어낸다 — 위 설명이 그 함수 이름을 언급하므로 자기충족을 피한다.
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^
]*#'], '', $source);

        $this->assertMatchesRegularExpression(
            '/window\.location\.href=/',
            $code,
            '미설치 안내의 이동 지점을 찾지 못했다 — 이 검사가 아무것도 재지 않는다'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/window\.location\.href="\s*\.\s*htmlspecialchars/',
            $code,
            'JS 문자열 자리에 HTML 이스케이프를 쓴다 — `<script>` 안에서는 해석되지 않아 주소가 조용히 달라진다'
        );

        $this->assertMatchesRegularExpression(
            '/json_encode\(\s*\$installUrl/',
            $code,
            '목적지가 JS 문자열 인코더(json_encode)를 거치지 않는다'
        );

        foreach (['JSON_HEX_TAG', 'JSON_HEX_QUOT', 'JSON_UNESCAPED_SLASHES'] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $code,
                "미설치 안내 인코딩에 {$flag} 가 없다 — `</script>` 조기 종료·따옴표 탈출을 막지 못한다"
            );
        }
    }
}
