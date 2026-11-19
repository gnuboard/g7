<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Models\Attachment;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPresetSettings;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Support\Facades\Storage;
use Modules\Gnuboard7\HelloModule\Listeners\ThumbnailPresetListener;
use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * 확장 썸네일 규격 통합 테스트 — `gnuboard7-hello_module.sample` (가로 480 · 세로 270 자르기)
 *
 * 모듈이 `core.thumbnail.filter_presets` 로 등록한 규격이 코어 레지스트리·관리자 표·서빙까지 실제 훅 체인으로
 * 이어지는지 잰다(mock 금지). 리스너는 기저 클래스가 모듈 선언(`getHookListeners`)대로 건다.
 */
class ThumbnailPresetIntegrationTest extends ModuleTestCase
{
    /**
     * 저장 행을 비워 규격 표를 기본값으로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.thumbnail.enabled' => true,
            'core.thumbnail.presets' => [],
            'core.thumbnail.disk_gated' => 'thumbnails',
            'core.image.driver' => 'gd',
        ]);
        $this->registry()->flush();
    }

    /**
     * 규격 레지스트리
     *
     * @return ThumbnailPresetRegistry 레지스트리
     */
    private function registry(): ThumbnailPresetRegistry
    {
        return app(ThumbnailPresetRegistry::class);
    }

    /**
     * 확장 규격이 레지스트리에 이름이 잠긴 채, 출처를 모듈 식별자로 들어온다.
     *
     * @scenario case=registered
     *
     * @effects extension_preset_listed_locked_with_module_source
     *
     * @return void 반환값 없음
     */
    public function test_extension_preset_is_listed_locked_with_module_source(): void
    {
        $spec = $this->registry()->find(ThumbnailPresetListener::PRESET);

        $this->assertInstanceOf(ThumbnailSpec::class, $spec);
        $this->assertSame('gnuboard7-hello_module', $spec->source);
        $this->assertTrue($spec->locked);
        $this->assertSame(480, $spec->width);
        $this->assertSame(270, $spec->height);
        $this->assertSame(ThumbnailSpec::FIT_COVER, $spec->fit);
        $this->assertTrue($spec->enabled);
        $this->assertFalse($spec->numeric, '확장 규격이 숫자 요청 대상으로 끼어들었다');

        $names = array_map(fn (ThumbnailSpec $s) => $s->name, $this->registry()->all());
        $this->assertSame(['xs', 'sm', 'md', 'lg', 'xl', 'og'], array_slice($names, 0, 6), '확장 규격은 고정·og 뒤에 온다');
        $this->assertContains(ThumbnailPresetListener::PRESET, $names);
    }

    /**
     * 운영자가 고친 값(저장 행)이 등록값을 덮는다 — 이름·출처는 덮지 못한다.
     *
     * @scenario case=operator_override
     *
     * @effects extension_preset_operator_values_override_defaults
     *
     * @return void 반환값 없음
     */
    public function test_operator_saved_values_override_the_registered_defaults(): void
    {
        config(['core.thumbnail.presets' => [[
            'name' => ThumbnailPresetListener::PRESET,
            'source' => 'custom',
            'width' => 600,
            'height' => 400,
            'fit' => 'contain',
            'quality' => 70,
            'enabled' => false,
        ]]]);

        $spec = $this->registry()->find(ThumbnailPresetListener::PRESET);

        $this->assertSame(600, $spec->width);
        $this->assertSame(400, $spec->height);
        $this->assertSame(ThumbnailSpec::FIT_CONTAIN, $spec->fit);
        $this->assertSame(70, $spec->quality);
        $this->assertFalse($spec->enabled);
        $this->assertSame('gnuboard7-hello_module', $spec->source, '저장 행이 출처를 바꿨다');
        $this->assertTrue($spec->locked);
    }

    /**
     * 관리자 표의 확장 행은 `defaults`(등록값)를 싣고, 그 값으로 저장하면 「기본값으로」 복원된다.
     *
     * @scenario case=restore_defaults
     *
     * @effects extension_preset_restore_defaults_from_present_rows
     *
     * @return void 반환값 없음
     */
    public function test_present_rows_defaults_restore_the_registered_values(): void
    {
        $previous = [[
            'name' => ThumbnailPresetListener::PRESET,
            'source' => 'gnuboard7-hello_module',
            'width' => 600,
            'height' => 400,
            'fit' => 'contain',
        ]];
        config(['core.thumbnail.presets' => $previous]);

        $settings = app(ThumbnailPresetSettings::class);
        $rows = collect($settings->presentRows())->keyBy('name');
        $row = $rows[ThumbnailPresetListener::PRESET];

        $this->assertSame('extension', $row['kind']);
        $this->assertSame(600, $row['width'], '표가 운영자 값을 보이지 않는다');
        $this->assertSame(480, $row['defaults']['width']);
        $this->assertSame(270, $row['defaults']['height']);
        $this->assertSame('cover', $row['defaults']['fit']);

        // 화면의 「기본값으로」 = 그 행 값을 defaults 로 바꿔 보낸다
        $input = $rows->values()->map(
            fn (array $r) => $r['name'] === ThumbnailPresetListener::PRESET ? ['name' => $r['name']] + $r['defaults'] : $r
        )->all();
        $saved = $settings->mergeForSave($input, $previous);

        config(['core.thumbnail.presets' => $saved]);
        $this->registry()->flush();
        $spec = $this->registry()->find(ThumbnailPresetListener::PRESET);

        $this->assertSame(480, $spec->width);
        $this->assertSame(270, $spec->height);
        $this->assertSame(ThumbnailSpec::FIT_COVER, $spec->fit);
    }

