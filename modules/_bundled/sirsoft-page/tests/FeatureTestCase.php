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
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 모듈을 활성화 상태로 등록 (테스트 환경)
        $this->registerModuleAsActive();

        // 모듈 라우트를 수동으로 등록 (부모 ModuleTestCase 의 등록·이름 조회표 재구성을 그대로 쓴다 — 사본 override 금지)
        $this->registerModuleRoutes();
    }

    /**
     * 모듈을 활성화 상태로 등록합니다.
     *
     * @return void 반환값 없음
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
