<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use Modules\Sirsoft\Page\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Page\Http\Requests\UploadPageAttachmentRequest;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Module;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Services\PageSettingsService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 첨부 정책(개수·용량·형식) 강제 테스트 (E3)
 *
 * 수정 전에는 `config/settings/defaults.json` 의 `attachment.*` 가 SSoT 로 선언만 되고
 * 참조가 0건이었습니다. Request 는 `max:10240` + mimes 16종을 하드코딩했고(설정은 MIME 6종),
 * 개수 강제는 어디에도 없었습니다.
 *
 * 주의: 현행 하드코딩 값(10240KB)과 우연히 일치하면 수정 전에도 green 이 되므로,
 * 용량 검증은 반드시 기본값과 다른 설정값을 주입해 판정합니다.
 */
class PageAttachmentPolicyTest extends ModuleTestCase
{
    /**
     * 첨부 설정을 저장합니다.
     *
     * config 를 직접 주입하지 않고 **설정 서비스를 경유**한다 — 직접 주입하면
     * 설정이 미러에 도달하는 경로가 통째로 우회되어, 서비스가 없어 미러가 영구
     * 미존재였던 결함(공개이슈 #109)이 테스트에서 드러나지 않는다.
     *
     * @param  array  $overrides  덮어쓸 설정 값
     */
    private function writeAttachmentSettings(array $overrides = []): void
    {
        config(['g7_settings.modules.sirsoft-page' => null]);

        app(PageSettingsService::class)->saveSettings([
            'attachment' => array_merge([
                'max_count' => 5,
                'max_size_mb' => 10,
                'allowed_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            ], $overrides),
        ]);
    }

    /**
     * 임시 첨부를 N 개 만듭니다.
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $count  생성 개수
     */
    private function seedTempAttachments(string $tempKey, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            PageAttachment::create([
                'page_id' => null,
                'temp_key' => $tempKey,
                'collection' => 'attachments',
                'original_filename' => "temp-{$i}.png",
                'stored_filename' => "temp-{$i}.png",
                'path' => "temp/{$tempKey}/temp-{$i}.png",
                'mime_type' => 'image/png',
                'size' => 100,
                'order' => $i,
            ]);
        }
    }

    /**
     * 설정한 최대 용량이 검증 규칙에 반영되는지 테스트합니다.
     */
    public function test_max_size_setting_is_reflected_in_rules(): void
    {
        // 기본값(10MB)과 다른 값이어야 하드코딩과의 우연한 일치를 피할 수 있다
        $this->writeAttachmentSettings(['max_size_mb' => 5]);

        $rules = (new UploadPageAttachmentRequest)->rules();

        $this->assertContains('max:5120', $rules['file']);
        $this->assertNotContains('max:10240', $rules['file']);
    }

    /**
     * 허용 형식이 설정의 MIME 목록으로 강제되는지 테스트합니다.
     */
    public function test_allowed_types_use_mimetypes_from_settings(): void
    {
        $this->writeAttachmentSettings(['allowed_types' => ['image/png', 'application/pdf']]);

        $rules = (new UploadPageAttachmentRequest)->rules();

        $this->assertContains('mimetypes:image/png,application/pdf', $rules['file']);
        // 수정 전: mimes:jpg,jpeg,png,... 16종 하드코딩 (설정과 무관)
        foreach ($rules['file'] as $rule) {
            $this->assertStringNotContainsString('mimes:jpg', (string) $rule);
        }
    }

    /**
     * 허용 형식 목록이 비면 형식을 제한하지 않는지 테스트합니다 (탈출구).
     */
    public function test_empty_allowed_types_disables_type_restriction(): void
    {
        $this->writeAttachmentSettings(['allowed_types' => []]);

        $rules = (new UploadPageAttachmentRequest)->rules();

        foreach ($rules['file'] as $rule) {
            $this->assertStringNotContainsString('mimetypes:', (string) $rule);
        }
    }

    /**
     * temp_key 연결이 개수 상한을 초과하면 차단되는지 테스트합니다.
     */
    public function test_temp_key_link_over_limit_is_blocked(): void
    {
        $this->writeAttachmentSettings(['max_count' => 5]);

        $tempKey = 'tk_'.str_repeat('a', 20);
        $this->seedTempAttachments($tempKey, 8);

        $this->expectException(AttachmentLimitExceededException::class);

        app(PageAttachmentService::class)->linkTempAttachmentsWithMove($tempKey, 1);
    }

    /**
     * 상한과 같은 개수는 통과하는지 테스트합니다 (경계).
     */
    public function test_link_at_limit_passes(): void
    {
        $this->writeAttachmentSettings(['max_count' => 5]);

        $tempKey = 'tk_'.str_repeat('b', 20);
        $this->seedTempAttachments($tempKey, 5);

        app(PageAttachmentService::class)->assertAttachmentCountWithin(null, 5, 'tk_none');

        $this->assertTrue(true);
    }

    /**
     * 기존 첨부와 합산해 판정하는지 테스트합니다.
     */
    public function test_existing_plus_new_over_limit_is_blocked(): void
    {
        $this->writeAttachmentSettings(['max_count' => 5]);

        $tempKey = 'tk_'.str_repeat('c', 20);
        $this->seedTempAttachments($tempKey, 4);

        $this->expectException(AttachmentLimitExceededException::class);

        app(PageAttachmentService::class)->assertAttachmentCountWithin(null, 2, $tempKey);
    }

    /**
     * 상한 미설정(0)이면 제한하지 않는지 테스트합니다.
     */
    public function test_zero_limit_is_unrestricted(): void
    {
        $this->writeAttachmentSettings(['max_count' => 0]);

        app(PageAttachmentService::class)->assertAttachmentCountWithin(null, 100, null);

        $this->assertTrue(true);
    }

    /**
     * 스토리지 디스크가 모듈 설정에 의존하지 않는지 테스트합니다.
     *
     * 설정 파일 자체가 이 디스크에 저장되므로 여기서 module_setting() 을 호출하면
     * 설정 로드 → getStorage() → getStorageDisk() → 설정 로드 무한 재귀가 된다.
     * (기존 `config('sirsoft-page.attachment.disk')` 참조도 해당 config 가 없어 항상 폴백이었다.)
     */
    public function test_storage_disk_does_not_depend_on_module_settings(): void
    {
        $this->writeAttachmentSettings();

        $module = new Module;

        $this->assertSame('modules', $module->getStorageDisk());
    }
}
