<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationPurpose;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 purpose 계약 정합성 테스트
 *
 * 코어 purpose 는 세 곳에 동시에 존재해야 온전히 동작합니다.
 *
 * 1. `IdentityVerificationPurpose` enum — 코드에서 참조하는 값
 * 2. `IdentityVerificationManager::$corePurposes` — 관리자 화면·API 가 읽는 목록
 * 3. `lang/{ko,en}/identity.php` 의 `purposes.{value}` — 사람이 읽는 라벨
 *
 * 실측(2026-07-29): 2단계 인증을 도입하며 enum 에 `login` 만 추가하고 2·3 을 빠뜨렸다.
 * 그 결과 `hasPurpose('login')` 이 false 였고, `getAllPurposes()` 에서 빠져 관리자가
 * 이 목적의 메시지 템플릿·정책을 만들 수 없었으며, `Login->label()` 은 라벨 대신
 * `identity.purposes.login.label` 이라는 i18n 키 원문을 그대로 돌려줬다.
 *
 * 셋 중 하나만 빠져도 조용히 반쪽 동작하므로 값 목록을 하드코딩하지 않고
 * enum 을 기준으로 나머지 둘을 전수 대조한다 — 새 purpose 를 추가하면 자동으로 검사 대상이 된다.
 */
class CorePurposeRegistryParityTest extends TestCase
{
    #[Test]
    public function every_core_purpose_is_registered_in_the_manager(): void
    {
        $registered = array_keys(app(IdentityVerificationManager::class)->getAllPurposes());

        foreach (IdentityVerificationPurpose::values() as $purpose) {
            $this->assertContains(
                $purpose,
                $registered,
                "purpose '{$purpose}' 가 corePurposes 레지스트리에 없습니다 — 관리자가 이 목적의 메시지 템플릿·정책을 만들 수 없습니다."
            );
        }
    }

    #[Test]
    public function every_core_purpose_answers_true_to_has_purpose(): void
    {
        $manager = app(IdentityVerificationManager::class);

        foreach (IdentityVerificationPurpose::values() as $purpose) {
            $this->assertTrue(
                $manager->hasPurpose($purpose),
                "hasPurpose('{$purpose}') 가 false 입니다 — 코어 purpose 인데 등록되지 않은 것으로 취급됩니다."
            );
        }
    }

    #[Test]
    public function every_core_purpose_resolves_a_label_and_description_in_each_base_locale(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach (IdentityVerificationPurpose::cases() as $case) {
                foreach (['label', 'description'] as $field) {
                    $key = "identity.purposes.{$case->value}.{$field}";
                    $translated = __($key);

                    $this->assertNotSame(
                        $key,
                        $translated,
                        "[{$locale}] {$key} 번역이 없습니다 — 화면에 i18n 키 원문이 그대로 노출됩니다."
                    );
                }
            }
        }
    }
}
