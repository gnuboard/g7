<?php

namespace Tests\Unit\Upgrades;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\UpgradeContext;
use App\Services\CoreUpdateService;
use App\Upgrades\Data\V7_2_0\Migrations\SeedDeployFragmentLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 7.2.0 스텝 — `deploy/**` 해시 원장 씨앗 (G3 회차 16 W2 A2 ③).
 *
 * 원장이 없는 설치본(이 규칙 이전에 설치됐거나, 구버전 부모 프로세스가 적용을 수행한 사이트)은
 * 다음 업데이트에서 전 조각이 「판정 불가 → 보존 + `.dist`」 로 떨어진다. 업그레이드 스텝이 도는
 * 시점의 디스크는 — 사이드카가 없는 조각에 한해 — 릴리즈 판본과 바이트 동일하므로 그 해시로 씨앗한다.
 *
 * 스텝은 경로 C 규율(기존 클래스의 신규 메서드 호출 금지)에 따라 로컬 로직으로 씨앗한다. 그래서
 * 서비스의 `seedDeployFragmentLedger()` 와 **같은 결과**를 내는지를 여기서 대조한다 — 두 구현이
 * 갈리면 원장 형식이 둘이 되어 다음 업데이트가 한쪽을 읽지 못한다.
 */
class SeedDeployFragmentLedgerTest extends TestCase
{
    private string $sandbox;

    private string $originalBasePath;

    private string $originalStoragePath;

    /**
     * 스텝 파일을 읽어 들이고 deploy 와 storage 를 갖춘 임시 샌드박스로 기준 경로와 storage 경로를 옮긴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 스텝 데이터 마이그레이션은 오토로드 밖이다 — 스텝 러너가 `require_once` 로
        // 읽으므로 테스트도 같은 방식으로 읽는다 (형제 01_ 테스트와 같다).
        require_once base_path('upgrades/data/7.2.0/migrations/05_SeedDeployFragmentLedger.php');

        $this->sandbox = storage_path('test_seed_step_'.uniqid());
        File::ensureDirectoryExists($this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx');
        File::ensureDirectoryExists($this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy');
        File::ensureDirectoryExists($this->sandbox.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');

        $this->originalBasePath = base_path();
        $this->originalStoragePath = app()->storagePath();
        app()->setBasePath($this->sandbox);
        app()->useStoragePath($this->sandbox.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * 기준 경로와 storage 경로를 원래대로 되돌리고 샌드박스를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        app()->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * 원장이 없을 때 스텝이 디스크 해시로 원장을 씨앗하고, 사이드카가 있는 조각은 건너뛰며, 결과가 서비스 씨앗기와 같은지 확인한다.
     *
     * @scenario deploy_fragment_ledger=absent, deploy_fragment_state=unmodified, process_shape=spawn_child
     *
     * @effects deploy_fragment_ledger_can_be_seeded_from_disk_without_touching_preserved_fragments
     *
     * @return void 반환값 없음
     */
    public function test_step_seeds_the_ledger_from_disk_and_matches_the_service_seeder(): void
    {
        $nginx = $this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
        $caddy = $this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy'.DIRECTORY_SEPARATOR.'g7-layout2.caddyfile';
        File::put($nginx, "# nginx\n");
        File::put($caddy, "# caddy operator\n");
        File::put($caddy.CoreUpdateService::DEPLOY_FRAGMENT_DIST_SUFFIX, "# caddy release\n");

        $ledger = storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER);

        (new SeedDeployFragmentLedger)->run(new UpgradeContext('7.1.3', '7.2.0', '7.2.0'));

        $this->assertFileExists($ledger, '스텝이 원장을 씨앗하지 않았다 — 다음 업데이트가 전 조각을 「판정 불가」로 본다');
        $fromStep = json_decode((string) File::get($ledger), true);
        $this->assertSame(hash('sha256', "# nginx\n"), $fromStep['nginx/g7-layout2.conf'] ?? null);
        $this->assertArrayNotHasKey('caddy/g7-layout2.caddyfile', $fromStep, '사이드카가 있는 조각을 씨앗하면 운영자 수정본이 다음 업데이트에서 덮인다');

        // 서비스 씨앗기와 같은 결과 — 형식·모집단·해시 알고리즘이 갈리면 다음 업데이트가 한쪽을 읽지 못한다.
        File::delete($ledger);
        (new CoreUpdateService)->seedDeployFragmentLedger($this->sandbox);
        $this->assertSame(json_decode((string) File::get($ledger), true), $fromStep, '스텝과 서비스의 원장이 다르다');

