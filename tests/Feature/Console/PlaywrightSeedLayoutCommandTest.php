<?php

namespace Tests\Feature\Console;

use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * playwright:seed-layout Artisan 커맨드 Feature 테스트.
 *
 * 레이아웃 편집기 E2E 중 저장(PUT)까지 수행하는 spec 전용 시드 화면(`e2e_sandbox`)의
 * 설치/제거를 검증한다. 이 커맨드가 없으면 저장 spec 이 제품 화면(`home` 등)에 편집 결과를
 * 영속시켜 실행마다 노드가 누적된다.
 *
 * 검증 축:
 *   - 옵트인 가드 (`G7_PLAYWRIGHT_BYPASS`) 부재 시 실패
 *   - 설치: 레이아웃 파일 + routes.json 라우트 + DB 행 3종 동시 생성
 *   - 설치 멱등: 반복 실행이 라우트를 중복 추가하지 않음
 *   - 제거: 3종 모두 정리, 다른 레이아웃/라우트 무영향
 *   - 대상 = 활성 템플릿(종류별 하나). 코어가 특정 템플릿 식별자를 적어 두지 않는다(2026-09-28 결정) —
 *     활성 템플릿이 없으면 아무것도 건드리지 않고 실패한다
 *
 * 파일 시스템은 테스트 DB 와 달리 격리되지 않으므로, 활성 템플릿의 `routes.json` 원본을
 * setUp 에서 보관해 tearDown 에서 되돌린다.
 */
class PlaywrightSeedLayoutCommandTest extends TestCase
{
    use RefreshDatabase;

    /** 테스트가 활성 사용자 템플릿으로 등록하는 식별자 — 활성 디렉토리가 있는 개발 환경의 템플릿 */
    private const TEMPLATE = 'sirsoft-basic';

    /** 하드코딩 부재를 증명할 임시 템플릿 식별자 — 테스트가 디렉토리째 만들고 지운다 */
    private const PROBE_TEMPLATE = 'e2e-seed-probe-template';

    private const LAYOUT_NAME = 'e2e_sandbox';

    /** 활성 routes.json 원본 내용 (tearDown 복원용). 활성 디렉토리 부재 시 null. */
    private ?string $routesBackup = null;

    /**
     * 테스트 준비 — 활성 routes.json 원본을 보관합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (File::exists($this->routesPath())) {
            $this->routesBackup = File::get($this->routesPath());
        }
    }

    /**
     * 테스트 정리 — routes.json 을 되돌리고 시드 파일·임시 템플릿 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('templates/'.self::PROBE_TEMPLATE));

        if ($this->routesBackup !== null) {
            File::put($this->routesPath(), $this->routesBackup);
        }
        if (File::exists($this->layoutPath())) {
            File::delete($this->layoutPath());
        }
        if (File::exists($this->routesBackupPath())) {
            File::delete($this->routesBackupPath());
        }
        $this->clearBypassFlag();

        parent::tearDown();
    }

    /**
     * 옵트인 환경변수가 없으면 실패한다.
     *
     * @effects seed_layout_command_requires_explicit_optin_env
     *
     * @return void 반환값 없음
     */
    public function test_옵트인_환경변수가_없으면_실패한다(): void
    {
        $this->clearBypassFlag();

        $this->artisan('playwright:seed-layout')
            ->expectsOutputToContain('G7_PLAYWRIGHT_BYPASS=1')
            ->assertExitCode(1);
    }

    /**
     * 설치가 레이아웃 파일과 라우트와 DB 행을 함께 만든다.
     *
     * @scenario action=install, template_active=yes
     *
     * @effects seed_layout_install_creates_file_route_and_db_row
     *
     * @return void 반환값 없음
     */
    public function test_설치가_레이아웃_파일과_라우트와_d_b행을_함께_만든다(): void
    {
        $this->skipIfTemplateDirMissing();
        $template = $this->makeTemplate();

        $this->runSeedCommand();

        $this->assertTrue(File::exists($this->layoutPath()), '시드 레이아웃 파일이 생성되어야 한다');
        $this->assertCount(1, $this->seedRoutes(), 'routes.json 에 시드 라우트가 1건 있어야 한다');
        $this->assertSame('/e2e-sandbox', $this->seedRoutes()[0]['path']);
        $this->assertSame(self::LAYOUT_NAME, $this->seedRoutes()[0]['layout']);

        $layout = TemplateLayout::where('template_id', $template->id)
            ->where('name', self::LAYOUT_NAME)
            ->first();
        $this->assertNotNull($layout, '시드 레이아웃 DB 행이 생성되어야 한다');
        $this->assertNotEmpty($layout->content, '시드 레이아웃 content 가 비어 있지 않아야 한다');
        $this->assertNotEmpty($layout->original_content_hash);
        $this->assertGreaterThan(0, $layout->original_content_size);
    }

