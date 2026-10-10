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
 * 아직 페이지에 연결되지 않은 임시 첨부의 순서 변경 테스트
 *
 * 순서 변경은 대상 첨부마다 부모 페이지 스코프를 다시 검사한다. 임시 첨부(page_id 없음)는 부모가 없으므로
 * 올린 사람만 다룰 수 있다. 다른 관리자가 남의 임시 첨부를 순서 변경하면 서버 오류(500)가 아니라 403 으로
 * 거절하고, 대상 중 하나라도 범위 밖이면 아무것도 바꾸지 않는다.
 *
 * 컨트롤러는 FileUploader 의 `[{id, order}]` 를 `[ID => order]` 매핑으로 바꿔 서비스에 넘기는데, 스코프
 * 검사가 `{id, order}` 목록 모양을 가정해 ID 를 하나도 읽지 못하고 검사 없이 통과시켰다(실측 2026-10-09).
 *
 * @scenario-manifest tests/scenarios/temp-attachment-reorder.yaml
 */
class TempAttachmentReorderTest extends FeatureTestCase
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
     * @param  int  $order  순서
     * @return PageAttachment 임시 첨부
     */
    private function makeTempAttachment(int $userId, int $order): PageAttachment
    {
        $tempKey = '00000000-0000-4000-8000-0000000008'.str_pad((string) ($userId % 100), 2, '0', STR_PAD_LEFT);

        return PageAttachment::create([
            'page_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => "temp-upload-{$order}.png",
            'stored_filename' => "stored-temp-{$order}.png",
            'disk' => 'local',
            'path' => 'temp/'.$tempKey."/stored-temp-{$order}.png",
            'mime_type' => 'image/png',
            'size' => 161,
            'collection' => 'page_attachments',
            'order' => $order,
            'created_by' => $userId,
        ]);
    }

    /**
     * 순서 변경 요청을 보낸다.
     *
     * @param  User  $actor  요청 관리자
     * @param  array<int, int>  $orders  [첨부 ID => 순서]
     * @return \Illuminate\Testing\TestResponse 응답
     */
    private function reorderAs(User $actor, array $orders)
    {
        $payload = [];
        foreach ($orders as $id => $order) {
            $payload[] = ['id' => $id, 'order' => $order];
        }

        return $this->actingAs($actor)
            ->patchJson('/api/modules/sirsoft-page/admin/attachments/reorder', ['order' => $payload]);
    }

    /**
     * 올린 사람은 자기 임시 첨부의 순서를 바꿀 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=uploader_own_only
     *
     * @effects own_temp_attachments_reordered
     */
    public function test_uploader_can_reorder_own_temp_attachments(): void
    {
        $first = $this->makeTempAttachment($this->uploader->id, 1);
        $second = $this->makeTempAttachment($this->uploader->id, 2);

        $response = $this->reorderAs($this->uploader, [$first->id => 2, $second->id => 1]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSame(2, (int) $first->fresh()->order);
        $this->assertSame(1, (int) $second->fresh()->order);
    }

    /**
     * 다른 관리자의 임시 첨부 순서는 바꿀 수 없고, 거절은 서버 오류가 아니라 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=other_admin_others_only
     *
     * @effects others_temp_attachment_reorder_rejected_with_403
     */
    public function test_other_admin_cannot_reorder_temp_attachments_and_gets_403(): void
    {
        $other = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.update',
        ]);
        $first = $this->makeTempAttachment($this->uploader->id, 1);
        $second = $this->makeTempAttachment($this->uploader->id, 2);

        $response = $this->reorderAs($other, [$first->id => 2, $second->id => 1]);

        $response->assertStatus(403);
        $this->assertSame(1, (int) $first->fresh()->order);
        $this->assertSame(2, (int) $second->fresh()->order);
    }

    /**
     * 자기 첨부와 남의 임시 첨부가 섞이면 요청 전체를 거부하고 아무것도 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=other_admin_mixed
     *
     * @effects mixed_reorder_rejected_without_partial_change
     */
    public function test_mixed_reorder_is_rejected_entirely(): void
    {
        $other = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
        ]);
        $own = $this->makeTempAttachment($other->id, 1);
        $foreign = $this->makeTempAttachment($this->uploader->id, 2);

        $response = $this->reorderAs($other, [$own->id => 2, $foreign->id => 1]);

        $response->assertStatus(403);
        $this->assertSame(1, (int) $own->fresh()->order);
        $this->assertSame(2, (int) $foreign->fresh()->order);
    }

    /**
     * 없는 첨부 ID 가 섞이면 서버 오류(500)가 아니라 404 로 끊고 아무것도 바꾸지 않는다.
     *
     * 범위 검사가 대상 첨부를 하나씩 찾으므로 없는 ID 는 찾기 단계에서 끊긴다. 삭제와 같은 응답이다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=missing_id
     *
     * @effects missing_attachment_id_returns_404
     */
    public function test_missing_attachment_id_returns_404(): void
    {
        $own = $this->makeTempAttachment($this->uploader->id, 1);

        $response = $this->reorderAs($this->uploader, [$own->id => 2, 99999999 => 1]);

        $response->assertStatus(404);
        $this->assertSame(1, (int) $own->fresh()->order);
    }
}
