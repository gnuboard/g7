<?php

namespace Tests\Feature\Attachment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 설정 첨부 칸 업로드 정책 테스트 (#434)
 *
 * 파비콘이 아닌 템플릿 설정 첨부 칸(`template_settings.{템플릿}.{섹션}.{키}`)은 선언 `accept`(이미지 칸은 이미지 기본 목록)와
 * 전역 허용 목록의 교집합만 받고, 선언 `max_size_kb` 를 넘으면 거부한다.
 *
 * 선언에 없는 칸·없는 템플릿·교집합이 빈 칸의 컬렉션은 아무것도 받지 않는다 — 허용 확장자 훅의 빈 목록은 업로드 요청이
 * 「제한 없음」으로 읽으므로 리스너가 업로드 규칙에 거부 규칙을 더한다(#434 시험 보강 중 발견해 고친 결함).
 */
class TemplateSettingsUploadPolicyTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 선언에 첨부 칸(배너, png·webp·10KB)을 더한 템플릿을 만들고, 전역 허용 목록을 png·jpg·pdf 로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $declaration = $this->defaultDeclaration();
        $declaration['schema']['display']['banner'] = ['type' => 'attachments', 'max_files' => 2, 'accept' => ['png', 'webp'], 'max_size_kb' => 10];
        $declaration['defaults']['display']['banner'] = [];
        $this->makeSettingsTemplate('qa434-up', 'user', 'inactive', $declaration);

        config(['attachment.allowed_extensions' => ['png', 'jpg', 'pdf']]);
    }

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 업로드 요청을 보냅니다.
     *
     * @param  UploadedFile  $file  파일
     * @param  string  $collection  컬렉션
     * @return \Illuminate\Testing\TestResponse 응답
     */
    private function upload(UploadedFile $file, string $collection)
    {
        return $this->actingAs($this->makeAdmin(['core.templates.settings', 'core.attachments.create']))
            ->postJson('/api/admin/attachments', ['file' => $file, 'collection' => $collection]);
    }

    /**
     * 선언 `accept` 와 전역 허용 목록의 교집합만 받는다 — 선언에만 있는 webp, 전역에만 있는 jpg 는 422.
     *
     * @scenario case=api
     *
     * @effects template_field_upload_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_declared_accept_intersects_global_allow_list(): void
    {
        $collection = 'template_settings.qa434-up.display.banner';

        $this->upload(UploadedFile::fake()->image('a.png', 8, 8), $collection)->assertCreated();
        $this->assertSame(422, $this->upload(UploadedFile::fake()->image('a.webp', 8, 8), $collection)->status(), '전역 목록에 없는 webp');
        $this->assertSame(422, $this->upload(UploadedFile::fake()->image('a.jpg', 8, 8), $collection)->status(), '선언 accept 에 없는 jpg');
    }

    /**
     * 선언 `max_size_kb` 를 넘는 파일은 422, 그 안이면 통과.
     *
     * @scenario case=api
     *
     * @effects template_field_upload_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_declared_max_size_is_enforced(): void
    {
        $collection = 'template_settings.qa434-up.display.banner';

        $this->upload(UploadedFile::fake()->image('small.png', 8, 8)->size(5), $collection)->assertCreated();
        $this->upload(UploadedFile::fake()->image('big.png', 8, 8)->size(20), $collection)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file'], 'errors');
    }

    /**
     * 이미지 칸(다크 로고)은 업로드 경로에서 이미지가 아닌 파일을 거부한다 — 전역 목록에 pdf 가 있어도.
     *
     * @scenario case=api
     *
     * @effects template_field_upload_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_image_field_rejects_non_image_upload(): void
    {
        $collection = 'template_settings.qa434-up.branding.logo_dark';

        $this->assertSame(422, $this->upload(UploadedFile::fake()->create('doc.pdf', 4, 'application/pdf'), $collection)->status(), '이미지 칸에 pdf');
        $this->upload(UploadedFile::fake()->image('logo.png', 8, 8), $collection)->assertCreated();
    }

    /**
     * 선언에 없는 칸의 컬렉션(`template_settings.{템플릿}.{섹션}.{없는 키}`)은 전역 허용 형식도 받지 않는다.
     *
     * @scenario case=api
     *
     * @effects template_field_upload_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_undeclared_field_collection_is_rejected(): void
    {
        $this->assertSame(422, $this->upload(UploadedFile::fake()->image('a.png', 8, 8), 'template_settings.qa434-up.display.nope')->status());
        $this->assertSame(422, $this->upload(UploadedFile::fake()->image('a.png', 8, 8), 'template_settings.qa434-none.display.banner')->status(), '없는 템플릿');
    }
}
