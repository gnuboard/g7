<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\StorePopupRequest;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 이미지 위 글 상자 저장 테스트 (매니페스트 image-overlays.yaml — 등록·수정 검증, 유형·모바일 이미지 정리, 상세, 복사)
 *
 * 공개 응답은 `PublicPopupOverlayTest` 가 본다.
 */
class PopupOverlayTest extends PluginTestCase
{
    /**
     * 조회·등록수정·삭제 권한을 가진 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에, 지원 언어는 ko·en.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
        config(['app.translatable_locales' => ['ko', 'en']]);
    }

    /**
     * 유효한 글 상자 한 개 — 경계 케이스는 여기서 필드 하나만 바꾼다.
     *
     * @param  array<string, mixed>  $override  바꿀 값
     * @return array<string, mixed> 상자
     */
    private function box(array $override = []): array
    {
        return array_replace([
            'id' => 'a1b2c3d4',
            'x' => 8,
            'y' => 62,
            'w' => 84,
            'text' => ['ko' => '가을 감사 이벤트', 'en' => 'Autumn sale'],
            'size' => 6.5,
            'color' => '#ffffff',
            'bold' => true,
            'align' => 'center',
            'bg' => ['color' => '#000000', 'opacity' => 40],
            'shadow' => true,
        ], $override);
    }

    /**
     * 글 상자를 넣어 이미지형 팝업 등록을 보냅니다.
     *
     * @param  mixed  $overlays  보낼 overlays
     * @param  bool  $mobile  모바일 이미지도 올릴지
     * @param  array<string, mixed>  $overrides  바꿀 팝업 값
     * @return TestResponse 응답
     */
    private function store(mixed $overlays, bool $mobile = false, array $overrides = [])
    {
        return $this->createPopupViaApi($this->admin, ['overlays' => $overlays] + $overrides, mobile: $mobile);
    }

    /**
     * PC 상자를 저장하면 저장 모양(색 대문자·숫자 자리수 고정)으로 남고, 모바일 배치 없음은 null.
     * JSON 컬럼은 키 순서를 바꿔 저장하므로 순서 없이 비교한다(assertEquals).
     *
     * @scenario surface=store_saves_pc_overlays
     *
     * @effects overlays_saved_as_json
     *
     * @return void 반환값 없음
     */
    public function test_store_saves_pc_overlays(): void
    {
        $id = $this->store(['pc' => [$this->box(['x' => 8.04, 'w' => 84.06])], 'mobile' => null])->assertCreated()->json('data.id');

        $this->assertEquals([
            'pc' => [[
                'id' => 'a1b2c3d4', 'x' => 8, 'y' => 62, 'w' => 84.1,
                'text' => ['ko' => '가을 감사 이벤트', 'en' => 'Autumn sale'],
                'size' => 6.5, 'color' => '#FFFFFF', 'bold' => true, 'align' => 'center',
                'bg' => ['color' => '#000000', 'opacity' => 40], 'shadow' => true,
            ]],
            'mobile' => null,
        ], Popup::query()->find($id)->overlays);
    }

    /**
     * 기기마다 10개까지 받는다.
     *
     * @scenario surface=store_accepts_ten_boxes
     *
     * @return void 반환값 없음
     */
    public function test_store_accepts_ten_boxes(): void
    {
        $boxes = array_map(fn ($i) => $this->box(['id' => sprintf('box%05d', $i)]), range(1, 10));

        $id = $this->store(['pc' => $boxes])->assertCreated()->json('data.id');

        $this->assertCount(10, Popup::query()->find($id)->overlays['pc']);
    }

