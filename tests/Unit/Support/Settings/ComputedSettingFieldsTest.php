<?php

namespace Tests\Unit\Support\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsCliKeyResolver;
use App\Support\Settings\ComputedSettingFields;
use Tests\TestCase;

/**
 * 계산 필드 판정 한 곳 — 관리자 설정 서비스(저장에서 빼기·응답에 싣기)와 터미널 설정 명령이 같은 판정을 쓴다 (#887)
 *
 * @scenario-manifest tests/scenarios/admin-settings-computed-fields.yaml
 */
class ComputedSettingFieldsTest extends TestCase
{
    /** 판정 표본 범주 스키마 */
    private const CATEGORY = [
        'fields' => [
            'render_policy' => ['type' => 'object', 'computed' => true],
            'site_logo_url' => ['computed' => 'site_logo', 'frontend_key' => 'logo_url'],
            'plain' => ['type' => 'string'],
            'off' => ['computed' => false],
            'empty' => ['computed' => ''],
            'scalar' => 'computed',
        ],
    ];

    /**
     * `computed` 가 비어 있지 않은 배열 스키마만 계산 필드다 — 두 모양(스키마 목록·화면 키 맵)이 같은 판정에서 나온다.
     *
     * @scenario case=single_source
     *
     * @effects computed_judgement_single_source
     *
     * @return void 반환값 없음
     */
    public function test_judgement_and_two_shapes(): void
    {
        $this->assertTrue(ComputedSettingFields::isComputed(['computed' => true]));
        $this->assertFalse(ComputedSettingFields::isComputed(['computed' => false]));
        $this->assertFalse(ComputedSettingFields::isComputed('computed'));
        $this->assertFalse(ComputedSettingFields::isComputed(null));

        $this->assertSame(['render_policy', 'site_logo_url'], array_keys(ComputedSettingFields::schemas(self::CATEGORY)));
        $this->assertSame(['render_policy' => 'render_policy', 'site_logo_url' => 'logo_url'], ComputedSettingFields::frontendKeys(self::CATEGORY));

        $this->assertSame([], ComputedSettingFields::schemas(['fields' => 'broken']));
        $this->assertSame([], ComputedSettingFields::schemas(null));
        $this->assertSame([], ComputedSettingFields::frontendKeys([]));
    }

    /**
     * 터미널 설정 명령의 계산 항목 판정이 같은 결과를 낸다(필드 목록·단건 판정).
     *
     * @scenario case=single_source
     *
     * @effects computed_judgement_single_source
     *
     * @return void 반환값 없음
     */
    public function test_cli_resolver_uses_the_same_judgement(): void
    {
        $config = $this->createMock(ConfigRepositoryInterface::class);
        $config->method('getFrontendSchema')->willReturn(['content' => self::CATEGORY]);
        $resolver = new SettingsCliKeyResolver($config);

        $this->assertSame(ComputedSettingFields::frontendKeys(self::CATEGORY), $resolver->computedFields('content'));
        $this->assertTrue($resolver->isComputed('content', 'site_logo_url'));
        $this->assertFalse($resolver->isComputed('content', 'off'));
        $this->assertFalse($resolver->isComputed('content', 'scalar'));
        $this->assertSame([], $resolver->computedFields('missing'));
    }
}
