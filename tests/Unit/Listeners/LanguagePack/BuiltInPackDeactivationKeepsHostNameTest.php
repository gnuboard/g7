<?php

namespace Tests\Unit\Listeners\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\LanguagePack;
use App\Models\Module;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 확장에 내장된 언어팩(ko·en)이 함께 꺼져도 확장 이름·설명의 그 언어 값은 지우지 않는다 (Q-G2 Hello 실측)
 *
 * 모듈·플러그인·템플릿을 끄면 그 확장에 내장된 언어팩(`bundled_with_extension`)도 함께 꺼지고, 비활성화 훅이
 * 확장 행 name/description 에서 그 언어 키를 지웠다. 내장 ko·en 은 확장 매니페스트 자신의 언어라 다시 켜도
 * 채울 seed 가 없어, 끄고 켠 확장의 이름·설명이 `[]` 로 남았다(실측: Hello 모듈 강제 끄기 → 다시 켜기 뒤
 * DB name `[]`, 플러그인 설치 창 의존 확장 이름이 식별자로 보임). 추가 언어팩(ja 등)은 종전처럼 지운다.
 *
 * 시나리오 매니페스트: tests/scenarios/built-in-language-pack-deactivation-keeps-host-name.yaml
 */
class BuiltInPackDeactivationKeepsHostNameTest extends TestCase
{
    use DatabaseTransactions;

    private const TARGET = 'acme-builtin_lp_module';

    /**
     * 이름·설명에 ko/en/ja 가 있는 모듈 행을 만든다.
     *
     * @return Module 모듈 행
     */
    private function module(): Module
    {
        return Module::query()->create([
            'identifier' => self::TARGET,
            'vendor' => 'acme',
            'name' => ['ko' => '내장 모듈', 'en' => 'Built-in Module', 'ja' => '内蔵モジュール'],
            'description' => ['ko' => '설명', 'en' => 'Description', 'ja' => '説明'],
            'version' => '1.0.0',
            'status' => 'inactive',
        ]);
    }

    /**
     * 그 모듈 대상 언어팩 행을 만든다.
     *
     * @param  string  $locale  로케일
     * @param  string  $sourceType  출처 유형
     * @return LanguagePack 언어팩 행
     */
    private function pack(string $locale, string $sourceType): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => 'acme-module-'.self::TARGET.'-'.$locale,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => self::TARGET,
            'locale' => $locale,
            'locale_name' => $locale,
            'locale_native_name' => $locale,
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => $sourceType === 'bundled_with_extension',
            'manifest' => [],
            'source_type' => $sourceType,
        ]);
    }

    /**
     * 내장 언어팩(ko) 비활성화는 확장 이름·설명의 ko 값을 지우지 않는다.
     *
     * @scenario pack_origin=built_in
     *
     * @effects host_own_locale_kept_on_built_in_pack_deactivation
     *
     * @return void 반환값 없음
     */
    public function test_built_in_pack_deactivation_keeps_host_locale(): void
    {
        $module = $this->module();

        app(SyncDatabaseTranslations::class)->handleDeactivated($this->pack('ko', 'bundled_with_extension'));

        $module->refresh();
        $this->assertSame('내장 모듈', $module->name['ko'] ?? null);
        $this->assertSame('설명', $module->description['ko'] ?? null);
    }

    /**
     * 추가 언어팩(ja) 비활성화는 종전처럼 그 언어 값을 지운다(과보존 금지).
     *
     * @scenario pack_origin=additional
     *
     * @effects host_own_locale_kept_on_built_in_pack_deactivation
     *
     * @return void 반환값 없음
     */
    public function test_additional_pack_deactivation_still_strips_locale(): void
    {
        $module = $this->module();

        app(SyncDatabaseTranslations::class)->handleDeactivated($this->pack('ja', 'bundled'));

        $module->refresh();
        $this->assertArrayNotHasKey('ja', $module->name);
        $this->assertSame('내장 모듈', $module->name['ko'] ?? null);
    }
}