    /**
     * 설치를 반복해도 라우트가 중복되지 않는다.
     *
     * @effects seed_layout_install_is_idempotent_no_duplicate_route
     *
     * @return void 반환값 없음
     */
    public function test_설치를_반복해도_라우트가_중복되지_않는다(): void
    {
        $this->skipIfTemplateDirMissing();
        $this->makeTemplate();

        $this->runSeedCommand();
        $routeCountAfterFirst = count($this->allRoutes());

        $this->runSeedCommand();

        $this->assertCount(1, $this->seedRoutes(), '반복 실행에도 시드 라우트는 1건이어야 한다');
        $this->assertCount(
            $routeCountAfterFirst,
            $this->allRoutes(),
            '반복 실행이 라우트 총 개수를 늘리면 안 된다'
        );
    }

    /**
     * 제거가 파일과 라우트와 DB 행을 모두 정리한다.
     *
     * @scenario action=remove, template_active=yes
     *
     * @effects seed_layout_remove_cleans_file_route_and_db_row, seed_layout_remove_keeps_other_routes
     *
     * @return void 반환값 없음
     */
    public function test_제거가_파일과_라우트와_d_b행을_모두_정리한다(): void
    {
        $this->skipIfTemplateDirMissing();
        $template = $this->makeTemplate();

        $this->runSeedCommand();
        $routeCountWithSeed = count($this->allRoutes());

        $this->runSeedCommand(['--remove' => true]);

        $this->assertFalse(File::exists($this->layoutPath()), '시드 레이아웃 파일이 삭제되어야 한다');
        $this->assertCount(0, $this->seedRoutes(), 'routes.json 에서 시드 라우트가 제거되어야 한다');
        $this->assertCount(
            $routeCountWithSeed - 1,
            $this->allRoutes(),
            '시드 라우트 1건만 줄어야 한다 (다른 라우트 무영향)'
        );
        $this->assertFalse(
            TemplateLayout::withTrashed()
                ->where('template_id', $template->id)
                ->where('name', self::LAYOUT_NAME)
                ->exists(),
            '시드 레이아웃 DB 행이 영구 삭제되어야 한다 (soft delete 잔여 행도 없어야 재설치가 충돌하지 않는다)'
        );
    }

    /**
     * 활성 템플릿이 없으면 아무것도 깔지 않고 실패한다.
     *
     * @scenario action=install, template_active=no
     *
     * @effects seed_layout_fails_without_an_active_template
     *
     * @return void 반환값 없음
     */
    public function test_활성_템플릿이_없으면_설치하지_않고_실패한다(): void
    {
        $this->skipIfTemplateDirMissing();

        // Template 행을 만들지 않는다 — 대상은 활성 템플릿뿐이라 깔 곳이 없다. 조용한 성공은 E2E 가
        // 시드 화면 없이 돌다 엉뚱한 곳에서 실패하게 만든다.
        $this->runSeedCommand(exitCode: 1);

        $this->assertFalse(File::exists($this->layoutPath()), '대상이 없으면 파일을 만들지 않아야 한다');
    }

