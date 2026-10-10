<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Admin;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Mockery;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 아직 페이지에 연결되지 않은 임시 첨부 삭제 테스트
 *
 * 작성 화면은 [저장]을 누를 때 첨부를 먼저 올리고 페이지를 만든다. 제목을 비우는 등으로 페이지 저장이
 * 422 로 실패하면 첨부는 임시 첨부(page_id 없음)로 남고 업로더 목록에 그대로 보인다. 이 첨부를 지우면
 * 부모 페이지가 없다는 이유로 스코프 검사가 막고, 그 거절이 500 「첨부파일 삭제에 실패했습니다」로
 * 나갔다. 임시 첨부는 올린 사람만 지울 수 있고, 거절은 403 이어야 한다.
 *
 * @scenario-manifest tests/scenarios/temp-attachment-delete.yaml
 */
class TempAttachmentDeleteTest extends FeatureTestCase
{
    protected User $uploader;

    /**
     * 테스트 환경 설정 — 첨부 권한을 가진 관리자와 파일 저장소 대역
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->uploader = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
        ]);

        $storageMock = Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('exists')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $this->app->instance(StorageInterface::class, $storageMock);
    }

    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 주어진 사용자가 올린 임시 첨부(페이지 미연결)를 만든다.
     *
     * @param  int  $userId  올린 사용자 ID
     * @return PageAttachment 임시 첨부
     */
    private function makeTempAttachment(int $userId): PageAttachment
    {
        $tempKey = '00000000-0000-4000-8000-0000000007'.str_pad((string) $userId % 100, 2, '0', STR_PAD_LEFT);

        return PageAttachment::create([
            'page_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => 'temp-upload.png',
            'stored_filename' => 'stored-temp.png',
            'disk' => 'local',
            'path' => 'temp/'.$tempKey.'/stored-temp.png',
            'mime_type' => 'image/png',
            'size' => 161,
            'collection' => 'page_attachments',
            'order' => 1,
            'created_by' => $userId,
        ]);
    }

    /**
     * 올린 사람은 자기 임시 첨부를 지울 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario actor=uploader
     *
     * @effects own_temp_attachment_deleted
     */
    public function test_uploader_can_delete_own_temp_attachment(): void
    {
        $attachment = $this->makeTempAttachment($this->uploader->id);

        $response = $this->actingAs($this->uploader)
            ->deleteJson("/api/modules/sirsoft-page/admin/attachments/{$attachment->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseMissing('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * 다른 관리자의 임시 첨부는 지울 수 없고, 거절은 서버 오류가 아니라 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario actor=other_admin
     *
     * @effects others_temp_attachment_rejected_with_403
     */
    public function test_other_admin_cannot_delete_temp_attachment_and_gets_403(): void
    {
        $other = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.update',
        ]);
        $attachment = $this->makeTempAttachment($this->uploader->id);

        $response = $this->actingAs($other)
            ->deleteJson("/api/modules/sirsoft-page/admin/attachments/{$attachment->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('page_attachments', ['id' => $attachment->id]);
    }
}
