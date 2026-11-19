<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 활동 로그 리스너 테스트 (매니페스트 listeners.yaml — 활동 로그 케이스)
 *
 * 관리자 API 를 실제로 불러 훅 → 리스너 → activity_logs 행까지 확인한다(리스너를 모의하지 않는다). 일괄은 건마다 한 줄.
 */
class PopupActivityLogListenerTest extends PluginTestCase
{
    /**
     * 설명 키 접두
     */
    private const KEY = 'sirsoft-popup::activity_log.description.';

    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
    }

    /**
     * 등록을 기록한다(관리용 제목 스냅샷).
     *
     * @scenario surface=log_create
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_create(): void
    {
        $id = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload())->assertCreated()->json('data.id');

        $log = $this->logs('popup.create')->firstWhere('loggable_id', $id);
        $this->assertNotNull($log);
        $this->assertSame(Popup::class, $log->loggable_type);
        $this->assertSame(self::KEY.'popup_create', $log->description_key);
        $this->assertSame('10월 정기 시스템 점검 안내', json_decode((string) $log->properties, true)['name']);
    }

    /**
     * 수정은 바뀐 필드를 changes 에 남긴다.
     *
     * @scenario surface=log_update_with_changes
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_update_with_changes(): void
    {
        $popup = Popup::factory()->html()->create(['audience' => 'all']);

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->htmlPopupPayload(['audience' => 'member']))->assertOk();

        $log = $this->logs('popup.update')->firstWhere('loggable_id', $popup->id);
        $this->assertNotNull($log);
        $this->assertStringContainsString('audience', (string) $log->changes);
    }

    /**
     * 삭제는 지워진 팝업 id 와 제목 스냅샷으로 남는다.
     *
     * @scenario surface=log_delete_with_snapshot
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_delete_with_snapshot(): void
    {
        $popup = Popup::factory()->html()->create(['title' => '지울 팝업']);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$popup->id))->assertOk();

        $log = $this->logs('popup.delete')->firstWhere('loggable_id', $popup->id);
        $this->assertNotNull($log);
        $this->assertSame('지울 팝업', json_decode((string) $log->properties, true)['name']);
    }

    /**
     * 일괄 노출·비노출은 팝업마다 한 줄씩 남는다.
     *
     * @scenario surface=log_bulk_status_per_item
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_bulk_status_per_item(): void
    {
        [$a, $b] = Popup::factory()->count(2)->create();

        $this->actingAs($this->admin)->patchJson($this->api('/admin/popups/bulk-status'), ['ids' => [$a->id, $b->id], 'is_active' => false])->assertOk();

        $rows = $this->logs('popup.bulk_status')->sortBy('loggable_id')->values();
        $this->assertSame([$a->id, $b->id], $rows->pluck('loggable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(self::KEY.'popup_bulk_hide', $rows[0]->description_key);
    }

    /**
     * 일괄 복사는 복사본마다 한 줄씩, 원본 id 와 함께 남는다.
     *
     * @scenario surface=log_bulk_copy_per_item
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_bulk_copy_per_item(): void
    {
        [$a, $b] = Popup::factory()->html()->count(2)->create();

        $copies = $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$a->id, $b->id]])->assertOk()->json('data.copies');

        $rows = $this->logs('popup.bulk_copy');
        $this->assertCount(2, $rows);
        $row = $rows->firstWhere('loggable_id', $copies[$a->id]);
        $this->assertNotNull($row);
        $this->assertSame($a->id, json_decode((string) $row->description_params, true)['source_id']);
    }

    /**
     * 일괄 삭제는 팝업마다 한 줄씩 남는다.
     *
     * @scenario surface=log_bulk_delete_per_item
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_bulk_delete_per_item(): void
    {
        [$a, $b] = Popup::factory()->html()->count(2)->create();

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/bulk'), ['ids' => [$a->id, $b->id]])->assertOk();

        $this->assertSame([$a->id, $b->id], $this->logs('popup.bulk_delete')->pluck('loggable_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
    }

    /**
     * 순서 변경은 한 줄(건수)로 남는다.
     *
     * @scenario surface=log_reorder_single
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_reorder_single(): void
    {
        $ids = Popup::factory()->count(3)->create()->pluck('id')->reverse()->values()->all();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/order'), ['ids' => $ids])->assertOk();

        $rows = $this->logs('popup.reorder');
        $this->assertCount(1, $rows);
        $this->assertSame(3, json_decode((string) $rows[0]->description_params, true)['count']);
    }

    /**
     * 수정 저장으로 바뀐 이미지를 기록한다(업로드만으로는 남지 않는다).
     *
     * @scenario surface=log_image_update
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_log_image_update(): void
    {
        $id = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $tempKey = (string) Str::uuid();
        $newId = $this->uploadImage($this->admin, 'pc', $tempKey)->assertCreated()->json('data.id');
        $this->assertCount(0, $this->logs('popup.image_update'), '업로드만으로는 남지 않는다');

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $this->popupPayload(['temp_key' => $tempKey, 'pc_image_id' => $newId]))->assertOk();

        $log = $this->logs('popup.image_update')->firstWhere('loggable_id', $id);
        $this->assertNotNull($log);
        $this->assertSame(self::KEY.'popup_image_update', $log->description_key);
    }

    /**
     * 그 동작의 활동 로그 행을 돌려줍니다.
     *
     * @param  string  $action  동작
     * @return Collection<int, object> 로그 행
     */
    private function logs(string $action): Collection
    {
        return DB::table('activity_logs')->where('action', $action)->orderBy('id')->get();
    }
}