    /**
     * 활성 템플릿이 없으면 제거도 파일을 건드리지 않는다.
     *
     * @scenario action=remove, template_active=no
     *
     * @effects seed_layout_remove_without_active_template_touches_no_files
     *
     * @return void 반환값 없음
     */
    public function test_활성_템플릿이_없으면_제거도_파일을_건드리지_않는다(): void
    {
        $this->skipIfTemplateDirMissing();

        // 이전 실행이 남긴 시드 파일이 있다고 가정 — 활성 템플릿으로 식별되지 않은 디렉토리는 건드리지
        // 않아야 한다(템플릿 식별에 실패한 상태로 파일만 지우면 DB 행과 파일이 어긋난다).
        File::put($this->layoutPath(), '{"layout_name":"e2e_sandbox"}');
        $routesBefore = File::get($this->routesPath());

        $this->runSeedCommand(['--remove' => true], exitCode: 1);

        $this->assertTrue(File::exists($this->layoutPath()), '활성이 아닌 템플릿의 파일은 건드리지 않아야 한다');
        $this->assertSame($routesBefore, File::get($this->routesPath()), 'routes.json 도 변경되지 않아야 한다');
    }

    /**
     * 제거가 routes.json 을 바이트 동일하게 복원한다.
     *
     * @effects seed_layout_remove_restores_routes_json_byte_for_byte
     *
     * @return void 반환값 없음
     */
    public function test_제거가_routes_json_을_바이트_동일하게_복원한다(): void
    {
        $this->skipIfTemplateDirMissing();
        $this->makeTemplate();

        // 원본은 주석 그룹 사이 빈 줄 같은 서식을 갖는다. JSON 재직렬화만으로는 그 서식이 사라지므로
        // 설치 시 원본을 보관하고 제거 시 그대로 되돌려야 한다.
        $original = File::get($this->routesPath());

        $this->runSeedCommand();
        $this->assertNotSame($original, File::get($this->routesPath()), '설치는 routes.json 을 바꿔야 한다');
        $this->assertTrue(File::exists($this->routesBackupPath()), '원본 백업이 남아야 한다');

        $this->runSeedCommand(['--remove' => true]);

        $this->assertSame($original, File::get($this->routesPath()), 'routes.json 이 바이트 동일하게 복원되어야 한다');
        $this->assertFalse(File::exists($this->routesBackupPath()), '복원 후 백업 파일은 정리되어야 한다');
    }

    /**
     * 반복 설치가 원본 백업을 시드 포함본으로 덮어쓰지 않는다.
     *
     * @effects seed_layout_repeated_install_keeps_pristine_routes_backup
     *
     * @return void 반환값 없음
     */
    public function test_반복_설치가_원본_백업을_시드_포함본으로_덮어쓰지_않는다(): void
    {
        $this->skipIfTemplateDirMissing();
        $this->makeTemplate();

        $original = File::get($this->routesPath());

        // 비정상 종료로 시드가 남은 상태에서 다시 설치되는 상황 — 그 시점 routes.json 을 "원본" 으로
        // 굳혀 버리면 이후 제거가 시드를 품은 파일을 복원한다.
        $this->runSeedCommand();
        $this->runSeedCommand();

        $this->assertSame($original, File::get($this->routesBackupPath()), '백업은 최초 원본을 유지해야 한다');
    }

    /**
     * 활성 템플릿이 아닌 식별자를 지정하면 경고하고 건너뛴다.
     *
     * @effects seed_layout_warns_on_requested_template_that_is_not_active
     *
     * @return void 반환값 없음
     */
    public function test_활성이_아닌_템플릿을_지정하면_경고하고_건너뛴다(): void
    {
        $this->setBypassFlag();

        try {
            // 대상이 전부 걸러지면 처리할 것이 없다 — 조용한 성공보다 명시적 실패가 낫다.
            $this->artisan('playwright:seed-layout', ['--template' => ['no-such-template']])
                ->expectsOutputToContain('no-such-template')
                ->assertExitCode(1);
        } finally {
            $this->clearBypassFlag();
        }
    }

    /**
     * 어떤 식별자의 템플릿이든 활성이면 그 템플릿에 깐다 — 코어가 식별자를 알지 않는다.
     *
     * @scenario action=install, template_active=yes
     *
     * @effects seed_layout_targets_whatever_template_is_active
     *
     * @return void 반환값 없음
     */
    public function test_어떤_식별자든_활성_템플릿에_설치한다(): void
    {
        $dir = base_path('templates/'.self::PROBE_TEMPLATE);
        File::ensureDirectoryExists($dir.'/layouts');
        File::put($dir.'/routes.json', json_encode(['routes' => []], JSON_PRETTY_PRINT));

        Template::factory()->create([
            'identifier' => self::PROBE_TEMPLATE,
            'type' => 'user',
            'status' => 'active',
        ]);

        $this->runSeedCommand();

        $this->assertTrue(File::exists($dir.'/layouts/'.self::LAYOUT_NAME.'.json'), '활성 템플릿에 시드 파일이 깔려야 한다');
        $routes = json_decode((string) File::get($dir.'/routes.json'), true)['routes'] ?? [];
        $this->assertSame('/e2e-sandbox', $routes[0]['path'] ?? null, '사용자 템플릿 종류의 시드 경로가 붙어야 한다');
    }

