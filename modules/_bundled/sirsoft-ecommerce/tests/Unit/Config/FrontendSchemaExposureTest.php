<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Config;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 프론트엔드 노출 스키마 계약 테스트 (이슈 #524 §4.3)
 *
 * 결함: 마일리지 기능을 꺼도 마이페이지 「적립금」 탭이 그대로 보였다. 탭 바는
 * `_global.modules['sirsoft-ecommerce']` 를 보고 판단하는데, 그 값은 `frontend_schema` 로
 * 노출한 설정만 담기고 거기에 `mileage` 가 없었다.
 *
 * 이 테스트가 지키는 것은 «탭이 숨는가» 가 아니라 «탭이 판단할 값이 프런트에 도달하는가» 다 —
 * 노출 선언이 빠지면 레이아웃 식이 아무리 옳아도 항상 undefined 를 읽는다.
 *
 * @scenario surface=mypage_tabs,setting=mileage_enabled
 *
 * @effects ecommerce_exposes_mileage_enabled_to_frontend
 */
#[Group('ecommerce')]
#[Group('settings')]
class FrontendSchemaExposureTest extends ModuleTestCase
{
    /**
     * frontend_schema 가 mileage.enabled 를 노출한다.
     *
     * @effects ecommerce_exposes_mileage_enabled_to_frontend
     *
     * @return void 반환값 없음
     */
    public function test_frontend_schema_exposes_mileage_enabled(): void
    {
        $schema = $this->loadFrontendSchema();

        $this->assertArrayHasKey('mileage', $schema, 'frontend_schema 에 mileage 가 없으면 탭 바가 판단할 값이 프런트에 오지 않는다');
        $this->assertTrue((bool) ($schema['mileage']['expose'] ?? false), 'mileage 블록이 expose=true 여야 한다');
        $this->assertTrue(
            (bool) ($schema['mileage']['fields']['enabled']['expose'] ?? false),
            'mileage.enabled 가 expose=true 여야 탭 숨김 판단이 가능하다'
        );
    }

    /**
     * 회귀: 문의(inquiry) 노출은 그대로 유지된다 (같은 탭 바가 이 값도 읽는다).
     *
     * @effects ecommerce_keeps_inquiry_board_slug_exposure
     *
     * @return void 반환값 없음
     */
    public function test_frontend_schema_keeps_inquiry_exposure(): void
    {
        $schema = $this->loadFrontendSchema();

        $this->assertTrue((bool) ($schema['inquiry']['expose'] ?? false));
        $this->assertTrue((bool) ($schema['inquiry']['fields']['board_slug']['expose'] ?? false));
    }

    /**
     * 마일리지 기본값은 꺼짐이다 — 노출해도 기본이 켜짐이면 의미가 뒤집힌다.
     *
     * @effects ecommerce_mileage_default_disabled
     *
     * @return void 반환값 없음
     */
    public function test_mileage_defaults_to_disabled(): void
    {
        $defaults = $this->loadDefaults();

        $this->assertArrayHasKey('mileage', $defaults);
        $this->assertFalse((bool) ($defaults['mileage']['enabled'] ?? true));
    }

    /**
     * 번들 defaults.json 을 읽습니다.
     *
     * @return array<string, mixed> 디코딩된 설정 정의
     */
    private function loadManifest(): array
    {
        $path = dirname(__DIR__, 3).'/config/settings/defaults.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true) ?? [];
    }

    /**
     * frontend_schema 블록을 읽습니다.
     *
     * @return array<string, 반환값
     */
    private function loadFrontendSchema(): array
    {
        return $this->loadManifest()['frontend_schema'] ?? [];
    }

    /**
     * defaults 블록을 읽습니다.
     *
     * @return array<string, 반환값
     */
    private function loadDefaults(): array
    {
        return $this->loadManifest()['defaults'] ?? [];
    }
}
