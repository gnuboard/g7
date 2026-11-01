<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\IntegrationNotices;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Concerns\RespondsWithIntegrationNotices;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 선택 연동(`integrations`) 생명주기 안내 — 막지 않고 알리기만 한다 (#822 P10·D10)
 *
 * - 설치·활성화·업데이트: 연동 대상 중 미설치·비활성·버전 미달을 `integration_notices` 와 완료 문구 한 줄로 알린다.
 * - 비활성화·제거: 이 확장을 연동으로 선언한 활성 확장을 `integrated_by` 로 알린다.
 * - 어느 쪽도 HTTP 상태·종료 코드를 바꾸지 않는다.
 */
class IntegrationLifecycleNoticesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 정리할 임시 파일 */
    private array $tmpFiles = [];

    /**
     * 임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    /**
     * 미설치·비활성·버전 미달만 안내하고 충족된 연동은 빼며, 버전 무관 선언은 설치·활성만 보면 충족이다.
     *
     * @scenario case=notice_states
     *
     * @effects unmet_integrations_become_notices, met_integrations_yield_no_notice
     *
     * @return void 반환값 없음
     */
    public function test_notice_states_follow_install_active_and_version(): void
    {
        app()->setLocale('ko');
        Module::factory()->create(['identifier' => 'acme-board', 'version' => '1.0.0', 'status' => ExtensionStatus::Active->value]);
        Module::factory()->create(['identifier' => 'acme-page', 'version' => '2.0.0', 'status' => ExtensionStatus::Inactive->value]);
        Plugin::factory()->create(['identifier' => 'acme-postcode', 'version' => '1.0.0', 'status' => ExtensionStatus::Active->value]);

        $notices = IntegrationNotices::fromIntegrations([
            'modules' => ['acme-board' => '>=1.2.1', 'acme-page' => '>=1.0.0', 'acme-shop' => '>=1.3.0'],
            'plugins' => ['acme-postcode'],
        ]);

        $byId = collect($notices)->keyBy('identifier');
        $this->assertSame(['acme-board', 'acme-page', 'acme-shop'], $byId->keys()->all(), '충족된 acme-postcode(버전 무관·활성)는 빠져야 합니다');
        $this->assertSame(IntegrationNotices::STATE_VERSION_MISMATCH, $byId['acme-board']['state']);
        $this->assertSame('>=1.2.1', $byId['acme-board']['required_version']);
        $this->assertSame('1.0.0', $byId['acme-board']['installed_version']);
        $this->assertSame(IntegrationNotices::STATE_INACTIVE, $byId['acme-page']['state']);
        $this->assertSame(IntegrationNotices::STATE_NOT_INSTALLED, $byId['acme-shop']['state']);
        $this->assertNull($byId['acme-shop']['installed_version']);
        $this->assertSame('module', $byId['acme-shop']['type']);
        $this->assertStringContainsString('>=1.2.1', $byId['acme-board']['message']);

        $message = IntegrationNotices::appendToMessage('설치했습니다.', $notices);
        $this->assertStringStartsWith('설치했습니다. ', $message);
        $this->assertStringContainsString('acme-shop', $message);
        $this->assertSame('설치했습니다.', IntegrationNotices::appendToMessage('설치했습니다.', []));
    }

    /**
     * 확장을 연동으로 선언한 활성 확장(모듈·플러그인·템플릿)을 역으로 찾고, 꺼진 확장·자기 자신은 뺀다.
     *
     * @scenario case=integrated_by
     *
     * @effects integrated_by_lists_active_declarers
     *
     * @return void 반환값 없음
     */
    public function test_integrated_by_lists_active_extensions_declaring_the_target(): void
    {
        Module::factory()->create(['identifier' => 'acme-board', 'status' => ExtensionStatus::Active->value]);
        Module::factory()->create(['identifier' => 'acme-shop', 'name' => ['ko' => '쇼핑몰', 'en' => 'Shop'], 'status' => ExtensionStatus::Active->value]);
        Module::factory()->create(['identifier' => 'acme-off', 'status' => ExtensionStatus::Inactive->value]);
        Plugin::factory()->create(['identifier' => 'acme-sms', 'status' => ExtensionStatus::Active->value]);
        Template::factory()->create(['identifier' => 'acme-basic', 'type' => 'user', 'status' => ExtensionStatus::Active->value]);

        $declares = ['modules' => ['acme-board'], 'plugins' => [], 'templates' => []];
        $nothing = ['modules' => [], 'plugins' => [], 'templates' => []];
        $moduleInstances = ['acme-board' => $declares, 'acme-shop' => $declares, 'acme-off' => $declares];

        $this->mock(ModuleManagerInterface::class, function ($mock) use ($moduleInstances): void {
            $mock->shouldReceive('getModule')->andReturnUsing(function (string $id) use ($moduleInstances) {
                $instance = Mockery::mock(ModuleInterface::class);
                $instance->shouldReceive('getIntegrations')->andReturn($moduleInstances[$id] ?? ['modules' => [], 'plugins' => [], 'templates' => []]);

                return $instance;
            });
        });
        $this->mock(PluginManagerInterface::class, function ($mock) use ($nothing): void {
            $mock->shouldReceive('getPlugin')->andReturnUsing(function () use ($nothing) {
                $instance = Mockery::mock(PluginInterface::class);
                $instance->shouldReceive('getIntegrations')->andReturn($nothing);

                return $instance;
            });
        });
        $this->mock(TemplateManagerInterface::class, function ($mock): void {
            $mock->shouldReceive('ensureLoaded');
            $mock->shouldReceive('getTemplate')->andReturn(['integrations' => ['modules' => ['acme-board' => '>=1.2.1']]]);
        });

        app()->setLocale('ko');
        $integratedBy = IntegrationNotices::integratedBy('module', 'acme-board');

        $this->assertSame(
            [
                ['type' => 'module', 'identifier' => 'acme-shop', 'name' => '쇼핑몰'],
                ['type' => 'template', 'identifier' => 'acme-basic', 'name' => Template::where('identifier', 'acme-basic')->first()->getLocalizedName()],
            ],
            collect($integratedBy)->filter(fn (array $row) => str_starts_with($row['identifier'], 'acme-'))->values()->all(),
            '꺼진 acme-off 와 자기 자신(acme-board)은 빠져야 합니다',
        );
        $this->assertSame([], IntegrationNotices::integratedBy('widget', 'acme-board'));
        $this->assertTrue(app(TemplateRepositoryInterface::class)->findActiveByIntegration('module', 'acme-board')->contains('identifier', 'acme-basic'));
        $this->assertFalse(app(ModuleRepositoryInterface::class)->findActiveByIntegration('plugin', 'acme-board')->contains('identifier', 'acme-shop'), '유형이 다르면 찾지 않습니다');
    }

    /**
     * 응답에 안내를 실어도 HTTP 상태·기존 키는 그대로이고, 안내가 있으면 완료 문구에 한 줄이 붙는다.
     *
     * @scenario case=api_response
     *
     * @effects lifecycle_response_carries_integration_notices_without_blocking
     *
     * @return void 반환값 없음
     */
    public function test_response_carries_notices_and_integrated_by_without_changing_status(): void
    {
        Module::factory()->create(['identifier' => 'acme-page', 'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value]);
        $harness = new class
        {
            use RespondsWithIntegrationNotices {
                withIntegrationNotices as public;
                withIntegratedBy as public;
            }
        };

        $notices = IntegrationNotices::fromIntegrations(['modules' => ['acme-page']]);
        $response = $harness->withIntegrationNotices(ResponseHelper::success('common.success', ['template' => ['identifier' => 'acme-basic']], 201), $notices);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['identifier' => 'acme-basic'], $payload['data']['template']);
        $this->assertSame('acme-page', $payload['data']['integration_notices'][0]['identifier']);
        $this->assertSame('inactive', $payload['data']['integration_notices'][0]['state']);
        $this->assertStringContainsString(IntegrationNotices::summary($notices), $payload['message']);

        $quiet = $harness->withIntegrationNotices(ResponseHelper::success('common.success', []), [])->getData(true);
        $this->assertSame([], $quiet['data']['integration_notices']);
        $this->assertSame(__('common.success'), $quiet['message']);

        $deactivated = $harness->withIntegratedBy(ResponseHelper::success('common.success', ['preserved_backups' => []]), 'module', 'acme-nobody');
        $this->assertSame(200, $deactivated->getStatusCode());
        $this->assertSame([], $deactivated->getData(true)['data']['integrated_by']);
    }

    /**
     * 업데이트 뒤 새 판 manifest 의 연동을 검사해 안내하고, 읽을 수 없는 manifest 는 조용히 빈 목록이다 (D10).
     *
     * @scenario case=update_notices
     *
     * @effects update_reports_integration_notices
     *
     * @return void 반환값 없음
     */
    public function test_update_reports_integration_notices_from_new_manifest(): void
    {
        Module::factory()->create(['identifier' => 'acme-board', 'version' => '1.0.0', 'status' => ExtensionStatus::Active->value]);

        $ok = $this->tmp((string) json_encode(['identifier' => 'acme-basic', 'integrations' => ['modules' => ['acme-board' => '>=1.2.1']]]));
        $broken = $this->tmp('{not json');

        $notices = DependencyVersionWarnings::integrationsAfterUpdate('template', 'acme-basic', $ok);
        $this->assertCount(1, $notices);
        $this->assertSame('version_mismatch', $notices[0]['state']);
        $this->assertSame([], DependencyVersionWarnings::integrationsAfterUpdate('template', 'acme-basic', $broken));
        $this->assertSame([], IntegrationNotices::forManifestFile($ok.'.absent'));
    }

    /**
     * Artisan 은 안내를 표로 출력하고 종료 코드는 그대로다 — 안내가 없으면 아무것도 출력하지 않는다.
     *
     * @scenario case=artisan_table
     *
     * @effects artisan_prints_integration_table_without_exit_code_change
     *
     * @return void 반환값 없음
     */
    public function test_console_renders_notice_table(): void
    {
        app()->setLocale('ko');
        $command = new class extends Command
        {
            use ReportsIntegrationNotices {
                renderIntegrationNotices as public;
            }

            protected $signature = 'acme:integration-notice-probe';
        };
        $command->setLaravel($this->app);
        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $command->renderIntegrationNotices([]);
        $this->assertSame('', $buffer->fetch());

        $command->renderIntegrationNotices([[
            'type' => 'module', 'identifier' => 'acme-board', 'name' => '게시판', 'state' => 'version_mismatch',
            'required_version' => '>=1.2.1', 'installed_version' => '1.0.0', 'message' => '',
        ]]);
        $output = $buffer->fetch();

        $this->assertStringContainsString(__('extensions.integrations.console.notices_title'), $output);
        $this->assertStringContainsString('게시판 (acme-board)', $output);
        $this->assertStringContainsString(__('extensions.integrations.states.version_mismatch'), $output);
        $this->assertStringContainsString('>=1.2.1', $output);
    }

    /**
     * 임시 manifest 파일을 만듭니다.
     *
     * @param  string  $content  파일 내용
     * @return string 파일 경로
     */
    private function tmp(string $content): string
    {
        $path = storage_path('framework/testing/integration_manifest_'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