    /**
     * 규격 행의 `label`(다국어 키)이 관리자 표에 로케일 맵으로 실려, 표가 식별자 대신 사람이 읽는 이름을 보인다.
     * 이름은 저장 행에 남지 않는다(늘 모듈 등록에서 다시 읽는다).
     *
     * @scenario case=registered
     *
     * @effects extension_preset_label_shown_in_table
     *
     * @return void 반환값 없음
     */
    public function test_present_rows_carry_the_registered_label_per_locale(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);
        $this->registry()->flush();

        $settings = app(ThumbnailPresetSettings::class);
        $row = collect($settings->presentRows())->keyBy('name')[ThumbnailPresetListener::PRESET];

        $this->assertSame(['ko' => 'Hello 모듈 샘플', 'en' => 'Hello module sample'], $row['label']);

        $saved = collect($settings->mergeForSave([$row]))->keyBy('name')[ThumbnailPresetListener::PRESET];
        $this->assertArrayNotHasKey('label', $saved, '이름이 저장 행에 남았다');
    }

    /**
     * 모듈이 꺼져 필터가 빠지면 규격도 빠지지만, 운영자 저장값은 남아 다시 켜면 되살아난다.
     *
     * @scenario case=module_disabled
     *
     * @effects extension_preset_saved_values_survive_deactivation
     *
     * @return void 반환값 없음
     */
    public function test_saved_values_survive_while_the_module_is_disabled(): void
    {
        $previous = [[
            'name' => ThumbnailPresetListener::PRESET,
            'source' => 'gnuboard7-hello_module',
            'width' => 600,
            'height' => 400,
            'fit' => 'contain',
        ]];

        HookManager::clearFilter('core.thumbnail.filter_presets');
        config(['core.thumbnail.presets' => $previous]);
        $this->registry()->flush();

        $this->assertNull($this->registry()->find(ThumbnailPresetListener::PRESET), '꺼진 모듈의 규격이 남았다');

        $saved = app(ThumbnailPresetSettings::class)->mergeForSave([], $previous);
        $kept = collect($saved)->firstWhere('name', ThumbnailPresetListener::PRESET);

        $this->assertNotNull($kept, '꺼진 모듈의 운영자 저장값을 저장하며 지웠다');
        $this->assertSame(600, $kept['width']);
    }

    /**
     * 이름 형식 `{확장 식별자}.{키}` 를 어긴 행은 코어가 버린다.
     *
     * @scenario case=invalid_name
     *
     * @effects extension_preset_invalid_names_ignored
     *
     * @return void 반환값 없음
     */
    public function test_rows_violating_the_name_format_are_ignored(): void
    {
        $invalid = ['sample', 'hello.sample', 'gnuboard7-hello_module.Bad-Key', 'gnuboard7-hello_module.', 'xs'];

        HookManager::addFilter('core.thumbnail.filter_presets', function (array $rows) use ($invalid) {
            foreach ($invalid as $name) {
                $rows[] = ['name' => $name, 'width' => 300, 'height' => 300, 'fit' => 'cover'];
            }

            return $rows;
        }, 20);
        $this->registry()->flush();

        $defaults = $this->registry()->extensionDefaults();

        $this->assertSame([ThumbnailPresetListener::PRESET], array_keys($defaults));

        $xs = $this->registry()->find('xs');
        $this->assertSame(64, $xs->width, '형식을 어긴 확장 행이 고정 슬롯을 덮었다');
        $this->assertSame('core', $xs->source);
        $this->assertNull($this->registry()->find('hello.sample'));
    }

    /**
     * 앞선 필터가 배열이 아닌 값을 넘기면 손대지 않고 그대로 돌려준다 — 빈 배열로 바꿔 규격 하나만 남기면
     * 앞선 리스너가 만든 값(코어가 오류로 다룰 값 포함)을 이 데모가 조용히 지운다.
     *
     * @scenario case=non_array_passthrough
     *
     * @effects extension_preset_non_array_passthrough
     *
     * @return void 반환값 없음
     */
    public function test_non_array_value_is_returned_untouched(): void
    {
        $listener = new ThumbnailPresetListener;

        $this->assertNull($listener->addPreset(null));
        $this->assertSame('broken', $listener->addPreset('broken'));
        $this->assertSame([ThumbnailPresetListener::PRESET], array_column($listener->addPreset([]), 'name'));
    }

    /**
     * 코어 첨부 주소에 `?size=gnuboard7-hello_module.sample` 을 붙이면 480×270 썸네일이 나간다.
     *
     * @scenario case=serve
     *
     * @effects extension_preset_serves_core_attachment_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_core_attachment_thumbnail_uses_the_extension_preset(): void
    {
        config(['attachment.disk' => 'attachments']);
        Storage::fake('attachments');
        Storage::fake('thumbnails');

        $canvas = imagecreatetruecolor(1200, 900);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 80, 40));
        ob_start();
        imagejpeg($canvas, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        $path = 'attachments/2026/11/05/hello-sample.jpg';
        Storage::disk('attachments')->put($path, $bytes);

        $attachment = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => $path,
            'original_filename' => 'hello-sample.jpg',
            'stored_filename' => 'hello-sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
        ]);

        $response = $this->get('/api/attachment/'.$attachment->hash.'?size='.ThumbnailPresetListener::PRESET);

        $response->assertOk();
        $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER));

        $size = getimagesizefromstring($response->streamedContent());
        $this->assertIsArray($size);
        $this->assertSame([480, 270], [$size[0], $size[1]]);

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringContainsString('/'.ThumbnailPresetListener::PRESET.'_', $files[0]);
    }
}
