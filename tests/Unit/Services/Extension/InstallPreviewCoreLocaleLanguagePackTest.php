<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Repositories\LanguagePackRepository;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 설치 미리보기 — 같은 로케일의 코어 언어팩이 켜져 있지 않으면 확장 언어팩은 설치할 수 없는 후보로 표시한다.
 *
 * 확장 언어팩 설치는 같은 로케일 코어 언어팩이 활성이어야 진행된다(`core_locale_missing`). 후보 산출이 이 판정을
 * 보지 않으면 설치 창은 그 언어팩을 기본 선택하고, 터미널 `--with-language-packs=all` 은 그 언어팩을 모두 시도해
 * 전부 실패한다. 후보 행은 남기되(어떤 언어팩이 있는지 보이게) 기본 선택에서 빼고 사유를 싣는다.
 * 이미 설치된 의존 확장의 버전이 언어팩 요구(`requires.target_version`)보다 낮은 경우(`target_version_too_old`)도
 * 함께 설치로 풀리지 않으므로 같은 방식으로 표시한다. 대상 확장 미설치(`target_not_installed`)는 본 확장·의존 확장을
 * 함께 설치하면서 풀리므로 막지 않는다.
 *
 * 시나리오 매니페스트: tests/scenarios/extension-install-language-pack-failures.yaml
 */
