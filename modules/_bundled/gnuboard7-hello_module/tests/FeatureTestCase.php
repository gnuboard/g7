<?php

namespace Modules\Gnuboard7\HelloModule\Tests;

/**
 * Hello 모듈 Feature 테스트 베이스
 *
 * HTTP 요청 기반 API 테스트에 사용합니다.
 */
abstract class FeatureTestCase extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleAsActive();
        $this->registerModuleRoutes();
    }
}
