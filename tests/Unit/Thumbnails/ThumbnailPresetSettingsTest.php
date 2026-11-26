<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPresetSettings;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 썸네일 규격 설정의 저장 변환(mergeForSave)과 관리자 표(presentRows) 테스트
 *
 * 설정 저장소는 카테고리 1단계만 병합해 목록을 통째로 바꾼다. 그래서 저장 변환이 고정 슬롯을 늘 보충하고,
 * 잠긴 행(고정, 확장)의 이름을 지키고, 지금 꺼진 확장의 수정값을 이어 붙이는지가 곧 데이터 보존이다.
 * 저장된 규격 행과 SEO 크기는 운영자 저장값이 스며들지 않게 setUp 에서 고정한다
 * (`mem:core-settings-boot-config-leaks-into-unit-tests`). DB 는 쓰지 않는다.
 */
class ThumbnailPresetSettingsTest extends TestCase
{
    /**
     * 저장 규격, og 크기를 고정하고 규격 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.thumbnail.presets', null);
        Config::set('attachment.image_quality', 85);
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        HookManager::clearFilter('core.thumbnail.filter_presets');
        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * 규격 필터와 레지스트리 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_presets');
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 변환기 (레지스트리 캐시를 비운 상태)
     *
     * @return ThumbnailPresetSettings 변환기
     */
    private function settings(): ThumbnailPresetSettings
    {
        app(ThumbnailPresetRegistry::class)->flush();

        return app(ThumbnailPresetSettings::class);
    }

    /**
     * 확장 규격 하나를 필터로 등록합니다 (켜진 확장 흉내).
     *
     * @return void 반환값 없음
     */
    private function registerBoardPreset(): void
    {
        HookManager::addFilter('core.thumbnail.filter_presets', function (array $rows) {
            $rows[] = ['name' => 'sirsoft-board.list', 'width' => 300, 'height' => 200, 'fit' => 'cover', 'pregenerate' => true];

            return $rows;
        });
    }

    /**
     * 이름 → 행 색인
     *
     * @param  list<array<string, mixed>>  $rows  행 목록
     * @return array<string, array<string, mixed>> 색인
     */
    private function byName(array $rows): array
    {
        return array_column($rows, null, 'name');
    }

