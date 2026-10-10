<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Page\Http\Requests\UploadPageAttachmentRequest;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Support\AttachmentLimits;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 첨부 공개 설정(publicLimits)의 계층 역전 제거 (#599 validate page minor)
 *
 * #599 가 화면용 공개 설정 `PageAttachmentService::publicLimits()` 를 만들면서 허용 형식 폴백을
 * `UploadPageAttachmentRequest::FALLBACK_ALLOWED_TYPES`(private → public 으로 열어)에서 읽었다.
 * Service 가 HTTP 계층(FormRequest)에 기대는 역전이다. 해석을 하위 지점(AttachmentLimits)으로
 * 내리고 두 계층이 그것을 함께 쓴다.
 */
class AttachmentLimitsLayeringTest extends ModuleTestCase
{
    /**
     * 첨부 설정 미러를 테스트 값으로 바꿉니다 (설정 파일은 건드리지 않는다).
     *
     * @param  array  $attachment  attachment.* 설정
     * @return void 반환값 없음
     */
    private function setAttachmentSettings(array $attachment): void
    {
        Config::set('g7_settings.modules.sirsoft-page.attachment', $attachment);
    }

    /**
     * 업로드 검증 규칙에서 파일 규칙을 꺼냅니다.
     *
     * @return array<int, string> file 필드 규칙
     */
    private function uploadFileRules(): array
    {
        return (new UploadPageAttachmentRequest)->rules()['file'];
    }

    /**
     * Service 가 HTTP 계층(FormRequest)을 참조하지 않는다.
     *
     * 수정 전 실패 이유: PageAttachmentService 가 `UploadPageAttachmentRequest` 를 import 해
     * 그 상수를 읽었다.
     *
     * @scenario case=layering
     *
     * @effects attachment_service_does_not_depend_on_http_layer
     *
     * @return void 반환값 없음
     */
    public function test_attachment_service_does_not_depend_on_http_layer(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Services/PageAttachmentService.php');

        $this->assertStringNotContainsString('Http\\Requests', $source, 'Service 가 FormRequest 에 기대고 있습니다.');
        $this->assertStringNotContainsString('UploadPageAttachmentRequest', $source);
    }

    /**
     * 설정 키가 없을 때 업로드 검증과 공개 설정이 같은 폴백·상한을 쓴다.
     *
     * 수정 전 실패 이유: 공용 해석 지점(AttachmentLimits)이 없었다 — 두 계층이 각자 해석했다.
     *
     * @scenario case=shared_resolution
     *
     * @effects upload_rules_and_public_limits_share_one_resolution
     *
     * @return void 반환값 없음
     */
    public function test_upload_rules_and_public_limits_share_one_resolution(): void
    {
        // allowed_types 키 없음 → 폴백, 용량은 기본값과 다른 값으로 판정
        $this->setAttachmentSettings(['max_count' => 0, 'max_size_mb' => 3]);

        $limits = $this->app->make(PageAttachmentService::class)->publicLimits();
        $rules = $this->uploadFileRules();

        $this->assertSame(AttachmentLimits::FALLBACK_ALLOWED_TYPES, $limits['allowed_types']);
        $this->assertContains('mimetypes:'.implode(',', AttachmentLimits::FALLBACK_ALLOWED_TYPES), $rules);

        $this->assertSame(3, $limits['max_size_mb']);
        $this->assertContains('max:'.(3 * 1024), $rules);

        $this->assertNull($limits['max_count'], '0 이하는 제한 없음(null)으로 알려야 합니다.');
    }

    /**
     * 허용 형식을 명시적으로 비우면 양쪽 모두 형식을 제한하지 않는다.
     *
     * @scenario case=shared_resolution
     *
     * @effects empty_allowed_types_is_unrestricted_on_both_layers
     *
     * @return void 반환값 없음
     */
    public function test_empty_allowed_types_is_unrestricted_on_both_layers(): void
    {
        $this->setAttachmentSettings(['max_count' => 4, 'max_size_mb' => 10, 'allowed_types' => []]);

        $limits = $this->app->make(PageAttachmentService::class)->publicLimits();
        $rules = $this->uploadFileRules();

        $this->assertSame([], $limits['allowed_types']);
        $this->assertSame('', $limits['accept']);
        $this->assertSame(4, $limits['max_count']);

        foreach ($rules as $rule) {
            $this->assertStringStartsNotWith('mimetypes:', (string) $rule, '빈 목록에 형식 규칙을 붙이면 전 파일이 거부됩니다.');
        }
    }
}
