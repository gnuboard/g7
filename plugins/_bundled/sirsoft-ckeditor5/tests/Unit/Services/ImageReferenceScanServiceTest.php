<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Services\ImageReferenceScanService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 업로드 이미지 참조 판정 테스트
 *
 * 본문에 박히는 URL 이 두 형태(해시 API 폴백형 / 디스크 직접 URL)라 판정 토큰도 둘입니다.
 * 한 형태만 검사하면 다른 형태로 저장된 이미지를 "미참조" 로 오판해 삭제하게 되므로,
 * 두 변종 모두를 회귀로 고정합니다.
 */
class ImageReferenceScanServiceTest extends PluginTestCase
{
    private ImageReferenceScanService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ImageReferenceScanService::class);
    }

    /**
     * 업로드 기록을 생성합니다.
     *
     * @param  string  $hash  이미지 해시
     * @param  string  $filename  저장 파일명
     * @return Ckeditor5ImageUpload 생성된 기록
     */
    private function makeUpload(string $hash = 'abcdef012345', string $filename = 'aaaaaaaa-bbbb.png'): Ckeditor5ImageUpload
    {
        return Ckeditor5ImageUpload::create([
            'hash' => $hash,
            'original_name' => 'sample.png',
            'file_path' => "images/2026/08/14/{$filename}",
            'storage_disk' => 'plugins',
            'file_size' => 100,
            'mime_type' => 'image/png',
        ]);
    }

    /**
     * 코어 소유 참조 소스(메일 템플릿 본문)에 임의 내용을 담은 행을 만듭니다.
     *
     * @param  string  $content  본문
     */
    private function seedCoreSourceContent(string $content): void
    {
        DB::table('mail_templates')->insert([
            'type' => 'reference-scan-test-'.uniqid(),
            'subject' => json_encode(['ko' => '참조 스캔 테스트']),
            'body' => $content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @effects reference_sources_default_core_only
     */
    #[Test]
    public function default_sources_are_core_only_when_no_extension_registers(): void
    {
        $tables = array_column($this->service->getReferenceSources(), 'table');

        $this->assertContains('template_layouts', $tables);
        $this->assertContains('notification_templates', $tables);
        $this->assertNotContains('board_posts', $tables, '훅 미등록 상태에서는 모듈 소스가 포함되지 않아야 한다.');
    }

    /**
     * @effects reference_sources_hook_append
     */
    #[Test]
    public function extensions_can_append_sources_through_the_filter_hook(): void
    {
        HookManager::addFilter(
            ImageReferenceScanService::FILTER_REFERENCE_SOURCES,
            fn (array $sources) => [...$sources, ['table' => 'pages', 'columns' => ['content']]],
        );

        $tables = array_column(app(ImageReferenceScanService::class)->getReferenceSources(), 'table');

        $this->assertContains('pages', $tables);
    }

    /**
     * @effects reference_match_hash_api_url
     */
    #[Test]
    public function hash_form_url_in_content_counts_as_referenced(): void
    {
        $upload = $this->makeUpload(hash: 'a1b2c3d4e5f6');
        $this->seedCoreSourceContent('<img src="/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6">');

        $this->assertTrue($this->service->isReferenced($upload));
    }

    /**
     * @effects reference_match_direct_url
     */
    #[Test]
    public function direct_disk_url_in_content_counts_as_referenced(): void
    {
        $upload = $this->makeUpload(hash: 'ffffffffffff', filename: '9f8e7d6c-1234.webp');
        $this->seedCoreSourceContent('<img src="https://cdn.test/assets/ckeditor5/images/2026/08/14/9f8e7d6c-1234.webp">');

        $this->assertTrue(
            $this->service->isReferenced($upload),
            '직접 URL 은 해시를 포함하지 않으므로 저장 파일명으로 매칭되어야 한다.'
        );
    }

    /**
     * @effects reference_unreferenced
     */
    #[Test]
    public function content_without_the_image_leaves_it_unreferenced(): void
    {
        $upload = $this->makeUpload(hash: '0123456789ab', filename: 'unused-file.png');
        $this->seedCoreSourceContent('<p>이미지가 없는 본문</p>');

        $this->assertFalse($this->service->isReferenced($upload));
    }

    /**
     * LIKE 와일드카드가 들어간 파일명이 임의 문자로 해석되면 무관한 본문이 매칭된다.
     *
     * @effects reference_like_escape
     */
    #[Test]
    public function like_wildcards_in_the_filename_do_not_match_arbitrary_content(): void
    {
        $upload = $this->makeUpload(hash: 'cafebabe1234', filename: 'a%b_c.png');
        $this->seedCoreSourceContent('<img src="/files/aXXXbYc.png">');

        $this->assertFalse(
            $this->service->isReferenced($upload),
            '% 와 _ 는 이스케이프되어 리터럴로 비교되어야 한다.'
        );
    }

    /**
     * 불량 선언은 건너뛰되 **반드시 흔적을 남긴다**.
     *
     * 조용히 건너뛰면 확장이 등록한 소스가 오타 하나로 빠져도 아무도 모른 채 그 확장의
     * 콘텐츠에 박힌 이미지가 "미참조" 로 분류된다 — 삭제 방향의 오판이라 경고가 유일한
     * 발견 통로다. skip 동작과 경고를 함께 고정한다.
     *
     * @effects reference_invalid_source_skipped, reference_invalid_source_warns
     */
    #[Test]
    public function invalid_source_declarations_are_skipped_without_breaking_the_scan(): void
    {
        Log::spy();

        HookManager::addFilter(
            ImageReferenceScanService::FILTER_REFERENCE_SOURCES,
            fn (array $sources) => [
                ...$sources,
                ['table' => 'no_such_table_here', 'columns' => ['content']],
                ['table' => 'template_layouts', 'columns' => ['no_such_column']],
                ['columns' => ['content']],
            ],
        );

        $service = app(ImageReferenceScanService::class);
        $tables = array_column($service->getReferenceSources(), 'table');

        $this->assertNotContains('no_such_table_here', $tables);
        $this->assertContains('template_layouts', $tables);

        // 형식 자체가 깨진 선언(table 키 없음) 1건
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, '형식이 올바르지 않아'))
            ->once();

        // 실재하지 않는 테이블 1건 + 실재하지 않는 컬럼 1건
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, '존재하는 테이블/컬럼이 없어'))
            ->twice();

        // 불량 선언이 섞여도 판정 자체는 정상 동작해야 한다.
        $upload = $this->makeUpload(hash: 'deadbeef0000', filename: 'skip-test.png');
        $this->seedCoreSourceContent('<img src="/api/plugins/sirsoft-ckeditor5/images/deadbeef0000">');

        $this->assertTrue($service->isReferenced($upload));
    }

    /**
     * @effects reference_map_batch
     */
    #[Test]
    public function map_referenced_reports_each_upload_separately(): void
    {
        $referenced = $this->makeUpload(hash: '111111111111', filename: 'used.png');
        $unreferenced = $this->makeUpload(hash: '222222222222', filename: 'unused.png');

        $this->seedCoreSourceContent('<img src="/api/plugins/sirsoft-ckeditor5/images/111111111111">');

        $map = $this->service->mapReferenced(collect([$referenced, $unreferenced]));

        $this->assertTrue($map[$referenced->id]);
        $this->assertFalse($map[$unreferenced->id]);
    }
}
