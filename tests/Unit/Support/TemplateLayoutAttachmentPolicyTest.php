<?php

namespace Tests\Unit\Support;

use App\Extension\HookManager;
use App\Support\TemplateLayoutAttachmentPolicy;
use Tests\TestCase;

/**
 * 레이아웃 첨부 허용 확장자의 우선순위 — 템플릿 설정(필터 훅) > 코어 설정 > 기본값.
 *
 * @scenario-manifest tests/scenarios/upload-stored-extension.yaml
 */
class TemplateLayoutAttachmentPolicyTest extends TestCase
{
    /**
     * 시험이 건 필터를 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(TemplateLayoutAttachmentPolicy::FILTER_HOOK);

        parent::tearDown();
    }

    /**
     * 코어 설정이 비어 있으면 기본 목록을 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_default
     *
     * @effects layout_allowlist_priority_template_then_core_then_default
     */
    public function test_falls_back_to_defaults_when_core_setting_is_empty(): void
    {
        config(['attachment.layout_attachment_extensions' => []]);

        $this->assertSame(
            TemplateLayoutAttachmentPolicy::DEFAULT_EXTENSIONS,
            TemplateLayoutAttachmentPolicy::allowedExtensions('sirsoft-basic')
        );
    }

    /**
     * 코어 설정이 있으면 기본값 대신 그 목록을 쓴다(문자열·대문자·점도 정규화).
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_core_setting
     *
     * @effects layout_allowlist_priority_template_then_core_then_default
     */
    public function test_core_setting_replaces_defaults(): void
    {
        config(['attachment.layout_attachment_extensions' => 'PNG, .pdf']);

        $this->assertSame(['png', 'pdf'], TemplateLayoutAttachmentPolicy::allowedExtensions('sirsoft-basic'));
    }

    /**
     * 필터 훅은 템플릿 식별자를 받고, 돌려준 목록이 코어 설정보다 우선한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_template_filter
     *
     * @effects layout_allowlist_priority_template_then_core_then_default
     */
    public function test_template_filter_wins_over_core_setting_and_receives_the_identifier(): void
    {
        config(['attachment.layout_attachment_extensions' => ['png']]);

        $seen = [];
        HookManager::addFilter(
            TemplateLayoutAttachmentPolicy::FILTER_HOOK,
            function (array $extensions, string $identifier) use (&$seen) {
                $seen[] = [$extensions, $identifier];

                return $identifier === 'vendor-special' ? ['WEBP', 'pdf'] : $extensions;
            }
        );

        $this->assertSame(['webp', 'pdf'], TemplateLayoutAttachmentPolicy::allowedExtensions('vendor-special'));
        $this->assertSame(['png'], TemplateLayoutAttachmentPolicy::allowedExtensions('sirsoft-basic'));
        $this->assertSame([['png'], 'vendor-special'], $seen[0]);
    }

    /**
     * 필터가 빈 목록(또는 목록이 아닌 값)을 돌려주면 코어 값으로 되돌린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_template_filter
     *
     * @effects layout_allowlist_priority_template_then_core_then_default
     */
    public function test_empty_filter_result_falls_back_to_core_value(): void
    {
        config(['attachment.layout_attachment_extensions' => ['png', 'gif']]);

        HookManager::addFilter(TemplateLayoutAttachmentPolicy::FILTER_HOOK, fn () => []);
        $this->assertSame(['png', 'gif'], TemplateLayoutAttachmentPolicy::allowedExtensions('sirsoft-basic'));

        HookManager::clearFilter(TemplateLayoutAttachmentPolicy::FILTER_HOOK);
        HookManager::addFilter(TemplateLayoutAttachmentPolicy::FILTER_HOOK, fn () => null);
        $this->assertSame(['png', 'gif'], TemplateLayoutAttachmentPolicy::allowedExtensions('sirsoft-basic'));
    }
}