    /**
     * 11개면 422.
     *
     * @scenario surface=store_rejects_eleven_boxes
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_eleven_boxes(): void
    {
        $boxes = array_map(fn ($i) => $this->box(['id' => sprintf('box%05d', $i)]), range(1, 11));

        $this->store(['pc' => $boxes])->assertStatus(422)->assertJsonValidationErrors('overlays.pc');
    }

    /**
     * 왼쪽 위치 + 폭이 100% 를 넘으면 422, 딱 100% 는 통과.
     *
     * @scenario surface=store_rejects_box_past_right_edge
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_box_past_right_edge(): void
    {
        $this->store(['pc' => [$this->box(['x' => 20, 'w' => 80.1])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0');
        $this->store(['pc' => [$this->box(['x' => 20, 'w' => 80])]])->assertCreated();
    }

    /**
     * 위치·폭·글자 크기·투명도가 범위 밖이면 그 칸 422.
     *
     * @scenario surface=store_rejects_out_of_range_numbers
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_out_of_range_numbers(): void
    {
        $cases = [
            'x' => ['x' => -0.1],
            'y' => ['y' => 100.1],
            'w' => ['x' => 0, 'w' => 4.9],
            'size' => ['size' => 20.5],
            'bg.opacity' => ['bg' => ['color' => '#000000', 'opacity' => 91]],
        ];
        foreach ($cases as $field => $override) {
            $this->store(['pc' => [$this->box($override)]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.'.$field);
        }

        // 경계값 자체는 통과한다
        $this->store(['pc' => [$this->box(['x' => 0, 'y' => 100, 'w' => 5, 'size' => 2, 'bg' => ['color' => '#000000', 'opacity' => 90]])]])->assertCreated();
        $this->store(['pc' => [$this->box(['x' => 0, 'w' => 100, 'size' => 20, 'bg' => ['color' => '#000000', 'opacity' => 0]])]])->assertCreated();
    }

    /**
     * 글자 크기는 0.5 단위만.
     *
     * @scenario surface=store_rejects_size_off_half_step
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_size_off_half_step(): void
    {
        $this->store(['pc' => [$this->box(['size' => 6.3])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.size');
    }

    /**
     * 글자·바탕 색은 `#RRGGBB` 만.
     *
     * @scenario surface=store_rejects_bad_color
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_bad_color(): void
    {
        foreach (['#fff', 'red', '#12345G', 'rgb(0,0,0)'] as $color) {
            $this->store(['pc' => [$this->box(['color' => $color])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.color');
            $this->store(['pc' => [$this->box(['bg' => ['color' => $color, 'opacity' => 40]])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.bg.color');
        }
    }

    /**
     * 정렬은 왼·가운데·오른쪽만.
     *
     * @scenario surface=store_rejects_bad_align
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_bad_align(): void
    {
        $this->store(['pc' => [$this->box(['align' => 'justify'])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.align');
    }

    /**
     * id 는 영숫자 8자, 같은 기기 안에서 겹치면 422. PC 와 모바일은 같은 id 를 써도 된다(PC 배치 복사).
     *
     * @scenario surface=store_rejects_bad_or_duplicate_id
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_bad_or_duplicate_id(): void
    {
        $this->store(['pc' => [$this->box(['id' => 'short'])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.id');
        $this->store(['pc' => [$this->box(['id' => 'abc-1234'])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.id');
        $this->store(['pc' => [$this->box(), $this->box()]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.1.id');

        $this->store(['pc' => [$this->box()], 'mobile' => [$this->box()]], mobile: true)->assertCreated();
    }

    /**
     * 사이트 기본 언어 글이 없으면 422 — 빈 언어는 기본 언어 글로 대체되므로 기본 언어가 비면 빈 상자가 된다.
     *
     * @scenario surface=store_requires_default_locale_text
     *
     * @return void 반환값 없음
     */
    public function test_store_requires_default_locale_text(): void
    {
        $this->store(['pc' => [$this->box(['text' => ['en' => 'Only English']])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.text');
        $this->store(['pc' => [$this->box(['text' => ['ko' => '   ', 'en' => 'Sale']])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.text');
        $this->store(['pc' => [$this->box(['text' => ['ko' => '할인']])]])->assertCreated();
    }

    /**
     * 지원하지 않는 언어 글은 등록에서 422.
     *
     * @scenario surface=store_rejects_inactive_locale_text
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_inactive_locale_text(): void
    {
        $this->store(['pc' => [$this->box(['text' => ['ko' => '할인', 'ja' => '割引']])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.text');
    }

    /**
     * 글은 언어별 200자까지(태그를 뺀 뒤 길이).
     *
     * @scenario surface=store_rejects_text_over_200
     *
     * @return void 반환값 없음
     */
    public function test_store_rejects_text_over_200(): void
    {
        $this->store(['pc' => [$this->box(['text' => ['ko' => str_repeat('가', 201)]])]])->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.text');
        $this->store(['pc' => [$this->box(['text' => ['ko' => str_repeat('가', 200)]])]])->assertCreated();
    }

    /**
     * 오류 문구는 어느 기기의 몇 번째 상자인지 알린다(「PC 글 상자 2」) — 상자가 여럿이면 칸 이름만으로는 찾을 수 없다.
     *
     * @scenario surface=error_names_box_position
     *
     * @return void 반환값 없음
     */
    public function test_error_names_box_position(): void
    {
        $this->withHeader('Accept-Language', 'ko');
        $errors = $this->store([
            'pc' => [$this->box(), $this->box(['id' => 'second01', 'text' => ['en' => 'Only English']])],
            'mobile' => [$this->box(['x' => 50, 'w' => 60])],
        ], mobile: true)->assertStatus(422)->json('errors');

        $this->assertStringContainsString('PC 글 상자 2 글', $errors['overlays.pc.1.text'][0]);
        $this->assertStringContainsString('모바일 글 상자 1', $errors['overlays.mobile.0'][0]);
        $this->assertStringContainsString('오른쪽 밖으로', $errors['overlays.mobile.0'][0]);
    }

    /**
     * 글 상자 바탕(색·진하기) 오류도 칸 이름을 사람 말로 쓰고 상자 순번을 채운다 — 번역 키나 `:position` 이 그대로 나오지 않는다.
     * 필드 이름 표 전체(ko·en)에도 번역되지 않은 키가 없어야 한다.
     *
     * @scenario surface=error_names_box_background_parts
     *
     * @effects validation_messages_fully_translated
     *
     * @return void 반환값 없음
     */
    public function test_error_names_box_background_parts(): void
    {
        $cases = [
            'ko' => ['PC 글 상자 1 바탕 색', 'PC 글 상자 1 바탕 진하기'],
            'en' => ['PC text box 1 background color', 'PC text box 1 background strength'],
        ];
        foreach ($cases as $locale => [$color, $opacity]) {
            $errors = $this->withHeader('Accept-Language', $locale)
                ->store(['pc' => [$this->box(['bg' => ['opacity' => 120]])]])
                ->assertStatus(422)->json('errors');

            $this->assertStringContainsString($color, $errors['overlays.pc.0.bg.color'][0], $locale);
            $this->assertStringContainsString($opacity, $errors['overlays.pc.0.bg.opacity'][0], $locale);
            foreach (['overlays.pc.0.bg.color', 'overlays.pc.0.bg.opacity'] as $key) {
                $this->assertStringNotContainsString('sirsoft-popup::', $errors[$key][0], "{$locale} {$key}");
                $this->assertStringNotContainsString(':position', $errors[$key][0], "{$locale} {$key}");
            }

            app()->setLocale($locale);
            $untranslated = array_filter((new StorePopupRequest)->attributes(), fn (string $name) => str_contains($name, 'sirsoft-popup::'));
            $this->assertSame([], $untranslated, $locale);
        }
    }

    /**
     * 글은 일반 글로 저장한다 — 태그를 빼고 줄바꿈은 LF 로, 앞뒤 공백과 빈 언어를 지운다.
     *
     * @scenario surface=store_strips_html_from_text
     *
     * @return void 반환값 없음
     */
    public function test_store_strips_html_from_text(): void
    {
        $id = $this->store(['pc' => [$this->box(['text' => [
            'ko' => "  <b>가을</b> 할인\r\n<script>alert(1)</script>전 상품  ",
            'en' => '   ',
        ]])]])->assertCreated()->json('data.id');

        $this->assertSame(['ko' => "가을 할인\nalert(1)전 상품"], Popup::query()->find($id)->overlays['pc'][0]['text']);
    }

    /**
     * 직접 작성형으로 등록하거나 바꾸면 글 상자를 비운다.
     *
     * @scenario surface=store_html_type_clears_overlays
     *
     * @return void 반환값 없음
     */
    public function test_store_html_type_clears_overlays(): void
    {
        $id = $this->actingAs($this->admin)->postJson($this->api('/admin/popups'), $this->htmlPopupPayload([
            'overlays' => ['pc' => [$this->box()]],
        ]))->assertCreated()->json('data.id');
        $this->assertNull(Popup::query()->find($id)->overlays);

        $image = Popup::factory()->withImages()->withOverlays([$this->box()])->create();
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$image->id), $this->htmlPopupPayload([
            'overlays' => ['pc' => [$this->box()]],
            'revision' => 0,
        ]))->assertOk();
        $this->assertNull($image->fresh()->overlays);
    }

    /**
     * 모바일 이미지가 없으면 모바일 배치를 비운다(휴대폰도 PC 이미지와 PC 배치). 모바일 이미지가 있으면 남긴다.
     *
     * @scenario surface=store_without_mobile_image_clears_mobile_overlays
     *
     * @return void 반환값 없음
     */
    public function test_store_without_mobile_image_clears_mobile_overlays(): void
    {
        $mobileBox = $this->box(['id' => 'mobile01', 'y' => 10]);

        $without = $this->store(['pc' => [$this->box()], 'mobile' => [$mobileBox]])->assertCreated()->json('data.id');
        $this->assertNull(Popup::query()->find($without)->overlays['mobile']);

        $with = $this->store(['pc' => [$this->box()], 'mobile' => [$mobileBox]], mobile: true)->assertCreated()->json('data.id');
        $this->assertSame('mobile01', Popup::query()->find($with)->overlays['mobile'][0]['id']);

        // 수정에서 모바일 이미지를 빼면 모바일 배치도 빠지고, 세대 번호는 저장 한 번만큼만 오른다
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$with), $this->popupPayload([
            'overlays' => ['pc' => [$this->box()], 'mobile' => [$mobileBox]],
            'mobile_image_id' => null,
            'revision' => 0,
        ]))->assertOk()->assertJsonPath('data.revision', 1);
        $this->assertNull(Popup::query()->find($with)->overlays['mobile']);
    }

    /**
     * 수정하면 보낸 상자 목록으로 통째로 바뀐다(빼고 보낸 상자는 지워짐). 빈 목록이면 null.
     *
     * @scenario surface=update_replaces_overlays
     *
     * @effects overlays_saved_as_json
     *
     * @return void 반환값 없음
     */
    public function test_update_replaces_overlays(): void
    {
        $popup = Popup::factory()->withImages()->withOverlays([$this->box(), $this->box(['id' => 'second01'])])->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload([
            'overlays' => ['pc' => [$this->box(['id' => 'second01', 'x' => 10, 'w' => 50])], 'mobile' => null],
            'revision' => 0,
        ]))->assertOk();
        $this->assertSame(['second01'], array_column($popup->fresh()->overlays['pc'], 'id'));
        $this->assertSame(50, (int) $popup->fresh()->overlays['pc'][0]['w']);

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload([
            'overlays' => ['pc' => [], 'mobile' => null],
            'revision' => 1,
        ]))->assertOk();
        $this->assertNull($popup->fresh()->overlays);
    }

