<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 임시 첨부 키 경로 순회 방어 테스트 (KVE-2026-2349 부수의무)
 *
 * 페이지 첨부는 임시 키를 basePath 없이 `temp/{temp_key}` 저장 경로에 넣고, 페이지 저장 시
 * 그 임시 디렉토리를 지운다. 게시판보다 한 단계 얕아 순회 키가 모듈 저장소 루트에 더 쉽게
 * 닿았다. 요청 계층이 UUID 가 아닌 키를 422 로 거부하는지, 임시 첨부가 없으면 정리하지
 * 않는지, 정상 흐름(UUID 키 업로드 → 연결)과 키 없는 업로드가 그대로인지 확인한다.
 */
class TempKeyPathSafetyTest extends FeatureTestCase
{
    /**
     * 경로 순회·인코딩 임시 키 변종
     *
     * @var array<int, string>
     */
    private const TRAVERSAL_KEYS = ['../..', '..%2f..', 'a/../../..', '\\', '.'];

    /**
     * 페이지 관리 권한 관리자
     */
    private User $admin;

    /**
     * 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
        ]);
    }

    /**
     * 페이지 생성·수정 요청의 순회 임시 키는 검증 오류로 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=page_store,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422
     */
    public function test_page_store_and_update_reject_traversal_temp_keys(): void
    {
        $page = Page::factory()->create(['slug' => 'temp-key-guard-page']);

        try {
            foreach (self::TRAVERSAL_KEYS as $tempKey) {
                $this->actingAs($this->admin)
                    ->postJson('/api/modules/sirsoft-page/admin/pages', ['temp_key' => $tempKey])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('temp_key');

                $this->actingAs($this->admin)
                    ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", ['temp_key' => $tempKey])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('temp_key');
            }
        } finally {
            $page->forceDelete();
        }
    }

    /**
     * 첨부 업로드의 순회 임시 키는 422 로 거부되고 첨부가 만들어지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=upload,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422
     */
    public function test_upload_rejects_traversal_temp_keys(): void
    {
        $before = PageAttachment::query()->count();

        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                    'file' => UploadedFile::fake()->create('probe.pdf', 10, 'application/pdf'),
                    'temp_key' => $tempKey,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');
        }

        $this->assertSame($before, PageAttachment::query()->count());
    }

    /**
     * 연결할 임시 첨부가 없는 UUID 키로 연결하면 0 을 돌려주고 임시 디렉토리를 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=page_store,temp_key_shape=uuid
     *
     * @effects empty_temp_link_deletes_nothing
     */
    public function test_link_without_temp_attachments_deletes_nothing(): void
    {
        $tempKey = (string) Str::uuid();
        [$service, $storage] = $this->serviceWithStorage();
        $storage->put('attachments', "temp/{$tempKey}/stray.pdf", 'x');

        try {
            $this->assertSame(0, $service->linkTempAttachmentsWithMove($tempKey, 999999));
            $this->assertTrue($storage->exists('attachments', "temp/{$tempKey}/stray.pdf"));
        } finally {
            $storage->deleteDirectory('attachments', "temp/{$tempKey}");
        }
    }

    /**
     * UUID 키로 올린 임시 첨부는 페이지에 연결·이동되고 임시 디렉토리가 정리된다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=upload,temp_key_shape=uuid
     *
     * @effects uuid_temp_attachment_linked_and_temp_dir_cleaned
     */
    public function test_uuid_temp_upload_is_linked_and_temp_dir_cleaned(): void
    {
        $tempKey = (string) Str::uuid();

        $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                // 내용이 빈 가짜 파일은 서비스가 옮기지 않으므로(get 결과가 비면 이동 생략) 내용을 채운다
                'file' => UploadedFile::fake()->createWithContent('linked.pdf', "%PDF-1.4\n%temp-key-guard\n"),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(201);

        [$service, $storage] = $this->serviceWithStorage();
        $attachment = PageAttachment::query()->where('temp_key', $tempKey)->firstOrFail();
        $this->assertTrue($storage->exists('attachments', $attachment->path));

        $page = Page::factory()->create(['slug' => 'temp-key-guard-linked']);

        try {
            $this->assertSame(1, $service->linkTempAttachmentsWithMove($tempKey, $page->id));

            $attachment->refresh();
            $this->assertSame($page->id, $attachment->page_id);
            $this->assertTrue($storage->exists('attachments', $attachment->path));
            $this->assertSame([], $storage->files('attachments', "temp/{$tempKey}"));
        } finally {
            $storage->delete('attachments', $attachment->path);
            $page->forceDelete();
        }
    }

    /**
     * 페이지도 임시 키도 없이 올린 첨부는 서버가 만든 UUID 키의 임시 경로에 저장된다.
     *
     * @return void 반환값 없음
     *
     * @effects keyless_upload_gets_server_generated_temp_key
     */
    public function test_keyless_upload_gets_server_generated_temp_key(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => UploadedFile::fake()->create('keyless.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(201);

        $attachment = PageAttachment::query()->latest('id')->firstOrFail();
        [, $storage] = $this->serviceWithStorage();

        try {
            $this->assertTrue(Str::isUuid((string) $attachment->temp_key));
            $this->assertStringStartsWith("temp/{$attachment->temp_key}/", $attachment->path);
            $this->assertStringNotContainsString('//', $attachment->path);
        } finally {
            $storage->deleteDirectory('attachments', "temp/{$attachment->temp_key}");
        }
    }

    /**
     * 페이지 첨부 서비스와 그 서비스가 쓰는 스토리지 드라이버를 반환합니다.
     *
     * @return array{0: PageAttachmentService, 1: StorageInterface} 서비스와 스토리지
     */
    private function serviceWithStorage(): array
    {
        $service = app(PageAttachmentService::class);

        return [$service, (new \ReflectionProperty($service, 'storage'))->getValue($service)];
    }
}
