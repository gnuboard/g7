<?php

namespace Tests\Feature\Custom;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `php artisan custom:check` 테스트 (#792 WP-K2·WP-K3)
 *
 * 등록된 custom 라우트·거부된 라우트·개발자 패키지·코어 충돌을 보여 주고, 거부·충돌이 있으면 실패 코드로 끝나는지 잠근다.
 */
class CustomCheckCommandTest extends TestCase
{
    private string $fakeBase;

    private string $originalBasePath;

    /**
     * 격리 base 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->originalBasePath = base_path();
        $this->fakeBase = storage_path('framework/testing/custom_check_'.uniqid());
        File::ensureDirectoryExists($this->fakeBase);
        app()->setBasePath($this->fakeBase);
    }

    /**
     * base 를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->fakeBase);

        parent::tearDown();
    }

    /**
     * custom 자리가 비어 있으면 성공으로 끝나고 「routes/custom.php 가 없습니다」를 보여 줍니다.
     *
     * @scenario case=check_command_clean
     *
     * @effects check_reports_status
     *
     * @return void 반환값 없음
     */
    public function test_clean_state_succeeds(): void
    {
        $this->artisan('custom:check')
            ->expectsOutputToContain('routes/custom.php 가 없습니다')
            ->expectsOutputToContain('코어 패키지와 충돌 없음')
            ->assertExitCode(0);
    }

    /**
     * 거부 라우트와 패키지 충돌이 있으면 둘 다 보여 주고 실패 코드로 끝납니다.
     *
     * @scenario case=check_command_problems
     *
     * @effects check_reports_status, check_fails_on_rejected_or_conflict
     *
     * @return void 반환값 없음
     */
    public function test_rejected_routes_and_conflicts_fail(): void
    {
        File::ensureDirectoryExists($this->fakeBase.'/routes');
        File::put($this->fakeBase.'/routes/custom.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/board/{slug}', fn () => 'x');\nRoute::get('/api/x', fn () => 'x');\n");
        File::put($this->fakeBase.'/composer.lock', (string) json_encode(['packages' => [['name' => 'symfony/console', 'version' => 'v7.1.0']]]));
        File::ensureDirectoryExists($this->fakeBase.'/custom');
        File::put($this->fakeBase.'/custom/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'acme/cli-kit', 'version' => '2.0.0', 'require' => ['symfony/console' => '^6.4']],
        ]]));

        $this->artisan('custom:check')
            ->expectsOutputToContain('/board/{slug}')
            ->expectsOutputToContain('거부 — 코어 예약 주소(reserved_prefix:api)')
            ->expectsOutputToContain('acme/cli-kit')
            ->assertExitCode(1);
    }
}
