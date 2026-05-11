<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Unit\Services;

use Modules\Gnuboard7\HelloModule\Models\Memo;
use Modules\Gnuboard7\HelloModule\Services\MemoService;
use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * MemoService 단위 테스트
 *
 * 메모 생성/수정/삭제 Pure Logic 을 검증합니다.
 */
class MemoServiceTest extends ModuleTestCase
{
    private MemoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MemoService::class);
    }

    /**
     * 메모를 생성할 수 있는지 확인합니다.
     */
    public function test_create_memo_persists_record(): void
    {
        $memo = $this->service->createMemo([
            'title' => '테스트 제목',
            'content' => '테스트 본문',
        ]);

        $this->assertInstanceOf(Memo::class, $memo);
        $this->assertNotNull($memo->uuid);
        $this->assertEquals('테스트 제목', $memo->title);

        $this->assertDatabaseHas('gnuboard7_hello_module_memos', [
            'id' => $memo->id,
            'title' => '테스트 제목',
        ]);
    }

    /**
     * 메모를 수정할 수 있는지 확인합니다.
     */
    public function test_update_memo_changes_fields(): void
    {
        $memo = $this->service->createMemo([
            'title' => '원본',
            'content' => '원본 본문',
        ]);

        $updated = $this->service->updateMemo($memo, [
            'title' => '수정됨',
            'content' => '수정된 본문',
        ]);

        $this->assertEquals('수정됨', $updated->title);
        $this->assertEquals('수정된 본문', $updated->content);
    }

    /**
     * 메모를 삭제할 수 있는지 확인합니다.
     */
    public function test_delete_memo_removes_record(): void
    {
        $memo = $this->service->createMemo([
            'title' => '삭제 대상',
            'content' => '본문',
        ]);

        $result = $this->service->deleteMemo($memo);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('gnuboard7_hello_module_memos', [
            'id' => $memo->id,
        ]);
    }
}
