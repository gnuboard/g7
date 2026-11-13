<?php

namespace Tests\Feature\Fields;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;
use App\Services\CoreUpdateService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 항목 선언 동기화 — 코어 시딩, 운영자 수정 보존, 선언에서 빠진 항목 정리, 운영자 항목 보호.
 */
class FieldDefinitionSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 요청 안에 담긴 항목 목록 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetFieldMemo(): void
    {
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 코어 시더는 config 의 회원 항목 11개를 종전 동작과 같은 기본값으로 만든다.
     *
     * @scenario source=core
     *
     * @effects core_fields_seeded_with_legacy_defaults
     *
     * @return void 반환값 없음
     */
    public function test_core_seeder_creates_fields_with_legacy_defaults(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);

        $rows = FieldDefinition::where('field_set', 'core.user')->orderBy('sort_order')->get()->keyBy('key');
        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'],
            $rows->keys()->all(),
        );

        $this->assertTrue($rows['name']->required, '이름이 기본 필수가 아니다');
        foreach ($rows as $key => $row) {
            $this->assertTrue($row->enabled, "{$key} 이 기본 사용이 아니다");
            $this->assertSame('core', $row->source_type);
            $this->assertSame('column', $row->storage);
            $this->assertSame('self', $row->edit_scope);
            $this->assertNull($row->user_overrides, "{$key} 시딩이 운영자 수정으로 기록됐다");
            if ($key !== 'name') {
                $this->assertFalse($row->required, "{$key} 이 기본 선택이 아니다");
            }
        }
        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language'],
            $rows->filter(fn ($row) => $row->show_on_signup)->keys()->all(),
            '가입 화면 기본 표시 항목이 종전과 다르다',
        );
        $this->assertSame('zipcode,address,address_detail', $rows['address']->storage_target);
    }

    /**
     * 다시 시딩해도 행이 늘지 않고, 운영자가 고친 속성은 보존되며 고치지 않은 속성은 새 선언을 따른다.
     *
     * @scenario source=core
     *
     * @effects declared_sync_is_idempotent_and_preserves_operator_edits
     *
     * @return void 반환값 없음
     */
    public function test_resync_is_idempotent_and_preserves_operator_edits(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);
        $count = FieldDefinition::count();

        // 운영자가 휴대폰을 필수로 바꾸고 이름 라벨(ko)을 고쳤다.
        $mobile = FieldDefinition::where('key', 'mobile')->firstOrFail();
        $mobile->update(['required' => true, 'name' => ['ko' => '핸드폰', 'en' => 'Mobile']]);
        $this->assertEqualsCanonicalizing(['required', 'name.ko'], $mobile->fresh()->user_overrides);

        // 새 판 선언이 휴대폰의 영어 라벨과 순서를 바꿨다고 가정한다.
        $fields = config('core.fields');
        foreach ($fields as &$field) {
            if ($field['key'] === 'mobile') {
                $field['name'] = ['ko' => '휴대폰', 'en' => 'Mobile phone'];
                $field['sort_order'] = 35;
            }
        }
        unset($field);
        config(['core.fields' => $fields]);

        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->assertSame($count, FieldDefinition::count(), '다시 시딩하자 행 수가 달라졌다');
        $mobile->refresh();
        $this->assertTrue($mobile->required, '운영자가 켠 필수가 재시딩에서 꺼졌다');
        $this->assertSame('핸드폰', $mobile->name['ko'], '운영자가 고친 로케일 라벨이 덮였다');
        $this->assertSame('Mobile phone', $mobile->name['en'], '운영자가 고치지 않은 로케일이 새 선언을 따르지 않았다');
        $this->assertSame(35, $mobile->sort_order, '운영자가 고치지 않은 순서가 새 선언을 따르지 않았다');
    }

    /**
     * 선언에서 빠진 코어 항목은 값과 함께 정리되지만, 운영자가 만든 항목과 그 값은 남는다.
     *
     * @scenario source=custom
     *
     * @effects stale_declared_field_removed_with_values, custom_fields_survive_every_sync
     *
     * @return void 반환값 없음
     */
    public function test_stale_declared_fields_are_removed_but_custom_fields_survive(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);
        $helper = app(FieldDefinitionSyncHelper::class);

        // 값 테이블에 저장하는 코어 선언 하나를 더 넣었다가 다음 판에서 뺀다.
        $withExtra = [...config('core.fields'), [
            'field_set' => 'core.user', 'key' => 'legacy_note', 'type' => 'text', 'name' => ['ko' => '옛 메모'],
        ]];
        $helper->sync('core', 'core', $withExtra);
        $legacy = FieldDefinition::where('key', 'legacy_note')->firstOrFail();

        $custom = FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'job', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '직업'],
        ]);
        $this->forgetFieldMemo();

        $user = User::factory()->create();
        app(FieldValueService::class)->write($user, 'core.user', ['legacy_note' => '메모', 'job' => '개발자'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
        $this->assertSame(2, FieldValue::where('owner_id', $user->id)->count());

        $result = $helper->sync('core', 'core', config('core.fields'));

        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('field_definitions', ['id' => $legacy->id]);
        $this->assertDatabaseMissing('field_values', ['field_definition_id' => $legacy->id]);
        $this->assertDatabaseHas('field_definitions', ['id' => $custom->id]);
        $this->assertDatabaseHas('field_values', ['field_definition_id' => $custom->id, 'owner_id' => $user->id]);

        // 운영자 항목은 출처를 custom 으로 지정해 정리를 시도해도 지워지지 않는다.
        $this->assertSame(0, $helper->removeSource('custom', 'custom'));
        $this->assertSame(['synced' => [], 'skipped' => [], 'removed' => 0], $helper->sync('custom', 'custom', []));
        $this->assertDatabaseHas('field_definitions', ['id' => $custom->id]);
    }

    /**
     * 선언이 빈 배열이면 기존 행을 정리하지 않는다(환경 결함으로 선언이 비어 보일 때의 데이터 손실 방어).
     *
     * @scenario source=core
     *
     * @effects empty_declaration_never_wipes_rows
     *
     * @return void 반환값 없음
     */
    public function test_empty_declaration_does_not_wipe_existing_rows(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);
        $before = FieldDefinition::count();

        $result = app(FieldDefinitionSyncHelper::class)->sync('core', 'core', []);

        $this->assertSame(0, $result['removed']);
        $this->assertSame($before, FieldDefinition::count());
    }

    /**
     * 형식이 맞지 않는 선언은 건너뛰고 사유를 돌려준다.
     *
     * @scenario source=core
     *
     * @effects invalid_declaration_skipped_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_invalid_declarations_are_skipped_with_reason(): void
    {
        $result = app(FieldDefinitionSyncHelper::class)->sync('core', 'core', [
            ['field_set' => 'core.user', 'key' => 'ok_field', 'type' => 'text', 'name' => ['ko' => '정상']],
            ['field_set' => 'core.user', 'key' => 'Bad-Key', 'type' => 'text', 'name' => ['ko' => '키 형식']],
            ['field_set' => 'core.user', 'key' => 'no_type', 'type' => 'hologram', 'name' => ['ko' => '없는 유형']],
            ['field_set' => 'nowhere.set', 'key' => 'lost', 'type' => 'text', 'name' => ['ko' => '없는 집합']],
            ['field_set' => 'core.user', 'key' => 'no_name', 'type' => 'text'],
        ]);

        $this->assertSame(['core.user|ok_field'], $result['synced']);
        $this->assertSame(
            ['core.user|Bad-Key', 'core.user|no_type', 'nowhere.set|lost', 'core.user|no_name'],
            array_keys($result['skipped']),
        );
        $this->assertSame(1, FieldDefinition::count());
    }

    /**
     * 코어 업데이트의 선언 재동기화가 코어 회원 항목을 만든다(7.2.0 으로 올리는 사이트의 경로).
     *
     * @scenario source=core
     *
     * @effects core_resync_seeds_fields_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_core_update_resync_seeds_core_fields(): void
    {
        $this->assertSame(0, FieldDefinition::count());

        app(CoreUpdateService::class)->reloadCoreConfigAndResync();

        $this->assertSame(count(config('core.fields')), FieldDefinition::where('source_type', 'core')->count());
        $this->assertCount(5, app(FieldRegistry::class)->forSurface('core.user', FieldContext::for(FieldContext::SURFACE_SIGNUP)));
    }
}