    /**
     * 빈 표를 보내도 고정 슬롯 5행이 기본값으로 채워지고 og 는 저장되지 않습니다.
     *
     * @scenario surface=save_storage
     *
     * @effects fixed_slots_always_stored
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function empty_input_still_stores_the_five_fixed_slots(): void
    {
        $saved = $this->settings()->mergeForSave([], []);

        $this->assertSame(ThumbnailPresetRegistry::FIXED_SLOTS, array_column($saved, 'name'));
        $this->assertSame(640, $this->byName($saved)['md']['width']);
        $this->assertArrayNotHasKey('locked', $saved[0], '잠금 표시는 저장하지 않는다(출처로 다시 정해진다).');
    }

    /**
     * 표에 없는 고정 행은 기존 저장값을, 보낸 고정 행은 보낸 값을 씁니다.
     *
     * 화면이 일부 행만 보내도 다른 고정 행의 운영자 수정값이 기본값으로 되돌아가면 안 된다.
     *
     * @scenario surface=save_storage
     *
     * @effects fixed_slots_always_stored
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function missing_fixed_rows_keep_previous_values(): void
    {
        $previous = [['name' => 'xs', 'source' => 'core', 'width' => 80, 'height' => 80, 'fit' => 'cover']];
        $input = [['name' => 'md', 'source' => 'core', 'width' => 700, 'height' => null, 'fit' => 'width']];

        $saved = $this->byName($this->settings()->mergeForSave($input, $previous));

        $this->assertSame(80, $saved['xs']['width'], '보내지 않은 고정 행의 기존 수정값이 사라졌습니다.');
        $this->assertSame(700, $saved['md']['width']);
        $this->assertSame(1280, $saved['lg']['width'], '저장값도 입력도 없는 고정 행은 기본값이어야 합니다.');
    }

    /**
     * 고정 행은 출처를 custom 으로 바꿔 보내도 core 로 저장되고, og 행은 보내도 저장되지 않습니다.
     *
     * @scenario surface=save_storage
     *
     * @effects locked_rows_keep_name_and_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function locked_rows_keep_name_and_source_and_og_is_not_stored(): void
    {
        $input = [
            ['name' => 'xs', 'source' => 'custom', 'width' => 90, 'height' => 90, 'fit' => 'cover'],
            ['name' => 'og', 'source' => 'core', 'width' => 999, 'height' => 999, 'fit' => 'cover'],
        ];

        $saved = $this->byName($this->settings()->mergeForSave($input, []));

        $this->assertSame(ThumbnailSpec::SOURCE_CORE, $saved['xs']['source']);
        $this->assertSame(90, $saved['xs']['width']);
        $this->assertArrayNotHasKey('og', $saved, 'og 크기는 SEO 탭 소유라 업로드 탭 저장본에 들어가면 안 됩니다.');
    }

    /**
     * 켜진 확장의 행은 값만 받고, 지금 꺼진 확장의 기존 저장 행은 그대로 남습니다.
     *
     * 남지 않으면 확장을 잠깐 껐다 켜는 것만으로 운영자가 고친 확장 규격 값이 사라진다.
     *
     * @scenario surface=save_storage
     *
     * @effects disabled_extension_rows_preserved
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_rows_are_kept_for_active_and_disabled_extensions(): void
    {
        $this->registerBoardPreset();

        $previous = [
            ['name' => 'sirsoft-ecommerce.card', 'source' => 'sirsoft-ecommerce', 'width' => 500, 'height' => 500, 'fit' => 'cover'],
        ];
        $input = [
            ['name' => 'sirsoft-board.list', 'source' => 'custom', 'width' => 360, 'height' => 240, 'fit' => 'cover'],
        ];

        $saved = $this->byName($this->settings()->mergeForSave($input, $previous));

        $this->assertSame(360, $saved['sirsoft-board.list']['width']);
        $this->assertSame('sirsoft-board', $saved['sirsoft-board.list']['source'], '확장 행의 출처는 이름 앞부분이 정한다.');
        $this->assertTrue($saved['sirsoft-board.list']['pregenerate'], '보내지 않은 값은 확장 기본값이어야 합니다.');
        $this->assertSame($previous[0], $saved['sirsoft-ecommerce.card'], '꺼진 확장의 저장 행이 사라지거나 바뀌었습니다.');
    }

    /**
     * 운영자 행은 형식에 맞는 것만 custom 으로 저장되고, 잘못된 이름과 중복은 버립니다.
     *
     * @scenario surface=save_storage
     *
     * @effects custom_rows_normalized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function custom_rows_are_normalized_and_invalid_ones_dropped(): void
    {
        $input = [
            ['name' => 'banner', 'width' => 1000, 'height' => 300, 'fit' => 'cover', 'source' => 'core'],
            ['name' => 'banner', 'width' => 10, 'height' => 10, 'fit' => 'cover'],
            ['name' => 'a-b', 'width' => 100, 'height' => 100, 'fit' => 'cover'],
        ];

        $saved = $this->settings()->mergeForSave($input, []);
        $names = array_column($saved, 'name');

        $this->assertSame([...ThumbnailPresetRegistry::FIXED_SLOTS, 'banner'], $names);
        $this->assertSame(ThumbnailSpec::SOURCE_CUSTOM, $this->byName($saved)['banner']['source']);
        $this->assertSame(1000, $this->byName($saved)['banner']['width'], '중복 이름은 앞의 행이 이겨야 합니다.');
    }

    /**
     * 키가 없는 기존 설치(저장 행 없음)도 표가 기본값으로 완성됩니다 — 업그레이드 스텝이 필요 없는 근거.
     *
     * @scenario surface=admin_response
     *
     * @effects keyless_install_reads_defaults
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function present_rows_work_without_any_stored_key(): void
    {
        Config::set('core.thumbnail.presets', null);

        $rows = $this->settings()->presentRows();

        $this->assertSame([...ThumbnailPresetRegistry::FIXED_SLOTS, 'og'], array_column($rows, 'name'));
        $this->assertSame(['fixed', 'fixed', 'fixed', 'fixed', 'fixed', 'og'], array_column($rows, 'kind'));
        $this->assertTrue($rows[5]['size_readonly']);
        $this->assertSame(1200, $rows[5]['width'], 'og 행 크기는 SEO 탭 값이어야 합니다.');
    }

    /**
     * 고정 행이 빠진 저장본(운영자 행만 있음)을 읽어도 고정 행이 기본값으로 채워지고 운영자 행이 뒤에 붙습니다.
     *
     * @scenario surface=admin_response
     *
     * @effects keyless_install_reads_defaults
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function present_rows_fill_missing_fixed_rows_from_defaults(): void
    {
        $this->registerBoardPreset();
        Config::set('core.thumbnail.presets', [
            ['name' => 'banner', 'source' => 'custom', 'width' => 1000, 'height' => 300, 'fit' => 'cover'],
        ]);

        $rows = $this->byName($this->settings()->presentRows());

        $this->assertSame(640, $rows['md']['width']);
        $this->assertSame('extension', $rows['sirsoft-board.list']['kind']);
        $this->assertSame(300, $rows['sirsoft-board.list']['defaults']['width'], '확장 행은 「기본값으로」 에 쓸 기본값을 실어야 합니다.');
        $this->assertSame('custom', $rows['banner']['kind']);
        $this->assertFalse($rows['banner']['locked']);
    }

    /**
     * 확장이 다국어 키로 등록한 이름(`label`)이 표 행에 로케일 맵으로 실립니다.
     * 화면은 `$localized()` 로 현재 언어를 고르므로 언어를 바꿔도 다시 받지 않고 바뀐다. 번역이 없는 로케일은 맵에서 빠진다.
     *
     * @scenario surface=admin_response
     *
     * @effects extension_label_resolved_per_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function present_rows_resolve_extension_label_key_per_locale(): void
    {
        Config::set('app.translatable_locales', ['ko', 'en', 'ja']);
        $translator = app('translator');
        $translator->addLines(['messages.thumbnail.list' => '게시판 목록'], 'ko', 'sirsoft-board');
        $translator->addLines(['messages.thumbnail.list' => 'Board list'], 'en', 'sirsoft-board');
        HookManager::addFilter('core.thumbnail.filter_presets', function (array $rows) {
            $rows[] = ['name' => 'sirsoft-board.list', 'label' => 'sirsoft-board::messages.thumbnail.list', 'width' => 300, 'height' => 200, 'fit' => 'cover'];

            return $rows;
        });

        $row = $this->byName($this->settings()->presentRows())['sirsoft-board.list'];

        $this->assertSame(['ko' => '게시판 목록', 'en' => 'Board list'], $row['label'], '번역이 없는 ja 는 맵에서 빠져야 합니다(화면이 다른 언어로 폴백).');
        $this->assertArrayNotHasKey('label', $row['defaults'], '「기본값으로」 값에 이름이 섞이면 안 됩니다.');
    }

    /**
     * 확장이 로케일 맵으로 준 이름은 문자열 값만 남기고, 이름이 없거나 다국어 키가 풀리지 않으면 null 이라
     * 화면이 규격 이름(식별자)으로 폴백합니다. 고정·og·운영자 행은 이름 칸이 늘 null 입니다.
     *
     * @scenario surface=admin_response
     *
     * @effects missing_label_falls_back_to_name
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function present_rows_accept_locale_map_and_fall_back_to_null_label(): void
    {
        HookManager::addFilter('core.thumbnail.filter_presets', function (array $rows) {
            $rows[] = ['name' => 'sirsoft-board.list', 'label' => ['ko' => ' 게시판 목록 ', 'en' => '', 'ja' => 3], 'width' => 300, 'height' => 200, 'fit' => 'cover'];
            $rows[] = ['name' => 'sirsoft-board.view', 'label' => 'sirsoft-board::messages.thumbnail.no_such_key', 'width' => 800, 'height' => null, 'fit' => 'width'];
            $rows[] = ['name' => 'sirsoft-board.mini', 'width' => 80, 'height' => 80, 'fit' => 'cover'];

            return $rows;
        });
        Config::set('core.thumbnail.presets', [
            ['name' => 'banner', 'source' => 'custom', 'label' => ['ko' => '운영자가 끼워 넣은 이름'], 'width' => 1000, 'height' => 300, 'fit' => 'cover'],
        ]);

        $rows = $this->byName($this->settings()->presentRows());

        $this->assertSame(['ko' => '게시판 목록'], $rows['sirsoft-board.list']['label']);
        $this->assertNull($rows['sirsoft-board.view']['label'], '풀리지 않는 다국어 키를 그대로 보이면 안 됩니다.');
        $this->assertNull($rows['sirsoft-board.mini']['label']);
        $this->assertNull($rows['banner']['label'], '운영자 행은 저장 행에 끼어든 이름을 따르지 않습니다.');

        foreach ([...ThumbnailPresetRegistry::FIXED_SLOTS, 'og'] as $name) {
            $this->assertArrayHasKey('label', $rows[$name]);
            $this->assertNull($rows[$name]['label'], "{$name} 행은 이름 칸이 비어 있어야 합니다.");
        }
    }

    /**
     * 이름은 화면 표시용이라 저장 행에 남지 않고 지문도 바꾸지 않습니다 — 화면이 이름 실린 행을 그대로 돌려보내도
     * 저장본은 값만 담는다(확장 이름은 늘 확장 등록에서 다시 읽는다).
     *
     * @scenario surface=save_storage
     *
     * @effects label_not_stored_nor_fingerprinted
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function labels_are_not_stored_and_do_not_change_fingerprint(): void
    {
        HookManager::addFilter('core.thumbnail.filter_presets', function (array $rows) {
            $rows[] = ['name' => 'sirsoft-board.list', 'label' => ['ko' => '게시판 목록'], 'width' => 300, 'height' => 200, 'fit' => 'cover'];

            return $rows;
        });

        $saved = $this->settings()->mergeForSave([
            ['name' => 'sirsoft-board.list', 'label' => ['ko' => '바꾼 이름'], 'width' => 320, 'height' => 200, 'fit' => 'cover'],
            ['name' => 'banner', 'label' => ['ko' => '배너'], 'width' => 1000, 'height' => 300, 'fit' => 'cover'],
        ]);

        foreach ($saved as $row) {
            $this->assertArrayNotHasKey('label', $row, "{$row['name']} 저장 행에 이름이 남았습니다.");
        }

        $spec = app(ThumbnailPresetRegistry::class)->find('sirsoft-board.list');
        $plain = ThumbnailSpec::fromArray(['name' => 'sirsoft-board.list', 'width' => 300, 'height' => 200, 'fit' => 'cover'], 'sirsoft-board', true);
        $this->assertSame($plain->fingerprint('gd'), $spec->fingerprint('gd'), '이름이 지문에 들어가면 이름만 바꿔도 파일을 다시 만듭니다.');
    }
}
