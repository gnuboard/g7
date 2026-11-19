<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 스케줄 행의 삭제 불가 사유(`delete_blocked_reason`)
 *
 * 행의 `abilities.can_delete` 는 삭제 권한과 소유 잠김(코어·확장이 등록한 작업은 지울 수 없음)을 한 값으로 합친다.
 * 화면은 막힌 「삭제」 항목 아래에 왜 막혔는지를 적는데, 값 하나로는 둘을 가를 수 없어 삭제 권한만 없는
 * 운영자가 직접 등록한 작업을 열어도 「직접 등록한 작업만 삭제할 수 있습니다.」가 나왔다.
 * 행은 막힌 사유를 `owned`(소유 잠김) · `permission`(권한 없음) · null(삭제 가능) 로 따로 싣는다.
 */
class ScheduleDeleteBlockedReasonTest extends TestCase
{
    use DatabaseTransactions;

    private string $marker;

    /**
     * 행 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = 'dbr'.Str::lower(Str::random(8));
    }

    /**
     * 주어진 권한만 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 운영자
     */
    private function operatorWith(array $permissions): User
    {
        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'schedule_delete_reason_'.uniqid(),
            'name' => ['ko' => '운영자', 'en' => 'Operator'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 표식이 붙은 스케줄 행을 만듭니다.
     *
     * @param  string  $suffix  이름 뒤에 붙일 구분자
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Schedule 생성된 스케줄
     */
    private function makeSchedule(string $suffix, array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => $this->marker.'-'.$suffix,
            'label' => ['ko' => $this->marker.' 작업 '.$suffix, 'en' => $this->marker.' task '.$suffix],
            'description' => ['ko' => '설명', 'en' => 'desc'],
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 직접 등록 행과 코어 소유 행을 만들고 목록 응답의 두 행을 이름으로 돌려줍니다.
     *
     * @param  User  $user  요청하는 운영자
     * @return array{custom: array<string, mixed>, core: array<string, mixed>} 목록 행
     */
    private function listRows(User $user): array
    {
        $this->makeSchedule('custom');
        $this->makeSchedule('core', ['extension_type' => 'core', 'extension_identifier' => 'core']);

        $rows = collect($this->actingAs($user)
            ->getJson('/api/admin/schedules?per_page=100&search_field=identifier&search_keyword='.$this->marker)
            ->assertOk()
            ->json('data.data'))->keyBy('name');

        return ['custom' => $rows[$this->marker.'-custom'], 'core' => $rows[$this->marker.'-core']];
    }

    /**
     * 조회 권한만 있는 운영자: 직접 등록 행은 권한 때문에, 소유 행은 소유 잠김 때문에 막힌다
     *
     * @scenario case=delete_blocked_reason
     *
     * @effects schedule_delete_blocked_reason_splits_permission_and_owner
     *
     * @return void 반환값 없음
     */
    public function test_read_only_operator_gets_permission_reason_on_custom_row(): void
    {
        $rows = $this->listRows($this->operatorWith(['core.schedules.read']));

        $this->assertFalse($rows['custom']['abilities']['can_delete']);
        $this->assertSame('permission', $rows['custom']['delete_blocked_reason']);

        // 소유 행은 권한과 무관하게 지울 수 없다 — 권한이 생겨도 풀리지 않는 사유를 앞세운다
        $this->assertFalse($rows['core']['abilities']['can_delete']);
        $this->assertSame('owned', $rows['core']['delete_blocked_reason']);
    }

    /**
     * 삭제 권한이 있는 운영자: 직접 등록 행은 사유 없음, 소유 행은 소유 잠김
     *
     * @scenario case=delete_blocked_reason
     *
     * @effects schedule_delete_blocked_reason_splits_permission_and_owner
     *
     * @return void 반환값 없음
     */
    public function test_operator_with_delete_permission_gets_owner_reason_only(): void
    {
        $rows = $this->listRows($this->operatorWith(['core.schedules.read', 'core.schedules.delete']));

        $this->assertTrue($rows['custom']['abilities']['can_delete']);
        $this->assertNull($rows['custom']['delete_blocked_reason']);

        $this->assertFalse($rows['core']['abilities']['can_delete']);
        $this->assertSame('owned', $rows['core']['delete_blocked_reason']);
    }

    /**
     * 상세 응답도 같은 사유를 싣는다(행 메뉴·편집 창이 같은 값을 읽는다)
     *
     * @scenario case=delete_blocked_reason
     *
     * @effects schedule_delete_blocked_reason_splits_permission_and_owner
     *
     * @return void 반환값 없음
     */
    public function test_detail_carries_the_same_reason(): void
    {
        $user = $this->operatorWith(['core.schedules.read']);
        $custom = $this->makeSchedule('custom');

        $data = $this->actingAs($user)->getJson('/api/admin/schedules/'.$custom->id)->assertOk()->json('data');

        $this->assertFalse($data['abilities']['can_delete']);
        $this->assertSame('permission', $data['delete_blocked_reason']);
    }
}