    /**
     * 언어팩을 꺼 지원 밖이 된 언어의 저장된 상자 글은 같은 id 상자에 보존한다. 저장돼 있지 않던 지원 밖 언어는 422.
     *
     * @scenario surface=update_keeps_inactive_locale_overlay_text
     *
     * @return void 반환값 없음
     */
    public function test_update_keeps_inactive_locale_overlay_text(): void
    {
        $popup = Popup::factory()->withImages()->withOverlays([
            $this->box(['text' => ['ko' => '할인', 'ja' => '割引']]),
            $this->box(['id' => 'second01', 'text' => ['ko' => '둘째', 'ja' => '二番目']]),
        ])->create();

        // 화면이 ja 를 다시 보내도 막지 않고, 빼고 보내도 지우지 않는다. 지운 상자의 글은 살리지 않는다.
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload([
            'overlays' => ['pc' => [$this->box(['text' => ['ko' => '할인 수정', 'ja' => '割引']])]],
            'revision' => 0,
        ]))->assertOk();
        $this->assertEquals([['ko' => '할인 수정', 'ja' => '割引']], array_column($popup->fresh()->overlays['pc'], 'text'));

        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload([
            'overlays' => ['pc' => [$this->box(['text' => ['ko' => '할인 재수정']])]],
            'revision' => 1,
        ]))->assertOk();
        $this->assertEquals(['ko' => '할인 재수정', 'ja' => '割引'], $popup->fresh()->overlays['pc'][0]['text']);

        // 새 상자에 지원 밖 언어를 넣으면 등록처럼 막는다
        $this->actingAs($this->admin)->putJson($this->api('/admin/popups/'.$popup->id), $this->popupPayload([
            'overlays' => ['pc' => [$this->box(['id' => 'newbox01', 'text' => ['ko' => '새 상자', 'ja' => '新しい']])]],
            'revision' => 2,
        ]))->assertStatus(422)->assertJsonValidationErrors('overlays.pc.0.text');
    }

    /**
     * 상세 응답은 저장된 상자를 언어별 글 그대로 싣는다(편집기가 그대로 받는다).
     *
     * @scenario surface=show_returns_overlays
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_overlays(): void
    {
        $popup = Popup::factory()->withImages(mobile: true)->withOverlays([$this->box()], [$this->box(['id' => 'mobile01'])])->create();
        $plain = Popup::factory()->withImages()->create();

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/popups/'.$popup->id))->assertOk()->json('data');
        $this->assertEquals(['ko' => '가을 감사 이벤트', 'en' => 'Autumn sale'], $data['overlays']['pc'][0]['text']);
        $this->assertSame('mobile01', $data['overlays']['mobile'][0]['id']);

        $this->assertNull($this->actingAs($this->admin)->getJson($this->api('/admin/popups/'.$plain->id))->assertOk()->json('data.overlays'));
    }

    /**
     * 복사본은 글 상자도 그대로 가진다.
     *
     * @scenario surface=bulk_copy_copies_overlays
     *
     * @return void 반환값 없음
     */
    public function test_bulk_copy_copies_overlays(): void
    {
        // 복사는 이미지 파일까지 복제하므로 실제로 올린 이미지로 만든다
        $sourceId = $this->store(['pc' => [$this->box()], 'mobile' => [$this->box(['id' => 'mobile01'])]], mobile: true)->assertCreated()->json('data.id');

        $copyId = $this->actingAs($this->admin)->postJson($this->api('/admin/popups/bulk-copy'), ['ids' => [$sourceId]])
            ->assertOk()->json('data.copies.'.$sourceId);

        $this->assertEquals(Popup::query()->find($sourceId)->overlays, Popup::query()->find($copyId)->overlays);
        $this->assertSame('mobile01', Popup::query()->find($copyId)->overlays['mobile'][0]['id']);
    }

    /**
     * 컬럼은 null 을 받는다 — 기존 팝업은 글 상자 없음.
     *
     * @scenario surface=migration_adds_nullable_overlays_column
     *
     * @return void 반환값 없음
     */
    public function test_migration_adds_nullable_overlays_column(): void
    {
        $this->assertTrue(Schema::hasColumn('popups', 'overlays'));
        $this->assertNull(Popup::factory()->create()->fresh()->overlays);
    }
}
