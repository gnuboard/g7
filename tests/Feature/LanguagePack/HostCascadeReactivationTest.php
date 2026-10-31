<?php

namespace Tests\Feature\LanguagePack;

use App\Contracts\Extension\CacheInterface;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 호스트 확장을 끌 때 함께 꺼진 언어팩을 DB 에 표시하고, 다시 켤 목록을 DB 에서 읽는지 검증합니다 (#145 F7, D5).
 *
 * 예전에는 「다시 켤 목록」 이 캐시에만 있어 `cache:clear` 로 사라지고 한 번 읽으면 지워졌다. 그래서 확장을 다시 켜도
 * 언어팩이 꺼진 채 남는 사이트가 생겼다(2026-10-11 실측: 7개 플러그인의 ko/en/ja 19개). 이제 연쇄로 끈 팩은
 * `deactivated_reason=host_deactivated` 로 남고, 7.1.0 이하가 남긴 캐시 목록도 한 번 함께 읽는다.
 */
class HostCascadeReactivationTest extends TestCase
{
    use DatabaseTransactions;

    private const HOST = 'test-cascade-host';

    /**
     * 테스트 호스트의 언어팩 한 개를 만듭니다.
     *
     * @param  string  $locale  로케일
     * @param  string  $status  상태
     * @param  string|null  $reason  비활성 사유
     * @return LanguagePack 만든 언어팩
     */
    private function pack(string $locale, string $status = 'active', ?string $reason = null): LanguagePack
    {
        return LanguagePack::create([
            'identifier' => 'test-cascade-host-'.$locale,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => self::HOST,
            'locale' => $locale,
            'locale_name' => strtoupper($locale),
            'locale_native_name' => strtoupper($locale),
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => $status,
            'deactivated_reason' => $reason,
            'is_protected' => true,
            'manifest' => [],
            'source_type' => LanguagePackSourceType::Bundled->value,
        ]);
    }

    /**
     * 호스트 연쇄 비활성화는 host_deactivated 와 시각을 남기고, 캐시를 비워도 다시 켤 목록이 남는다.
     *
     * @scenario case=cascade_then_cache_clear
     *
     * @effects host_cascade_marks_language_packs_in_db, pending_reactivation_survives_cache_clear
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_cascade_marks_db_and_survives_cache_clear(): void
    {
        $ko = $this->pack('ko');
        $en = $this->pack('en');

        $registrar = app(LanguagePackBundledRegistrar::class);
        $deactivated = $registrar->deactivateForExtension('module', self::HOST);

        $this->assertEqualsCanonicalizing([$ko->id, $en->id], $deactivated);
        foreach ([$ko, $en] as $pack) {
            $pack->refresh();
            $this->assertSame(LanguagePackStatus::Inactive->value, $pack->status);
            $this->assertSame(LanguagePackDeactivationReason::HostDeactivated->value, $pack->deactivated_reason);
            $this->assertNotNull($pack->deactivated_at);
        }

        app(CacheInterface::class)->flush();

        $pending = $registrar->getPendingForReactivation('module', self::HOST);
        $this->assertSame([$ko->id, $en->id], array_column($pending, 'id'));

        // 한 번 읽어도 지워지지 않는다 — 운영자가 창을 닫으면 다음 활성화 때 다시 묻는다.
        $this->assertCount(2, $registrar->getPendingForReactivation('module', self::HOST));
    }

    /**
     * 7.1.0 이하가 남긴 캐시 목록(레거시)도 합쳐 읽고, 읽은 뒤 지운다.
     *
     * @scenario case=legacy_stash
     *
     * @effects legacy_stash_is_merged_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_legacy_stash_is_merged(): void
    {
        $legacy = $this->pack('ja', LanguagePackStatus::Inactive->value);
        $marked = $this->pack('ko', LanguagePackStatus::Inactive->value, LanguagePackDeactivationReason::HostDeactivated->value);

        $registrar = app(LanguagePackBundledRegistrar::class);
        $registrar->stashDeactivatedForReactivation('module', self::HOST, [$legacy->id]);

        $this->assertEqualsCanonicalizing([$legacy->id, $marked->id], array_column($registrar->getPendingForReactivation('module', self::HOST), 'id'));
        $this->assertSame([$marked->id], array_column($registrar->getPendingForReactivation('module', self::HOST), 'id'));
    }

    /**
     * 켜면 사유와 시각을 비우고, 운영자가 직접 끄면 사유 없이 시각만 남긴다.
     *
     * @scenario case=activate_and_manual_deactivate
     *
     * @effects activate_clears_deactivation_marks, manual_deactivate_has_no_host_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_activate_clears_and_manual_deactivate_has_no_reason(): void
    {
        $pack = $this->pack('ko', LanguagePackStatus::Inactive->value, LanguagePackDeactivationReason::HostDeactivated->value);
        $pack->forceFill(['deactivated_at' => now()->subHour()])->save();

        $service = app(LanguagePackService::class);
        $active = $service->activate($pack);
        $this->assertSame(LanguagePackStatus::Active->value, $active->status);
        $this->assertNull($active->deactivated_reason);
        $this->assertNull($active->deactivated_at);

        $other = $this->pack('en');
        $other->forceFill(['is_protected' => false])->save();
        $inactive = $service->deactivate($other);
        $this->assertNull($inactive->deactivated_reason);
        $this->assertNotNull($inactive->deactivated_at);
        $this->assertSame([], array_column(app(LanguagePackBundledRegistrar::class)->getPendingForReactivation('module', self::HOST), 'id'));
    }
}
