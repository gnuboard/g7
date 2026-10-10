<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use Modules\Sirsoft\Page\Services\PageSettingsService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 첨부 정책의 프론트엔드 노출 회귀 테스트 (#703)
 *
 * 회귀 배경: 첨부 업로드 서버 검증은 모듈 설정(attachment.max_size_mb·allowed_types·max_count)을
 * 따르는데, 관리자 페이지 폼의 업로더는 10MB·확장자 7종·5개를 리터럴로 박아 두었다. 설정을 바꾸면 화면은
 * 서버가 허용하는 파일을 먼저 거절하거나, 서버가 거절할 파일을 받아 저장 단계에서야 실패했다.
 * 화면이 같은 설정을 읽으려면 설정이 프론트엔드 전역(`G7Config.modules`)으로 노출되어야 한다.
 *
 * 설정 서비스는 활성 디렉토리의 defaults.json 을 읽으므로, 이 테스트는 이 저장소의 원본(_bundled)
 * defaults.json 을 가리키는 서비스로 검증한다 — 활성 디렉토리는 모듈 업데이트 뒤에야 같은 파일이 된다.
 */
class PageAttachmentFrontendSettingsTest extends ModuleTestCase
{
    /**
     * 원본(_bundled) defaults.json 을 읽는 설정 서비스를 만듭니다.
     *
     * @return PageSettingsService 원본 기본값 파일을 가리키는 설정 서비스
     */
    private function bundledSettingsService(): PageSettingsService
    {
        $path = dirname(__DIR__, 2).'/config/settings/defaults.json';

        return new class($path) extends PageSettingsService
        {
            /**
             * 원본 defaults.json 경로를 받아 둡니다.
             *
             * @param  string  $bundledPath  원본 defaults.json 절대 경로
             */
            public function __construct(private readonly string $bundledPath) {}

            /**
             * 원본 defaults.json 경로를 반환합니다.
             *
             * @return string|null 원본 defaults.json 절대 경로
             */
            public function getSettingsDefaultsPath(): ?string
            {
                return $this->bundledPath;
            }
        };
    }

    /**
     * 첨부 정책 3종이 프론트엔드 노출 설정에 실린다.
     *
     * @scenario surface=frontend_settings, setting=attachment_policy
     *
     * @effects attachment_policy_exposed_to_frontend
     *
     * @return void 없음
     */
    public function test_attachment_policy_is_exposed_to_frontend(): void
    {
        $service = $this->bundledSettingsService();
        $exposed = $service->getFrontendSettings();

        $this->assertSame(['attachment'], array_keys($exposed));
        $this->assertSame($service->getSetting('attachment.max_size_mb'), $exposed['attachment']['max_size_mb'] ?? null);
        $this->assertSame($service->getSetting('attachment.max_count'), $exposed['attachment']['max_count'] ?? null);
        $this->assertIsArray($exposed['attachment']['allowed_types'] ?? null);
        $this->assertNotEmpty($exposed['attachment']['allowed_types']);
    }

    /**
     * 노출 스키마·메타 선언이 설정 카테고리로 섞여 들어가지 않는다.
     *
     * @scenario surface=settings_service, setting=attachment_policy
     *
     * @effects frontend_schema_not_treated_as_category
     *
     * @return void 없음
     */
    public function test_frontend_schema_is_not_a_settings_category(): void
    {
        $settings = $this->bundledSettingsService()->getAllSettings();

        $this->assertSame(['attachment'], array_keys($settings));
        $this->assertArrayHasKey('max_count', $settings['attachment']);
        $this->assertArrayHasKey('max_size_mb', $settings['attachment']);
        $this->assertArrayHasKey('allowed_types', $settings['attachment']);
    }
}
