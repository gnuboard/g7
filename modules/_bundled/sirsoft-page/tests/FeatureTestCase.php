<?php

namespace Modules\Sirsoft\Page\Tests;

use App\Enums\ExtensionStatus;
use App\Models\Module;

/**
 * Page 모듈 Feature 테스트 베이스 클래스
 *
 * HTTP 요청을 통한 API 테스트에 사용합니다.
 * ModuleTestCase를 확장하여 라우트 등록과 모듈 활성화를 추가합니다.
 */
abstract class FeatureTestCase extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈을 활성화 상태로 등록 (테스트 환경)
        $this->registerModuleAsActive();

        // 모듈 라우트를 수동으로 등록 — 등록 뒤 이름 조회 재구성까지 포함한 ModuleTestCase 의
        // 구현을 쓴다. 종전에는 이 클래스가 같은 메서드를 사본으로 override 해, 라우트 캐시가
        // 있는 머신에서는 fluent `->name()` 이 조회표에 오르지 않아 `route()`/서명 URL 이
        // `Route [...] not defined` 를 던졌다. (G3 회차 15)
        $this->registerModuleRoutes();
    }

    /**
     * 모듈을 활성화 상태로 등록합니다.
     */
    protected function registerModuleAsActive(): void
    {
        if (Module::where('identifier', 'sirsoft-page')->exists()) {
            return;
        }

        Module::create([
            'identifier' => 'sirsoft-page',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '페이지', 'en' => 'Page'],
            'status' => ExtensionStatus::Active->value,
            'version' => '0.1.1',
            'config' => [],
        ]);
    }
}
