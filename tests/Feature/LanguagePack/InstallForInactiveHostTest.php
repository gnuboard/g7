<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Exceptions\LanguagePackOperationException;
use App\Extension\HookManager;
use App\Http\Controllers\Concerns\OrchestratesCascadeInstall;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 설치돼 있으나 꺼진 확장에도 그 확장의 언어팩을 꺼진 채로 설치하고, 확장을 켤 때 함께 켠다 (Q-G6, 결정 2026-10-08)
 *
 * 설치 직후 확장은 비활성이라, 설치 창에서 함께 고른 그 확장 자신의 추가 언어팩(예: Hello 모듈의 일본어 팩)이
 * 「대상 비활성」으로 늘 거절됐다(Q-G2 D-Q2-2). 이제 대상이 **설치돼 있고 비활성**이면 설치는 허용하고(미설치 대상은
 * 그대로 거절), 켜 달라는 요청으로 설치된 팩만 「대상 켜짐 대기」로 표시해 두었다가 그 확장을 켤 때 함께 켠다.
 * 켜 달라고 하지 않은 팩·사용자가 끈 팩은 확장을 켜도 그대로 둔다.
 *
 * 시나리오 매니페스트: tests/scenarios/language-pack-install-for-inactive-host.yaml
 */
class InstallForInactiveHostTest extends TestCase
{
    use DatabaseTransactions;

    private const TARGET = 'q6t-inactive_host';

    private const PACK = 'q6t-module-q6t-inactive_host-ja';

    private LanguagePackService $service;

    /** @var list<string> 테스트가 만든 디렉터리 (끝에 지운다) */
    private array $createdDirs = [];

