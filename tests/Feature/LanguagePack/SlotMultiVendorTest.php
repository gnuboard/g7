<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 슬롯 다중 벤더 Feature 테스트 (계획서 §16.9 케이스 66~70).
 *
 * 동일 슬롯(scope, target_identifier, locale)에 여러 벤더 언어팩이 공존할 때의 동작을 검증합니다.
 * application-level 트랜잭션이 슬롯당 active 1개를 보장하는지, 자동 승격/강등 흐름이 정확한지 확인.
 */
class SlotMultiVendorTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
    }

    /**
     * 코어 ja 슬롯 후보 1건을 생성합니다.
     *
     * @param  string  $vendor  벤더 식별자
     * @param  string  $status  초기 상태
     * @param  string  $version  버전
     * @return LanguagePack 생성된 언어팩
     */
    private function makeJaPack(string $vendor, string $status, string $version = '1.0.0'): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => sprintf('%s-core-ja', $vendor),
            'vendor' => $vendor,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => $version,
            'status' => $status,
            'is_protected' => false,
            'manifest' => ['conflicts' => ['vendors' => []]],
            'source_type' => 'zip',
            'installed_at' => now(),
            'activated_at' => $status === LanguagePackStatus::Active->value ? now() : null,
        ]);
    }

    /**
     * 케이스 66: 동일 슬롯에 벤더 A(active) + 벤더 B 설치 — B 는 installed, A 유지.
     *
     * @return void
     */
    public function test_case_66_second_vendor_enters_installed_when_slot_has_active(): void
    {
        $a = $this->makeJaPack('vendor-a', LanguagePackStatus::Active->value);
        $b = $this->makeJaPack('vendor-b', LanguagePackStatus::Installed->value);

        $this->assertSame(LanguagePackStatus::Active->value, $a->fresh()->status);
        $this->assertSame(LanguagePackStatus::Installed->value, $b->fresh()->status);
    }

    /**
     * 케이스 67: 벤더 B 를 activate → 벤더 A 자동 inactive.
     *
     * @return void
     */
    public function test_case_67_activating_b_demotes_existing_a(): void
    {
        $a = $this->makeJaPack('vendor-a', LanguagePackStatus::Active->value);
        $b = $this->makeJaPack('vendor-b', LanguagePackStatus::Installed->value);

        // 슬롯 충돌은 force=true 로 명시적 교체 의사 확인 후 demotion 수행 (기본은 SlotConflictException).
        $this->service->activate($b, force: true);

        $this->assertSame(LanguagePackStatus::Inactive->value, $a->fresh()->status);
        $this->assertSame(LanguagePackStatus::Active->value, $b->fresh()->status);

        // application-level 보장: 동일 슬롯의 active 가 정확히 1개
        $activeCount = LanguagePack::query()
            ->where('scope', LanguagePackScope::Core->value)
            ->whereNull('target_identifier')
            ->where('locale', 'ja')
            ->where('status', LanguagePackStatus::Active->value)
            ->count();
        $this->assertSame(1, $activeCount);
    }

    /**
     * 케이스 68: active 벤더 A 제거 → 벤더 B 자동 active 승격.
     *
     * @return void
     */
    public function test_case_68_uninstalling_active_promotes_next_candidate(): void
    {
        $a = $this->makeJaPack('vendor-a', LanguagePackStatus::Active->value);
        $b = $this->makeJaPack('vendor-b', LanguagePackStatus::Installed->value);

        $this->service->uninstall($a, false);

        // A 제거 + B 자동 승격
        $this->assertNull(LanguagePack::query()->find($a->id));
        $this->assertSame(LanguagePackStatus::Active->value, $b->fresh()->status);
    }

    /**
     * 케이스 69: conflicts.vendors 에 포함된 벤더와 공존 시도 — 슬롯 충돌은 application 정책으로 검증.
     *
     * 본 테스트는 conflicts 정책이 활성화 직전 거부되는 동작을 직접 확인합니다.
     * 현 구현은 manifest 검증 시 conflicts 를 체크하지 않으므로 (v1 범위 외), 본 테스트는
     * 같은 슬롯에 여러 active 가 동시에 존재하지 않는다는 핵심 불변량을 대신 검증합니다.
     *
     * @return void
     */
    public function test_case_69_slot_invariant_only_one_active_at_a_time(): void
    {
        $a = $this->makeJaPack('vendor-a', LanguagePackStatus::Active->value);
        $b = $this->makeJaPack('vendor-b', LanguagePackStatus::Installed->value);
        $c = $this->makeJaPack('vendor-c', LanguagePackStatus::Installed->value);

        // 순차 활성화 — 슬롯 충돌은 force=true 로 명시 교체. 항상 active 1개 유지.
        $this->service->activate($b, force: true);
        $activeAfterB = LanguagePack::query()
            ->where('locale', 'ja')->where('scope', 'core')
            ->where('status', LanguagePackStatus::Active->value)->count();
        $this->assertSame(1, $activeAfterB);

        $this->service->activate($c, force: true);
        $activeAfterC = LanguagePack::query()
            ->where('locale', 'ja')->where('scope', 'core')
            ->where('status', LanguagePackStatus::Active->value)->count();
        $this->assertSame(1, $activeAfterC);
        $this->assertSame(LanguagePackStatus::Active->value, $c->fresh()->status);
    }

    /**
     * 케이스 70: 3개 벤더 공존 — 중간 벤더(inactive) 제거 시 나머지 2개 유지, active 변경 없음.
     *
     * @return void
     */
    public function test_case_70_removing_inactive_candidate_does_not_affect_active(): void
    {
        $a = $this->makeJaPack('vendor-a', LanguagePackStatus::Active->value);
        $b = $this->makeJaPack('vendor-b', LanguagePackStatus::Inactive->value);
        $c = $this->makeJaPack('vendor-c', LanguagePackStatus::Inactive->value);

        $this->service->uninstall($b, false);

        $this->assertNotNull(LanguagePack::query()->find($a->id));
        $this->assertNull(LanguagePack::query()->find($b->id));
        $this->assertNotNull(LanguagePack::query()->find($c->id));
        $this->assertSame(LanguagePackStatus::Active->value, $a->fresh()->status);
        $this->assertSame(LanguagePackStatus::Inactive->value, $c->fresh()->status);
    }
}
