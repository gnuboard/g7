<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Plugin;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 공개 캐시 비우기 테스트 (매니페스트 listeners.yaml — 캐시 케이스)
 *
 * 캐시를 켠 상태에서 DB 를 직접 바꾸면 캐시된 옛 값이 나오고, 각 변경 훅이 불리면 새 값이 나와야 한다.
 */
class PopupCacheListenerTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 캐시 10분.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $this->admin = $this->fullAdmin();
        app(PublicPopupService::class)->invalidate();
    }

    /**
     * 등록·수정 저장 뒤 캐시를 비운다.
     *
     * @scenario surface=cache_cleared_on_save
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_save(): void
    {
        $this->assertSame([], $this->ids());
        $id = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload())->assertCreated()->json('data.id');
        $this->assertSame([$id], $this->ids(), '등록 뒤');

        $this->staleUpdate($id, ['is_active' => false]);
        $this->assertSame([$id], $this->ids(), '직접 바꾸면 캐시가 남는다');
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $this->htmlPopupPayload(['is_active' => false]))->assertOk();
        $this->assertSame([], $this->ids(), '수정 뒤');
    }

    /**
     * 삭제 뒤 캐시를 비운다.
     *
     * @scenario surface=cache_cleared_on_delete
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_delete(): void
    {
        $popup = Popup::factory()->html()->create();
        $this->assertSame([$popup->id], $this->ids());

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/'.$popup->id))->assertOk();

        $this->assertSame([], $this->ids());
    }

    /**
     * 일괄 노출·비노출·삭제 뒤 캐시를 비운다.
     *
     * @scenario surface=cache_cleared_on_bulk_change
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_bulk_change(): void
    {
        [$a, $b] = Popup::factory()->html()->count(2)->create();
        $this->assertCount(2, $this->ids());

        $this->actingAs($this->admin)->patchJson($this->api('/admin/popups/bulk-status'), ['ids' => [$a->id], 'is_active' => false])->assertOk();
        $this->assertSame([$b->id], $this->ids(), '일괄 비노출 뒤');

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/popups/bulk'), ['ids' => [$b->id]])->assertOk();
        $this->assertSame([], $this->ids(), '일괄 삭제 뒤');
    }

    /**
     * 복사 뒤 캐시를 비운다(복사본은 비활성이라 노출은 같지만 순서·후보가 바뀐다).
     *
     * @scenario surface=cache_cleared_on_copy
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_copy(): void
    {
        $popup = Popup::factory()->html()->create();
        $this->ids();
        $fired = false;
        HookManager::addAction('sirsoft-popup.popup.after_bulk_copy', function () use (&$fired) {
            $fired = true;
        });
        $versionBefore = $this->version();

        $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$popup->id]])->assertOk();

        $this->assertTrue($fired);
        $this->assertGreaterThan($versionBefore, $this->version());
    }

    /**
     * 순서 변경 뒤 캐시를 비운다(캐러셀 순서가 바로 바뀐다).
     *
     * @scenario surface=cache_cleared_on_reorder
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_reorder(): void
    {
        $a = Popup::factory()->html()->create(['sort_order' => 0]);
        $b = Popup::factory()->html()->create(['sort_order' => 1]);
        $this->assertSame([$a->id, $b->id], $this->ids());

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/order'), ['ids' => [$b->id, $a->id]])->assertOk();

        $this->assertSame([$b->id, $a->id], $this->ids());
    }

    /**
     * 수정 저장으로 이미지가 바뀌면 캐시를 비운다(이미지 변경 훅).
     *
     * @scenario surface=cache_cleared_on_image_change
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_image_change(): void
    {
        $id = $this->createPopupViaApi($this->admin)->assertCreated()->json('data.id');
        $before = $this->rows()[0]['image']['pc']['url'];

        $tempKey = (string) Str::uuid();
        $newId = $this->uploadImage($this->admin, 'pc', $tempKey)->assertCreated()->json('data.id');
        $versionBefore = $this->version();
        HookManager::doAction('sirsoft-popup.popup_image.after_change', Popup::query()->find($id), 'pc', 'replaced');
        $this->assertGreaterThan($versionBefore, $this->version(), '이미지 변경 훅만으로도 비운다');

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$id), $this->popupPayload(['temp_key' => $tempKey, 'pc_image_id' => $newId]))->assertOk();
        $this->assertNotSame($before, $this->rows()[0]['image']['pc']['url']);
    }

    /**
     * 이 플러그인 설정(표시 방식·캐시 시간)을 저장하면 비우고, 다른 플러그인 설정은 건드리지 않는다.
     *
     * @scenario surface=cache_cleared_on_plugin_setting_change
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_plugin_setting_change(): void
    {
        $popup = Popup::factory()->html()->create();
        $this->assertSame([$popup->id], $this->ids());
        $this->staleUpdate($popup->id, ['is_active' => false]);

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other', [], true);
        $this->assertSame([$popup->id], $this->ids(), '다른 플러그인 설정은 캐시를 건드리지 않는다');

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-popup', [], true);
        $this->assertSame([], $this->ids());
    }

    /**
     * 플러그인 비활성화 때 캐시를 비운다.
     *
     * @scenario surface=cache_cleared_on_plugin_deactivate
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_plugin_deactivate(): void
    {
        $popup = Popup::factory()->html()->create();
        $this->ids();
        $this->staleUpdate($popup->id, ['is_active' => false]);
        $this->assertSame([$popup->id], $this->ids());

        $this->assertTrue((new Plugin(['identifier' => 'sirsoft-popup', 'vendor' => 'sirsoft', 'version' => '1.0.0']))->deactivate());

        $this->assertSame([], $this->ids());
    }

    /**
     * 플러그인 삭제(데이터 유지 포함) 때 캐시를 비운다 — 코어 삭제는 비활성화를 먼저 거치지 않을 수 있다.
     *
     * @scenario surface=cache_cleared_on_plugin_uninstall
     *
     * @effects public_cache_cleared
     *
     * @return void 반환값 없음
     */
    public function test_cleared_on_plugin_uninstall(): void
    {
        $popup = Popup::factory()->html()->create();
        $this->ids();
        $this->staleUpdate($popup->id, ['is_active' => false]);

        HookManager::doAction('core.plugins.before_uninstall', 'sirsoft-other', false);
        $this->assertSame([$popup->id], $this->ids(), '다른 플러그인 삭제는 캐시를 건드리지 않는다');

        HookManager::doAction('core.plugins.before_uninstall', 'sirsoft-popup', false);
        $this->assertSame([], $this->ids());
    }

    /**
     * 손님·한국어 공개 응답의 팝업 id 를 돌려줍니다.
     *
     * @return array<int, int> 팝업 id
     */
    private function ids(): array
    {
        return array_column($this->rows(), 'id');
    }

    /**
     * 손님·한국어 공개 응답의 팝업 목록을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 팝업
     */
    private function rows(): array
    {
        return app(PublicPopupService::class)->forRequest(null, 'ko')['popups'];
    }

    /**
     * 캐시를 거치지 않고 DB 만 바꿉니다(캐시가 남는지 보려고).
     *
     * @param  int  $id  팝업 ID
     * @param  array<string, mixed>  $values  바꿀 값
     * @return void 반환값 없음
     */
    private function staleUpdate(int $id, array $values): void
    {
        DB::table('popups')->where('id', $id)->update($values);
    }

    /**
     * 공개 캐시 버전을 읽습니다.
     *
     * @return int 버전
     */
    private function version(): int
    {
        $service = app(PublicPopupService::class);
        $cache = (new \ReflectionProperty(PublicPopupService::class, 'cache'))->getValue($service);

        return (int) ($cache->get('popup:public:version') ?? 0);
    }
}
