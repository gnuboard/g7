<?php

namespace Tests\Feature\Upgrades;

use App\Contracts\Extension\CacheInterface;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\UpgradeContext;
use App\Models\LanguagePack;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Upgrades\Data\V7_1_1\Migrations\RecoverFalselyAutoDeactivatedExtensions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 7.1.1 업그레이드 스텝이 #145·#137 로 잘못 꺼진 확장과 그 언어팩을 한 번 다시 켜는지 검증합니다 (일회성 복구 결정).
 *
 * 7.0.0~7.0.10 부모가 옛 APP_VERSION 을 물려줘 config 캐시에 7.0.0 이 구워졌고, 업데이트 뒤 첫 프로세스가 `>=7.1.0` 확장을
 * `incompatible_core` 로 껐다. 기록된 요구 버전을 7.1.1 이 충족하는 행만 켜고, 진짜 비호환·수동 비활성은 그대로 둔다.
 * 매니저는 대역으로 바꿔 「활성화하면 DB 상태가 active 가 된다」 만 흉내 낸다 — 관심사는 대상 선별과 언어팩 3단 규칙이다.
 */
class Upgrade_7_1_1_RecoverAutoDeactivatedTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $offAt;

    /** @var array<string, array<int, string>> 매니저별 활성화 호출 기록 */
    private array $calls = ['modules' => [], 'plugins' => [], 'templates' => []];

    /**
     * 데이터 스텝을 불러오고 세 매니저를 대역으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.1/migrations/01_RecoverFalselyAutoDeactivatedExtensions.php');
        $this->offAt = Carbon::parse('2026-10-10 03:17:06');

        foreach (['modules' => [ModuleManager::class, 'loadModules', 'activateModule', Module::class],
            'plugins' => [PluginManager::class, 'loadPlugins', 'activatePlugin', Plugin::class],
            'templates' => [TemplateManager::class, 'loadTemplates', 'activateTemplate', Template::class]] as $table => [$class, $load, $activate, $model]) {
            /** @var MockInterface $manager */
            $manager = Mockery::mock($class);
            $manager->shouldReceive($load);
            $manager->shouldReceive('ensureLoaded');
            $manager->shouldReceive($activate)->andReturnUsing(function (string $identifier) use ($table, $model) {
                $this->calls[$table][] = $identifier;
                $model::where('identifier', $identifier)->update(['status' => 'active', 'deactivated_reason' => null, 'deactivated_at' => null, 'incompatible_required_version' => null]);

                return ['success' => true];
            });
            $this->app->instance($class, $manager);
        }
    }

    /**
     * 자동 비활성화 상태의 확장 기록을 만듭니다.
     *
     * @param  string  $model  모델 클래스
     * @param  string  $identifier  식별자
     * @param  string|null  $reason  비활성 사유
     * @param  string|null  $required  기록된 요구 버전
     * @param  array<string, mixed>  $extra  추가 칸
     * @return void 반환값 없음
     */
    private function offExtension(string $model, string $identifier, ?string $reason = 'incompatible_core', ?string $required = '>=7.1.0', array $extra = []): void
    {
        $record = $model::factory()->create(['identifier' => $identifier, 'status' => 'inactive'] + $extra);
        DB::table($record->getTable())->where('id', $record->id)->update([
            'deactivated_reason' => $reason,
            'incompatible_required_version' => $required,
            'deactivated_at' => $this->offAt,
        ]);
    }

    /**
     * 비활성 언어팩을 만듭니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $target  대상 식별자
     * @param  string  $locale  로케일
     * @param  string|null  $reason  비활성 사유
     * @param  Carbon|null  $updatedAt  마지막 변경 시각
     * @return LanguagePack 만든 언어팩
     */
    private function offPack(string $scope, string $target, string $locale, ?string $reason, ?Carbon $updatedAt = null): LanguagePack
    {
        $pack = LanguagePack::create([
            'identifier' => $target.'-'.$locale,
            'vendor' => 'test',
            'scope' => $scope,
            'target_identifier' => $target,
            'locale' => $locale,
            'locale_name' => strtoupper($locale),
            'locale_native_name' => strtoupper($locale),
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => 'inactive',
            'deactivated_reason' => $reason,
            'is_protected' => true,
            'manifest' => [],
            'source_type' => LanguagePackSourceType::Bundled->value,
        ]);
        DB::table('language_packs')->where('id', $pack->id)->update(['updated_at' => $updatedAt ?? $this->offAt]);

        return $pack;
    }

    /**
     * 데이터 스텝을 7.1.0 → 7.1.1 로 한 번 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new RecoverFalselyAutoDeactivatedExtensions)->run(new UpgradeContext('7.1.0', '7.1.1', '7.1.1'));
    }

    /**
     * 기록된 요구 버전을 충족하는 행만 모듈 → 플러그인 → 템플릿으로 켜고, 진짜 비호환·수동 비활성은 그대로 둔다. 다시 돌려도 바꿀 것이 없다.
     *
     * @scenario case=select_targets
     *
     * @effects recovers_only_satisfiable_auto_deactivated_extensions, recovery_step_is_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recovers_only_satisfiable_rows_and_is_idempotent(): void
    {
        $this->offExtension(Module::class, 'test-board');
        $this->offExtension(Module::class, 'test-future', 'incompatible_core', '>=9.0.0');
        $this->offExtension(Module::class, 'test-manual', 'manual', null);
        $this->offExtension(Plugin::class, 'test-postcode');
        $this->offExtension(Template::class, 'test-admin', 'incompatible_core', '>=7.1.0', ['type' => 'admin']);

        $this->runStep();

        $this->assertSame(['test-board'], $this->calls['modules']);
        $this->assertSame(['test-postcode'], $this->calls['plugins']);
        $this->assertSame(['test-admin'], $this->calls['templates']);
        $this->assertSame('inactive', Module::where('identifier', 'test-future')->value('status'));
        $this->assertSame('inactive', Module::where('identifier', 'test-manual')->value('status'));

        $this->calls = ['modules' => [], 'plugins' => [], 'templates' => []];
        $this->runStep();
        $this->assertSame(['modules' => [], 'plugins' => [], 'templates' => []], $this->calls);
    }

    /**
     * 언어팩 3단 규칙: host_deactivated 표시 → 7.1.0 캐시 목록 → 꺼진 시각 10분 창의 사유 없는 팩.
     *
     * @scenario case=language_pack_rules
     *
     * @effects recovers_host_language_packs_by_three_rules
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_language_packs_follow_three_rules(): void
    {
        // (1) host_deactivated 표시가 있으면 그것만 켠다
        $this->offExtension(Plugin::class, 'test-marked');
        $marked = $this->offPack('plugin', 'test-marked', 'ko', LanguagePackDeactivationReason::HostDeactivated->value);
        $markedOther = $this->offPack('plugin', 'test-marked', 'en', null);

        // (2) 7.1.0 캐시 목록
        $this->offExtension(Plugin::class, 'test-stash');
        $stashed = $this->offPack('plugin', 'test-stash', 'ko', null, $this->offAt->copy()->addHour());
        app(CacheInterface::class)->put('language-pack:reactivation:plugin:test-stash', [$stashed->id], 3600);

        // (3) 시각 창
        $this->offExtension(Module::class, 'test-window');
        $inWindow = $this->offPack('module', 'test-window', 'ko', null, $this->offAt->copy()->addMinutes(2));
        $outWindow = $this->offPack('module', 'test-window', 'en', null, $this->offAt->copy()->addMinutes(30));

        $this->runStep();

        $this->assertSame('active', $marked->refresh()->status);
        $this->assertSame('inactive', $markedOther->refresh()->status, '표시가 있으면 표시된 팩만 켠다');
        $this->assertSame('active', $stashed->refresh()->status);
        $this->assertSame('active', $inWindow->refresh()->status);
        $this->assertSame('inactive', $outWindow->refresh()->status, '창 밖에서 꺼진 팩은 운영자가 끈 것으로 본다');
    }

    /**
     * 매니저가 활성화를 거부하면 경고만 남기고 나머지를 계속한다.
     *
     * @scenario case=activation_failure
     *
     * @effects recovery_failure_is_logged_and_continues
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_failure_is_logged_and_continues(): void
    {
        $this->offExtension(Module::class, 'test-broken');
        $this->offExtension(Module::class, 'test-fine');

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('loadModules');
        $manager->shouldReceive('activateModule')->with('test-broken', false)->andReturn(['success' => false, 'message' => 'missing dependency']);
        $manager->shouldReceive('activateModule')->with('test-fine', false)->andReturnUsing(function () {
            Module::where('identifier', 'test-fine')->update(['status' => 'active', 'deactivated_reason' => null]);

            return ['success' => true];
        });
        $this->app->instance(ModuleManager::class, $manager);

        $this->runStep();

        $this->assertSame('inactive', Module::where('identifier', 'test-broken')->value('status'));
        $this->assertSame('active', Module::where('identifier', 'test-fine')->value('status'));
    }
}