    /**
     * 서비스를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
    }

    /**
     * 테스트가 만든 번들·설치본 디렉터리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->createdDirs = [];

        parent::tearDown();
    }

    /**
     * 대상 모듈 행을 만든다.
     *
     * @param  string  $status  모듈 상태
     * @param  string  $version  모듈 버전
     * @return void 반환값 없음
     */
    private function hostModule(string $status, string $version = '1.0.0'): void
    {
        DB::table('modules')->insert([
            'identifier' => self::TARGET,
            'name' => json_encode(['ko' => '대기 모듈', 'en' => 'Waiting Module']),
            'vendor' => 'q6t',
            'version' => $version,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 모듈 대상 ja 번들 언어팩 픽스처를 만든다.
     *
     * @param  array<string, mixed>  $requires  manifest requires 덮어쓰기
     * @return string 번들 식별자
     */
    private function bundledPack(array $requires = []): string
    {
        $path = base_path('lang-packs/_bundled/'.self::PACK);
        File::ensureDirectoryExists($path);
        File::put($path.DIRECTORY_SEPARATOR.'language-pack.json', json_encode([
            'identifier' => self::PACK,
            'namespace' => 'q6t',
            'vendor' => 'q6t',
            'name' => ['ko' => '대기 모듈 일본어', 'en' => 'Waiting Module Japanese', 'ja' => '待機モジュール日本語'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => self::TARGET,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
            'requires' => array_merge(['depends_on_core_locale' => false], $requires),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->createdDirs[] = $path;
        $this->createdDirs[] = base_path('lang-packs/'.self::PACK);

        return self::PACK;
    }

    /**
     * 같은 슬롯·대상의 언어팩 행을 직접 만든다.
     *
     * @param  string  $identifier  식별자
     * @param  string  $status  상태
     * @param  string|null  $reason  켜져 있지 않은 사유(deactivated_reason)
     * @return LanguagePack 생성된 행
     */
    private function packRow(string $identifier, string $status, ?string $reason = null): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'q6t',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => self::TARGET,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => $status,
            'deactivated_reason' => $reason,
            'is_protected' => false,
            'manifest' => [
                'identifier' => $identifier,
                'scope' => LanguagePackScope::Module->value,
                'target_identifier' => self::TARGET,
                'locale' => 'ja',
                'requires' => ['depends_on_core_locale' => false],
            ],
        ]);
    }

    /**
     * 켜 달라는 요청으로 설치하면 대상이 꺼져 있어도 거절하지 않고 꺼진 채 「대상 켜짐 대기」로 남긴다.
     *
     * @scenario case=inactive_host_install_with_activation
     *
     * @effects language_pack_installed_off_for_inactive_host
     *
     * @return void 반환값 없음
     */
    public function test_auto_activate_install_for_inactive_host_is_kept_off_and_marked_awaiting(): void
    {
        $this->hostModule('inactive');

        $pack = $this->service->installFromBundled($this->bundledPack(), autoActivate: true);

        $this->assertSame(LanguagePackStatus::Installed->value, $pack->status);
        $this->assertSame(LanguagePackDeactivationReason::AwaitingHost->value, $pack->fresh()->deactivated_reason);
        $this->assertDirectoryExists(base_path('lang-packs/'.self::PACK));
    }

    /**
     * 켜 달라고 하지 않은 설치(언어팩 화면의 개별 설치)는 꺼진 대상에도 설치되지만 대기 표시가 없다.
     *
     * @scenario case=inactive_host_install_without_activation
     *
     * @effects language_pack_installed_off_for_inactive_host
     *
     * @return void 반환값 없음
     */
    public function test_plain_install_for_inactive_host_is_installed_without_awaiting_mark(): void
    {
        $this->hostModule('inactive');

        $pack = $this->service->installFromBundled($this->bundledPack(), autoActivate: false);

        $this->assertSame(LanguagePackStatus::Installed->value, $pack->status);
        $this->assertNull($pack->fresh()->deactivated_reason);
    }

    /**
     * 설치되지 않은 대상은 종전처럼 거절한다.
     *
     * @scenario case=uninstalled_host_rejected
     *
     * @effects uninstalled_or_too_old_host_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_uninstalled_host_is_still_rejected(): void
    {
        try {
            $this->service->installFromBundled($this->bundledPack(), autoActivate: true);
            $this->fail('미설치 대상 언어팩 설치가 거절되지 않았다');
        } catch (LanguagePackOperationException $e) {
            $this->assertSame('language_packs.errors.target_not_installed', $e->errorKey);
        }
        $this->assertSame(0, LanguagePack::query()->where('identifier', self::PACK)->count());
    }

    /**
     * 꺼진 대상이라도 버전 제약을 못 맞추면 거절한다(비활성 허용이 버전 검사를 건너뛰게 하지 않는다).
     *
     * @scenario case=inactive_host_version_too_old_rejected
     *
     * @effects uninstalled_or_too_old_host_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_inactive_host_with_too_old_version_is_rejected(): void
    {
        $this->hostModule('inactive', '1.0.0');

        try {
            $this->service->installFromBundled($this->bundledPack(['target_version' => '>=2.0.0']), autoActivate: true);
            $this->fail('버전 미달 대상 언어팩 설치가 거절되지 않았다');
        } catch (LanguagePackOperationException $e) {
            $this->assertSame('language_packs.errors.target_version_too_old', $e->errorKey);
        }
    }

    /**
     * 대상이 꺼져 있는 동안 언어팩을 켜는 것은 종전처럼 거절한다.
     *
     * @scenario case=activate_while_host_inactive_rejected
     *
     * @effects activation_still_requires_active_host
     *
     * @return void 반환값 없음
     */
    public function test_activating_pack_while_host_inactive_is_still_rejected(): void
    {
        $this->hostModule('inactive');
        $pack = $this->packRow(self::PACK, LanguagePackStatus::Installed->value, LanguagePackDeactivationReason::AwaitingHost->value);

        try {
            $this->service->activate($pack);
            $this->fail('꺼진 대상의 언어팩이 켜졌다');
        } catch (LanguagePackOperationException $e) {
            $this->assertSame('language_packs.errors.target_inactive', $e->errorKey);
        }
    }

    /**
     * 확장을 켜면(after_activate 훅) 대기 표시된 언어팩이 함께 켜지고 표시가 지워진다.
     *
     * @scenario case=host_activation_turns_on_awaiting
     *
     * @effects awaiting_language_pack_activated_with_host
     *
     * @return void 반환값 없음
     */
    public function test_host_activation_hook_turns_on_awaiting_pack(): void
    {
        $this->hostModule('inactive');
        $pack = $this->service->installFromBundled($this->bundledPack(), autoActivate: true);

        DB::table('modules')->where('identifier', self::TARGET)->update(['status' => 'active']);
        HookManager::doAction('core.modules.after_activate', self::TARGET, []);

        $fresh = $pack->fresh();
        $this->assertSame(LanguagePackStatus::Active->value, $fresh->status);
        $this->assertNull($fresh->deactivated_reason);
        $this->assertNotNull($fresh->activated_at);
    }

    /**
     * 켜 달라고 하지 않은 팩(installed)·사용자가 끈 팩(inactive)은 확장을 켜도 그대로 둔다.
     *
     * @scenario case=host_activation_leaves_user_choices
     *
     * @effects user_choices_untouched_on_host_activation
     *
     * @return void 반환값 없음
     */
    public function test_host_activation_leaves_plain_installed_and_user_deactivated_packs(): void
    {
        $this->hostModule('active');
        $plain = $this->packRow('q6t-module-q6t-inactive_host-ja', LanguagePackStatus::Installed->value);
        $userOff = $this->packRow('q6u-module-q6t-inactive_host-ja', LanguagePackStatus::Inactive->value);

        $activated = $this->app->make(LanguagePackBundledRegistrar::class)
            ->activateAwaitingForExtension(LanguagePackScope::Module->value, self::TARGET);

        $this->assertSame([], $activated);
        $this->assertSame(LanguagePackStatus::Installed->value, $plain->fresh()->status);
        $this->assertSame(LanguagePackStatus::Inactive->value, $userOff->fresh()->status);
    }

    /**
     * 같은 언어 자리를 이미 다른 팩이 쓰고 있으면 대기 팩은 켜지 않는다(사용자가 고른 팩을 밀어내지 않는다).
     *
     * @scenario case=host_activation_slot_occupied
     *
     * @effects user_choices_untouched_on_host_activation
     *
     * @return void 반환값 없음
     */
    public function test_host_activation_does_not_displace_active_pack_in_slot(): void
    {
        $this->hostModule('active');
        $occupant = $this->packRow('q6u-module-q6t-inactive_host-ja', LanguagePackStatus::Active->value);
        $awaiting = $this->packRow(self::PACK, LanguagePackStatus::Installed->value, LanguagePackDeactivationReason::AwaitingHost->value);

        $activated = $this->app->make(LanguagePackBundledRegistrar::class)
            ->activateAwaitingForExtension(LanguagePackScope::Module->value, self::TARGET);

        $this->assertSame([], $activated);
        $this->assertSame(LanguagePackStatus::Active->value, $occupant->fresh()->status);
        $this->assertSame(LanguagePackStatus::Installed->value, $awaiting->fresh()->status);
    }

    /**
     * 언어팩 화면: 꺼진 대상의 미설치 번들은 설치 차단 사유가 없고(설치 가능), 안내 사유만 싣는다.
     *
     * @scenario case=bundled_list_inactive_host_installable
     *
     * @effects language_pack_installed_off_for_inactive_host
     *
     * @return void 반환값 없음
     */
    public function test_uninstalled_bundled_row_for_inactive_host_is_installable_with_notice(): void
    {
        $this->hostModule('inactive');
        $this->bundledPack();

        $this->assertNull($this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => self::TARGET,
            'locale' => 'ja',
            'requires' => ['depends_on_core_locale' => false],
        ]));

        $row = $this->service->getUninstalledBundledPacks(['target_identifier' => self::TARGET])
            ->firstWhere('identifier', self::PACK);

        $this->assertNotNull($row);
        $this->assertNull($row->getAttribute('install_blocked_reason'));
        $this->assertSame('target_inactive', $row->getAttribute('install_notice'));
    }

    /**
     * 설치 창 경로: 함께 고른 그 확장 자신의 언어팩은 실패로 돌려주지 않고 「켜짐 대기」 목록으로 돌려준다.
     *
     * @scenario case=install_modal_cascade_reports_awaiting
     *
     * @effects install_modal_reports_awaiting_instead_of_failure
     *
     * @return void 반환값 없음
     */
    public function test_cascade_install_reports_awaiting_packs_instead_of_failures(): void
    {
        $this->hostModule('inactive');
        $identifier = $this->bundledPack();

        $orchestrator = new class
        {
            use OrchestratesCascadeInstall;

            /**
             * 설치 창 cascade 2단계(함께 고른 번들 언어팩 설치)를 그대로 부른다.
             *
             * @param  array<int, string>  $ids  번들 언어팩 식별자 목록
             * @param  array<int, string>|null  $awaiting  (출력) 꺼진 채 설치된 언어팩 식별자
             * @return array<int, array{identifier: string, reason: string}> 실패 항목 목록
             */
            public function run(array $ids, ?array &$awaiting = null): array
            {
                return $this->installSelectedLanguagePacks($ids, $awaiting);
            }
        };

        $awaiting = null;
        $failures = $orchestrator->run([$identifier], $awaiting);

        $this->assertSame([], $failures);
        $this->assertSame([$identifier], $awaiting);
    }

    /**
     * 플러그인·템플릿을 켜도 대기 표시된 언어팩이 함께 켜진다 — 템플릿 훅은 식별자 대신 정보 배열을 넘긴다.
     *
     * 모듈 훅만 검증하면 플러그인 훅 등록 누락이나 템플릿 훅의 인자 모양(TemplateManager::activateTemplate 가
     * `getTemplateInfo()` 배열 1개를 넘김) 해석 오류가 드러나지 않는다.
     *
     * @scenario case=host_activation_plugin_and_template_hooks
     *
     * @effects awaiting_language_pack_activated_with_host
     *
     * @return void 반환값 없음
     */
    public function test_plugin_and_template_activation_hooks_turn_on_awaiting_packs(): void
    {
        $now = now();
        DB::table('plugins')->insert([
            'identifier' => self::TARGET,
            'vendor' => 'q6t',
            'name' => json_encode(['ko' => '대기 플러그인', 'en' => 'Waiting Plugin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('templates')->insert([
            'identifier' => self::TARGET,
            'vendor' => 'q6t',
            'name' => json_encode(['ko' => '대기 템플릿', 'en' => 'Waiting Template']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $awaitingPack = fn (string $scope): LanguagePack => LanguagePack::query()->create([
            'identifier' => "q6t-{$scope}-q6t-inactive_host-ja",
            'vendor' => 'q6t',
            'scope' => $scope,
            'target_identifier' => self::TARGET,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Installed->value,
            'deactivated_reason' => LanguagePackDeactivationReason::AwaitingHost->value,
            'is_protected' => false,
            'manifest' => [
                'identifier' => "q6t-{$scope}-q6t-inactive_host-ja",
                'scope' => $scope,
                'target_identifier' => self::TARGET,
                'locale' => 'ja',
                'requires' => ['depends_on_core_locale' => false],
            ],
        ]);
        $pluginPack = $awaitingPack(LanguagePackScope::Plugin->value);
        $templatePack = $awaitingPack(LanguagePackScope::Template->value);

        HookManager::doAction('core.plugins.after_activate', self::TARGET, []);
        HookManager::doAction('core.templates.after_activate', ['identifier' => self::TARGET, 'type' => 'admin']);

        foreach (['plugin' => $pluginPack, 'template' => $templatePack] as $scope => $pack) {
            $fresh = $pack->fresh();
            $this->assertSame(LanguagePackStatus::Active->value, $fresh->status, "{$scope} 대기 팩이 켜져야 합니다.");
            $this->assertNull($fresh->deactivated_reason, "{$scope} 대기 표시가 지워져야 합니다.");
        }
    }
}