        // 멱등 — 원장이 있으면 손대지 않는다.
        File::put($nginx, "# nginx changed\n");
        (new SeedDeployFragmentLedger)->run(new UpgradeContext('7.1.3', '7.2.0', '7.2.0'));
        $this->assertSame($fromStep, json_decode((string) File::get($ledger), true), '원장이 있는데 스텝이 덮어썼다');
    }

    /**
     * 부분 원장의 빠진 키를 서비스·스텝 **양쪽**이 채운다 (17차 C-4).
     *
     * `applyDeployFragments()` 는 세 지점(선택 적용 제외·소스 읽기 실패·복사 검증 실패)에서 조각을
     * 원장에 넣지 않고 빠져나간다 — 그러면 부분 원장이 남는데, 종전 씨앗기는 `is_file()` 하나로 조기
     * 반환해 그 빠진 키를 영원히 못 채웠다(오류 없음). 이미 있는 키의 근거 해시는 보존하고 빠진 것만
     * 채운다.
     *
     * @scenario deploy_fragment_ledger=present, deploy_fragment_state=operator_modified
     *
     * @effects deploy_fragment_ledger_can_be_seeded_from_disk_without_touching_preserved_fragments
     *
     * @return void 반환값 없음
     */
    public function test_partial_ledger_is_merged_not_skipped_by_both_the_service_and_the_step(): void
    {
        $nginx = $this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
        $caddy = $this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy'.DIRECTORY_SEPARATOR.'g7-layout2.caddyfile';
        File::put($nginx, "# nginx\n");
        File::put($caddy, "# caddy\n");

        $ledgerPath = storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER);

        // 부분 원장 — nginx 만 있다(운영자 근거 해시). caddy 는 빠져 있다.
        File::ensureDirectoryExists(dirname($ledgerPath));
        File::put($ledgerPath, (string) json_encode(['nginx/g7-layout2.conf' => 'operator-reference-hash'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 서비스 — 빠진 caddy 만 채우고 nginx 근거 해시는 보존한다.
        $added = (new CoreUpdateService)->seedDeployFragmentLedger($this->sandbox);
        $this->assertSame(['caddy/g7-layout2.caddyfile'], $added, '부분 원장의 빠진 조각이 채워지지 않았다 (조기 반환 회귀)');

        $ledger = json_decode((string) File::get($ledgerPath), true);
        $this->assertSame('operator-reference-hash', $ledger['nginx/g7-layout2.conf'], '이미 있던 키의 근거 해시가 덮였다 — 운영자 수정본이 덮인다');
        $this->assertSame(hash('sha256', "# caddy\n"), $ledger['caddy/g7-layout2.caddyfile']);

        // 스텝 — 같은 병합. 부분 원장을 다시 만들고 스텝을 돌린다.
        File::put($ledgerPath, (string) json_encode(['nginx/g7-layout2.conf' => 'operator-reference-hash'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        (new SeedDeployFragmentLedger)->run(new UpgradeContext('7.1.3', '7.2.0', '7.2.0'));

        $fromStep = json_decode((string) File::get($ledgerPath), true);
        $this->assertSame('operator-reference-hash', $fromStep['nginx/g7-layout2.conf'], '스텝이 이미 있던 키를 덮었다');
        $this->assertSame(hash('sha256', "# caddy\n"), $fromStep['caddy/g7-layout2.caddyfile'], '스텝이 빠진 조각을 채우지 않았다');
    }

    /**
     * 점 파일/디렉토리는 서비스·스텝 **양쪽**의 모집단에서 빠진다 (17차 C-1).
     *
     * 서비스 씨앗기는 `File::allFiles()`(`ignoreDotFiles(true)`)로 점 파일을 제외하는데, 스텝은
     * `scandir` 로 점 파일까지 포함해 두 구현의 모집단이 갈렸다 — 같은 디스크가 두 원장을 만들었다.
     *
     * @scenario deploy_fragment_ledger=absent, deploy_fragment_state=unmodified
     *
     * @effects deploy_fragment_ledger_can_be_seeded_from_disk_without_touching_preserved_fragments
     *
     * @return void 반환값 없음
     */
    public function test_dotfiles_are_excluded_by_both_the_service_and_the_step(): void
    {
        $deployDir = $this->sandbox.DIRECTORY_SEPARATOR.'deploy';
        File::put($deployDir.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf', "# nginx\n");
        File::put($deployDir.DIRECTORY_SEPARATOR.'.hidden', "secret\n");
        File::put($deployDir.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'.keep', "x\n");

        $ledgerPath = storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER);

        $added = (new CoreUpdateService)->seedDeployFragmentLedger($this->sandbox);
        $this->assertSame(['nginx/g7-layout2.conf'], $added, '점 파일이 서비스 씨앗기 모집단에 들었다');

        File::delete($ledgerPath);
        (new SeedDeployFragmentLedger)->run(new UpgradeContext('7.1.3', '7.2.0', '7.2.0'));

        $fromStep = json_decode((string) File::get($ledgerPath), true);
        $this->assertArrayNotHasKey('.hidden', $fromStep, '스텝이 점 파일을 원장에 넣었다 — 서비스와 모집단이 갈린다');
        $this->assertArrayNotHasKey('nginx/.keep', $fromStep, '스텝이 하위 점 파일을 원장에 넣었다');
        $this->assertSame(['nginx/g7-layout2.conf'], array_keys($fromStep), '스텝과 서비스의 모집단이 갈렸다');
    }

    /**
     * 스텝 파일은 원장 이름·사이드카 접미사를 서비스 상수와 **같은 값**으로 갖는다.
     *
     * 경로 C 규율로 리터럴을 둘 수밖에 없다 — 그 리터럴이 서비스 상수와 갈리면 위 대조는 빈 원장끼리
     * 같아 초록이 될 수 있으므로 값 자체를 대조한다.
     *
     * @scenario deploy_fragment_ledger=absent, process_shape=spawn_child
     *
     * @effects deploy_fragment_ledger_can_be_seeded_from_disk_without_touching_preserved_fragments
     *
     * @return void 반환값 없음
     */
    public function test_step_literals_match_the_service_constants(): void
    {
        $this->assertSame(CoreUpdateService::DEPLOY_FRAGMENT_LEDGER, SeedDeployFragmentLedger::LEDGER_FILE);
        $this->assertSame(CoreUpdateService::DEPLOY_FRAGMENT_DIST_SUFFIX, SeedDeployFragmentLedger::DIST_SUFFIX);
        $this->assertSame(CoreUpdateService::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX, SeedDeployFragmentLedger::DIST_NEW_SUFFIX);
    }

    /**
     * 원장 쓰기 실패가 **운영자에게 도달**한다 (23차 D23-13 의 조치를 재는 자리).
     *
     * 이 스텝이 도는 무CLI 동선의 운영자는 `storage/logs/*.log` 를 열 수단이 없다. 그래서
     * 실패는 로그가 아니라 형제 스텝과 같은 통로(`StorageLinkHelper` 의 경고 버퍼 —
     * 스텝 러너가 종료 시 비워 화면에 낸다)로 나가야 한다.
     *
     * 이 케이스가 없으면 그 통보를 통째로 지워도 이 클래스가 전부 초록이다
     * (24차 G3 라운드 R24-C M14 실측).
     *
     * @scenario deploy_fragment_ledger=absent, deploy_fragment_state=unmodified, process_shape=spawn_child
     *
     * @effects deploy_fragment_ledger_write_failure_reaches_the_operator
     *
     * @return void 반환값 없음
     */
    public function test_ledger_write_failure_reaches_the_operator_not_only_the_log(): void
    {
        $nginx = $this->sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
        File::put($nginx, "# nginx\n");

        // 원장이 놓일 자리를 **파일**로 막아 디렉토리 생성·쓰기를 불가능하게 만든다
        // (권한 조작은 Windows 에서 재현되지 않는다 — 형제 인스톨러 테스트와 같은 수법).
        $ledgerDir = storage_path('app'.DIRECTORY_SEPARATOR.'core-update');
        File::ensureDirectoryExists(dirname($ledgerDir));
        File::put($ledgerDir, 'not a directory');

        StorageLinkHelper::takeDocrootExposureWarnings();

        (new SeedDeployFragmentLedger)->run(new UpgradeContext('7.1.3', '7.2.0', '7.2.0'));

        $warnings = StorageLinkHelper::takeDocrootExposureWarnings();

        $this->assertNotEmpty(
            $warnings,
            '원장 쓰기가 실패했는데 운영자 통로가 비었다 — 무CLI 동선의 운영자는 로그 파일을 '
                .'열 수단이 없어 원장 손실을 영영 모른다'
        );
    }
}