class InstallPreviewCoreLocaleLanguagePackTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 페이지 모듈(번들 ja 언어팩 동봉)의 언어팩 후보에서 ja 행을 찾는다.
     *
     * @return array<string, mixed>|null ja 행
     */
    private function pageJaRow(): ?array
    {
        app(LanguagePackRegistry::class)->invalidate();

        $builder = app(ExtensionInstallPreviewBuilder::class);
        $rows = (new ReflectionMethod($builder, 'buildLanguagePacks'))
            ->invoke($builder, LanguagePackScope::Module, 'sirsoft-page', []);

        foreach ($rows as $row) {
            if ($row['bundled_identifier'] === 'g7-module-sirsoft-page-ja') {
                return $row;
            }
        }

        return null;
    }

    /**
     * 시험 대상 슬롯을 비운다 — 코어 ja·페이지 ja 기록을 지운다(트랜잭션 안에서만).
     *
     * @return void 반환값 없음
     */
    private function clearJaSlots(): void
    {
        LanguagePack::query()->where('locale', 'ja')
            ->where(function ($q) {
                $q->where('scope', LanguagePackScope::Core->value)
                    ->orWhere(fn ($q) => $q->where('scope', LanguagePackScope::Module->value)->where('target_identifier', 'sirsoft-page'));
            })
            ->delete();
    }

    /**
     * 코어 ja 가 켜져 있지 않으면 ja 확장 언어팩은 고를 수 없는 후보(사유 포함)이고 기본 선택이 아니다.
     *
     * @scenario case=core_locale_inactive_language_pack_unavailable
     *
     * @effects install_preview_marks_core_locale_missing_language_pack_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_is_unavailable_when_core_locale_is_not_active(): void
    {
        $this->clearJaSlots();

        $row = $this->pageJaRow();

        $this->assertNotNull($row, '페이지 모듈 번들 ja 언어팩 후보가 있어야 한다(동봉 ja).');
        $this->assertFalse($row['available'], '코어 ja 가 꺼져 있으면 설치할 수 없다');
        $this->assertSame('core_locale_missing', $row['blocked_reason'] ?? null);
        $this->assertFalse($row['default_selected'], '설치할 수 없는 언어팩을 기본 선택하면 창의 기본값 그대로 설치할 때 실패한다');
    }

    /**
     * 코어 ja 가 켜져 있으면 종전처럼 설치할 수 있는 후보다(과차단 금지).
     *
     * @scenario case=core_locale_active_language_pack_available
     *
     * @effects install_preview_marks_core_locale_missing_language_pack_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_is_available_when_core_locale_is_active(): void
    {
        $this->clearJaSlots();
        LanguagePack::query()->create([
            'identifier' => 'acme-core-ja',
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
        ]);

        $row = $this->pageJaRow();

        $this->assertNotNull($row);
        $this->assertTrue($row['available']);
        $this->assertNull($row['blocked_reason'] ?? null);
        $this->assertSame($row['can_select'], $row['default_selected'], '설치할 수 있으면 권한 판정 그대로 기본 선택');
    }

    /**
     * 번들 언어팩 후보 하나를 지정한 설치 차단 사유로 돌려주도록 서비스를 바꿔 끼우고 후보 행을 만든다.
     *
     * 사유 판정 자체(설치된 대상 확장 버전과 `requires.target_version` 비교)는 LanguagePackService 시험이 맡는다 —
     * 여기서는 미리보기가 그 사유를 행에 어떻게 싣는지만 본다. 번들 언어팩 중 `target_version` 을 선언한 것이 없어
     * 가상 후보로 만든다.
     *
     * @param  string|null  $blockedReason  서비스가 붙여 줄 설치 차단 사유
     * @param  bool  $targetIsMetDependency  대상 확장이 이미 설치·활성화되어 충족된 의존 확장인지
     * @return array<string, mixed> 후보 행
     */
    private function rowWithBlockedReason(?string $blockedReason, bool $targetIsMetDependency = true): array
    {
        $pack = app(LanguagePackRepository::class)->buildVirtualFromManifest([
            'identifier' => 'g7-module-acme-dep-ja',
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'acme-dep',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'version' => '1.0.0',
            'requires' => ['target_version' => '>=2.0.0', 'depends_on_core_locale' => true],
        ], 'g7-module-acme-dep-ja');
        $pack->setAttribute('install_blocked_reason', $blockedReason);

        $service = $this->mock(LanguagePackService::class);
        $service->shouldReceive('getUninstalledBundledPacks')->andReturn(collect([$pack]));
        $service->shouldReceive('annotateIcon')->andReturnUsing(fn (LanguagePack $p) => $p);

        $builder = app(ExtensionInstallPreviewBuilder::class);
        $rows = (new ReflectionMethod($builder, 'buildLanguagePacks'))->invoke(
            $builder,
            LanguagePackScope::Module,
            'acme-self',
            [['identifier' => 'acme-dep', 'type' => LanguagePackScope::Module->value, 'is_met' => $targetIsMetDependency]]
        );

        $this->assertCount(1, $rows, '의존 확장 언어팩 후보가 행으로 남아야 한다');

        return $rows[0];
    }

    /**
     * 이미 설치된 의존 확장의 버전이 언어팩 요구보다 낮으면 고를 수 없는 후보(사유·범위·요구 버전 포함)이고 기본 선택이 아니다.
     *
     * 함께 설치는 이미 설치된 확장을 업데이트하지 않으므로 이 설치로는 풀리지 않는다.
     *
     * @scenario case=target_version_too_old_language_pack_unavailable
     *
     * @effects install_preview_marks_target_version_too_old_language_pack_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_is_unavailable_when_target_version_is_too_old(): void
    {
        $row = $this->rowWithBlockedReason('target_version_too_old');

        $this->assertFalse($row['available'], '대상 확장 버전이 요구보다 낮으면 설치할 수 없다');
        $this->assertSame('target_version_too_old', $row['blocked_reason'] ?? null);
        $this->assertFalse($row['default_selected'], '설치할 수 없는 언어팩을 기본 선택하면 창의 기본값 그대로 설치할 때 실패한다');
        $this->assertSame('module', $row['scope'] ?? null, '사유 문장(대상 :scope (:target))에 쓰는 범위를 싣는다');
        $this->assertSame('acme-dep', $row['target_identifier']);
        $this->assertSame('>=2.0.0', $row['required_target_version'] ?? null, '사유 문장(:constraint)에 쓰는 요구 버전을 싣는다');
    }

    /**
     * 대상 확장 미설치(target_not_installed)는 본 확장·의존 확장을 함께 설치하면서 풀리므로 막지 않는다(과차단 금지).
     *
     * @scenario case=target_not_installed_language_pack_available
     *
     * @effects install_preview_marks_target_version_too_old_language_pack_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_for_not_installed_target_stays_available(): void
    {
        $row = $this->rowWithBlockedReason('target_not_installed', false);

        $this->assertTrue($row['available']);
        $this->assertNull($row['blocked_reason'] ?? null);
        $this->assertSame($row['can_select'], $row['default_selected'], '설치할 수 있으면 권한 판정 그대로 기본 선택');
    }
}
