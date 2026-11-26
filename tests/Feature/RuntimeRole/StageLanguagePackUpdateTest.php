<?php

namespace Tests\Feature\RuntimeRole;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 검수 서버(stage)의 언어팩 업데이트 — 공유 DB 의 언어팩 상태를 바꾸지 않는다.
 *
 * 언어팩 업데이트는 상태를 `updating` 으로 바꾸고, 재설치가 `installed` 를 기록한 뒤 다시 켜는(activate) 순서를 거친다. 검수 서버가
 * 이 순서를 공유 DB 에 쓰면 그동안 운영 서버에서 그 로케일이 빠진다. 확장 업데이트(`*:update`)는 검수 서버에서 공유 상태 칸을
 * 쓰지 않는다 — 언어팩도 같은 원칙으로 진행 내내 이전 상태를 유지한다. 운영 서버는 종전과 같다.
 *
 * @scenario-manifest tests/scenarios/stage-language-pack-update.yaml
 */
class StageLanguagePackUpdateTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 시험 언어팩 식별자 ({namespace}-core-{locale}). 실제 로케일 슬롯과 겹치지 않는 로케일을 쓴다.
     */
    private const IDENTIFIER = 'stglp-core-xq';

    /**
     * 업데이트 동안 공유 DB 에 기록된 이 언어팩의 상태 값.
     *
     * @var array<int, string>
     */
    private array $writtenStatuses = [];

    /**
     * 시험 번들을 정리하고 롤백 뒤 언어팩 레지스트리 캐시를 비우도록 예약합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupFiles();

        // 트랜잭션 롤백 뒤에 실행된다 — 시험 중 구운 레지스트리 캐시에 롤백된 언어팩이 남지 않게 한다
        $this->beforeApplicationDestroyed(function (): void {
            app(LanguagePackRegistry::class)->invalidate();
        });
    }

    /**
     * 시험 번들·설치본을 정리합니다 (시험이 붙인 훅은 기반 TestCase 가 시험마다 비운다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupFiles();

        parent::tearDown();
    }

    /**
     * stage 에서 켜져 있는 언어팩을 업데이트해도 공유 DB 의 상태는 진행 내내 active 다 — updating·installed 를 거치지 않는다.
     *
     * @scenario case=stage_active_pack
     *
     * @effects stage_lang_pack_update_keeps_shared_status
     *
     * @return void 반환값 없음
     */
    public function test_stage_update_of_active_pack_never_leaves_active(): void
    {
        $pack = $this->installActivePack();
        $this->writeBundled('1.0.1');

        config(['core.runtime_role' => 'stage']);
        $this->recordStatusWrites();
        $statusAtUpdatedHook = null;
        HookManager::addAction('core.language_packs.updated', static function (LanguagePack $updated) use (&$statusAtUpdatedHook): void {
            $statusAtUpdatedHook = LanguagePack::query()->whereKey($updated->id)->value('status');
        });

        $updated = app(LanguagePackService::class)->performUpdate($pack->fresh(), force: true);

        $this->assertSame('1.0.1', $updated->version, '검수 서버도 새 판으로 올라가야 한다');
        $this->assertSame(LanguagePackStatus::Active->value, $updated->status);
        $this->assertSame(
            [],
            array_values(array_diff($this->writtenStatuses, [LanguagePackStatus::Active->value])),
            '검수 서버가 공유 DB 에 active 가 아닌 상태를 썼다 — 그동안 운영 서버에서 이 로케일이 빠진다'
        );
        $this->assertSame(
            LanguagePackStatus::Active->value,
            $statusAtUpdatedHook,
            '재설치 직후(업데이트 훅 시점)에도 공유 DB 상태는 active 여야 한다'
        );
    }

    /**
     * stage 에서 꺼져 있는(installed) 언어팩을 업데이트해도 updating 을 쓰지 않고 그대로 꺼진 채 남는다.
     *
     * @scenario case=stage_inactive_pack
     *
     * @effects stage_lang_pack_update_keeps_shared_status
     *
     * @return void 반환값 없음
     */
    public function test_stage_update_of_inactive_pack_never_writes_updating(): void
    {
        $this->writeBundled('1.0.0');
        $pack = app(LanguagePackService::class)->installFromBundled(self::IDENTIFIER, autoActivate: false);
        $this->assertSame(LanguagePackStatus::Installed->value, $pack->status, '전제 — 꺼진 팩');
        $this->writeBundled('1.0.1');

        config(['core.runtime_role' => 'stage']);
        $this->recordStatusWrites();

        $updated = app(LanguagePackService::class)->performUpdate($pack->fresh(), force: true);

        $this->assertSame('1.0.1', $updated->version);
        $this->assertSame(LanguagePackStatus::Installed->value, $updated->status, '꺼진 팩이 업데이트로 켜지면 안 된다');
        $this->assertNotContains(
            LanguagePackStatus::Updating->value,
            $this->writtenStatuses,
            '검수 서버가 공유 DB 에 updating 을 썼다'
        );
    }

    /**
     * live 의 업데이트는 종전대로 새 판으로 올라가고 켜져 있던 팩은 켜진 채 끝난다 (회귀 축).
     *
     * @scenario case=live_regression
     *
     * @effects live_lang_pack_update_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_live_update_still_ends_active(): void
    {
        $pack = $this->installActivePack();
        $this->writeBundled('1.0.1');

        config(['core.runtime_role' => 'live']);
        $this->recordStatusWrites();

        $updated = app(LanguagePackService::class)->performUpdate($pack->fresh(), force: true);

        $this->assertSame('1.0.1', $updated->version);
        $this->assertSame(LanguagePackStatus::Active->value, $updated->status);
        $this->assertContains(LanguagePackStatus::Updating->value, $this->writtenStatuses, 'live 는 동시성 가드용 updating 을 종전대로 쓴다');
    }

    /**
     * live 역할로 1.0.0 번들을 설치·활성화합니다.
     *
     * @return LanguagePack 활성 언어팩
     */
    private function installActivePack(): LanguagePack
    {
        config(['core.runtime_role' => 'live']);
        $this->writeBundled('1.0.0');
        $pack = app(LanguagePackService::class)->installFromBundled(self::IDENTIFIER, autoActivate: true);
        $this->assertSame(LanguagePackStatus::Active->value, $pack->status, '전제 — 켜진 팩');

        return $pack;
    }

    /**
     * 이 언어팩의 상태 칸에 기록되는 값을 모읍니다.
     *
     * @return void 반환값 없음
     */
    private function recordStatusWrites(): void
    {
        $this->writtenStatuses = [];
        LanguagePack::saving(function (LanguagePack $model): void {
            if ($model->identifier === self::IDENTIFIER && $model->isDirty('status')) {
                $this->writtenStatuses[] = (string) $model->status;
            }
        });
    }

    /**
     * 번들 매니페스트를 씁니다.
     *
     * @param  string  $version  번들 판
     * @return void 반환값 없음
     */
    private function writeBundled(string $version): void
    {
        $path = base_path('lang-packs/_bundled/'.self::IDENTIFIER);
        File::ensureDirectoryExists($path);
        File::put($path.DIRECTORY_SEPARATOR.'language-pack.json', json_encode([
            'identifier' => self::IDENTIFIER,
            'namespace' => 'stglp',
            'vendor' => 'stglp',
            'name' => ['ko' => self::IDENTIFIER, 'en' => self::IDENTIFIER],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => $version,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'xq',
            'locale_name' => 'XQ',
            'locale_native_name' => 'XQ',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 시험 번들과 설치본 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function cleanupFiles(): void
    {
        File::deleteDirectory(base_path('lang-packs/_bundled/'.self::IDENTIFIER));
        File::deleteDirectory(base_path('lang-packs/'.self::IDENTIFIER));
    }
}