    /**
     * 커맨드를 옵트인 환경변수와 함께 실행합니다.
     *
     * @param  array<string, mixed>  $options  추가 커맨드 옵션
     * @param  int  $exitCode  기대 종료 코드
     * @return void 반환값 없음
     */
    private function runSeedCommand(array $options = [], int $exitCode = 0): void
    {
        $this->setBypassFlag();

        try {
            $this->artisan('playwright:seed-layout', $options)->assertExitCode($exitCode);
        } finally {
            $this->clearBypassFlag();
        }
    }

    /**
     * 옵트인 플래그를 설정합니다.
     *
     * Laravel `env()` 는 `$_SERVER` → `$_ENV` → `putenv` 순으로 읽으므로 세 곳을 모두 채운다.
     * `putenv` 만 쓰면 앞선 어댑터가 값을 먼저 결정하는 환경에서 조용히 무시된다.
     *
     * @return void 반환값 없음
     */
    private function setBypassFlag(): void
    {
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 옵트인 플래그를 제거합니다 (다른 테스트로 새지 않도록).
     *
     * @return void 반환값 없음
     */
    private function clearBypassFlag(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');
    }

    /**
     * 대상 템플릿의 DB 행을 만듭니다.
     *
     * @return Template 생성된 템플릿 모델
     */
    private function makeTemplate(): Template
    {
        return Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'type' => 'user',
            'status' => 'active',
        ]);
    }

    /**
     * 활성 템플릿 디렉토리가 없으면 테스트를 건너뜁니다.
     *
     * 활성 디렉토리는 Git 무시 대상이라 클린 체크아웃에는 존재하지 않는다. 그 환경에서는
     * 커맨드가 경고 후 건너뛰므로 본 테스트가 검증할 대상이 없다.
     *
     * @return void 반환값 없음
     */
    private function skipIfTemplateDirMissing(): void
    {
        if (! File::isDirectory(base_path('templates/'.self::TEMPLATE))) {
            $this->markTestSkipped('활성 템플릿 디렉토리('.self::TEMPLATE.')가 없어 건너뜁니다.');
        }
    }

    /**
     * 활성 템플릿의 routes.json 절대 경로를 반환합니다.
     *
     * @return string 절대 경로
     */
    private function routesPath(): string
    {
        return base_path('templates/'.self::TEMPLATE.'/routes.json');
    }

    /**
     * 원본 routes.json 보관 파일 절대 경로를 반환합니다 (커맨드가 설치 시 생성).
     *
     * @return string 절대 경로
     */
    private function routesBackupPath(): string
    {
        return $this->routesPath().'.playwright-backup';
    }

    /**
     * 시드 레이아웃 파일 절대 경로를 반환합니다.
     *
     * @return string 절대 경로
     */
    private function layoutPath(): string
    {
        return base_path('templates/'.self::TEMPLATE.'/layouts/'.self::LAYOUT_NAME.'.json');
    }

    /**
     * 활성 routes.json 의 전체 라우트 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 라우트 목록
     */
    private function allRoutes(): array
    {
        $decoded = json_decode((string) File::get($this->routesPath()), true);

        return $decoded['routes'] ?? [];
    }

    /**
     * 활성 routes.json 에서 시드 마커가 붙은 라우트만 반환합니다.
     *
     * @return array<int, array<string, mixed>> 시드 라우트 목록
     */
    private function seedRoutes(): array
    {
        return array_values(array_filter(
            $this->allRoutes(),
            fn ($route) => is_array($route) && ($route['_marker'] ?? null) === 'playwright-seed-layout'
        ));
    }
}
