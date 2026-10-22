<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 보안 점검(2026-10-15)이 짚은 권한 경계의 회귀 테스트
 *
 * - 남이 만든 파일은 원본 조회 범위가 「전체」인 운영자만 받는다 — 범위가 좁은 운영자가 남의 파일로 자기 범위 밖 자료를
 *   받지 못하게 한다(「다른 운영자의 내보내기 관리」 권한만으로는 부족하다).
 * - 남의 개인정보 작업을 다시 요청하면 다시 요청하는 사람이 사유를 직접 남긴다.
 * - 다운로드 이력의 IP 는 개인정보 권한이 있는 운영자에게만 보인다.
 */
class ExportSecurityTest extends ExportTestCase
{
    /**
     * 큐를 가짜로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateExportJob::class]);
    }

    /**
     * 주문 조회 권한의 범위를 「본인」으로 좁힙니다.
     *
     * @param  User  $user  대상 운영자 (createAdminUser 로 만든 단일 역할)
     * @return void 반환값 없음
     */
    private function narrowOrderScopeToSelf(User $user): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.orders.read')->firstOrFail();
        // 설치된 이커머스와 같게 — 주문 조회 권한의 소유자 열(테스트 도우미가 만든 권한 행에는 비어 있다)
        $permission->forceFill(['owner_key' => 'user_id'])->save();
        $user->roles()->firstOrFail()->permissions()->updateExistingPivot($permission->id, ['scope_type' => ScopeType::Self->value]);
        PermissionHelper::clearPermissionScopeCache();
        $this->clearPermissionCache();
    }

    /**
     * 파일이 있는 작업을 만듭니다.
     *
     * @param  User  $owner  요청자
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return Export 작업
     */
    private function export(User $owner, array $overrides = []): Export
    {
        $path = 'fixture/'.uniqid().'.csv';
        Storage::disk('modules')->put($this->storedPath($path), "a,b\r\n");

        return Export::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::COMPLETED->value,
            'requested_by' => $owner->id,
            'options' => ['fields' => ['order.order_number'], 'row_unit' => 'order', 'scope' => 'filter', 'locale_mode' => 'locale'],
            'filters' => [],
            'file_name' => 'orders.csv',
            'file_path' => $path,
            'requested_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'expires_at' => now()->addDays(3),
        ], $overrides));
    }

    /**
     * 주문 조회 범위가 「본인」인 운영자는 남이 만든 주문 파일을 받지 못한다(관리 권한이 있어도). 자기 파일과,
     * 범위가 「전체」인 운영자는 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=self_scope
     *
     * @effects download_requires_full_source_scope_for_others
     */
    public function test_scoped_operator_cannot_download_others_export(): void
    {
        $full = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.exports.create']);
        $others = $this->export($full);

        $scoped = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
        ]);
        $this->narrowOrderScopeToSelf($scoped);
        $this->assertNotNull(PermissionHelper::getOwnerKey('sirsoft-ecommerce.orders.read'), '전제: 주문 조회 권한은 범위를 갖는다');
        // 「본인」 범위로 만든 자기 파일 — 실제 생성은 그때 범위(self)를 기록한다
        $own = $this->export($scoped, ['options' => ['fields' => ['order.order_number'], 'row_unit' => 'order', 'scope' => 'filter', 'locale_mode' => 'locale', 'source_scope' => 'self']]);

        $this->actingAs($scoped)->postJson($this->exportsUrl("/{$others->id}/download-link"))->assertStatus(403);
        $this->actingAs($scoped)->get($this->exportsUrl("/{$others->id}/download"))->assertStatus(403);
        $this->actingAs($scoped)->getJson($this->exportsUrl("/{$others->id}"))->assertOk()->assertJsonPath('data.abilities.can_download', false);
        $this->actingAs($scoped)->postJson($this->exportsUrl("/{$own->id}/download-link"))->assertOk();

        $manager = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
        ]);
        $this->actingAs($manager)->postJson($this->exportsUrl("/{$others->id}/download-link"))->assertOk();
    }

    /**
     * 남의 개인정보 작업을 다시 요청하면 자기 사유가 있어야 하고 그 사유가 새 작업에 남는다. 자기 작업은 원래 사유를 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=retry, permission=personal_data
     *
     * @effects retry_of_others_personal_job_needs_own_reason
     */
    public function test_retry_of_others_personal_job_needs_own_reason(): void
    {
        $permissions = [
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.personal_data',
        ];
        $owner = $this->createAdminUser($permissions);
        // 다시 요청은 지금 내보낼 행이 있어야 받는다 — 조건(전체 주문)에 걸리는 주문 하나
        OrderFactory::new()->paid()->create();
        $failed = $this->export($owner, ['status' => ExportStatusEnum::FAILED->value, 'file_path' => null, 'contains_personal_data' => true, 'reason' => '원래 요청자의 사유']);

        $other = $this->createAdminUser($permissions);
        $this->actingAs($other)->postJson($this->exportsUrl("/{$failed->id}/retry"))->assertStatus(422)->assertJsonValidationErrors('reason');

        $id = (int) $this->actingAs($other)->postJson($this->exportsUrl("/{$failed->id}/retry"), ['reason' => '다시 요청한 사람의 사유'])->assertStatus(202)->json('data.id');
        $this->assertSame('다시 요청한 사람의 사유', Export::findOrFail($id)->reason);

        $ownId = (int) $this->actingAs($owner)->postJson($this->exportsUrl("/{$failed->id}/retry"))->assertStatus(202)->json('data.id');
        $this->assertSame('원래 요청자의 사유', Export::findOrFail($ownId)->reason);
    }

    /**
     * 개인정보 권한의 범위가 「본인만」이면 남이 요청한 개인정보 작업은 받지도, 다시 요청하지도, 받은 사람 IP 를 보지도
     * 못한다. 자기 개인정보 작업은 그대로 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download, permission=personal_data
     *
     * @effects personal_scope_limits_others_personal_jobs
     */
    public function test_personal_scope_self_limits_others_personal_jobs(): void
    {
        $permissions = [
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
            'sirsoft-ecommerce.exports.personal_data',
        ];
        $owner = $this->createAdminUser($permissions);
        $others = $this->export($owner, ['contains_personal_data' => true, 'reason' => '원래 요청자의 사유']);
        OrderFactory::new()->paid()->create();
        $othersFailed = $this->export($owner, ['status' => ExportStatusEnum::FAILED->value, 'file_path' => null, 'contains_personal_data' => true, 'reason' => '원래 요청자의 사유']);
        ExportDownload::query()->create([
            'export_id' => $others->id, 'user_id' => $owner->id, 'user_name_snapshot' => 'x',
            'ip_address' => '203.0.113.7', 'downloaded_at' => now(),
        ]);

        $operator = $this->createAdminUser($permissions);
        $this->setExportScope($operator, ScopeType::Self, ['personal_data']);
        $mine = $this->export($operator, ['contains_personal_data' => true, 'reason' => '내 사유']);

        $this->actingAs($operator)->postJson($this->exportsUrl("/{$others->id}/download-link"))->assertStatus(403);
        $this->actingAs($operator)->getJson($this->exportsUrl("/{$others->id}"))->assertOk()
            ->assertJsonPath('data.abilities.can_download', false);
        $this->actingAs($operator)->getJson($this->exportsUrl("/{$others->id}/downloads"))->assertOk()
            ->assertJsonPath('data.data.0.ip_address', null);
        $this->actingAs($operator)->postJson($this->exportsUrl("/{$othersFailed->id}/retry"), ['reason' => '다시 요청한 사람의 사유'])->assertStatus(403);
        $this->actingAs($operator)->postJson($this->exportsUrl("/{$mine->id}/download-link"))->assertOk();

        // 범위가 「전체」인 운영자는 같은 작업을 받고 IP 도 본다
        $this->actingAs($owner)->getJson($this->exportsUrl("/{$others->id}/downloads"))->assertOk()
            ->assertJsonPath('data.data.0.ip_address', '203.0.113.7');
    }

    /**
     * 다운로드 이력의 IP 는 개인정보 권한이 있어야 보인다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=download, permission=personal_data
     *
     * @effects download_history_ip_needs_personal_permission
     */
    public function test_download_history_ip_needs_personal_permission(): void
    {
        $viewer = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.exports.read']);
        $export = $this->export($viewer);
        ExportDownload::query()->create([
            'export_id' => $export->id, 'user_id' => $viewer->id, 'user_name_snapshot' => 'x',
            'ip_address' => '203.0.113.7', 'downloaded_at' => now(),
        ]);

        $this->actingAs($viewer)->getJson($this->exportsUrl("/{$export->id}/downloads"))->assertOk()->assertJsonPath('data.data.0.ip_address', null);

        $privileged = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.exports.read', 'sirsoft-ecommerce.exports.personal_data']);
        $this->actingAs($privileged)->getJson($this->exportsUrl("/{$export->id}/downloads"))->assertOk()->assertJsonPath('data.data.0.ip_address', '203.0.113.7');
    }

    /**
     * 자기 파일도 만들 때보다 원본 조회 범위가 좁아졌으면 받지 못한다 — 파일은 만들 때의 범위로 뽑혔다. 만들 때와 같은
     * 범위면(처음부터 「본인」이었으면) 그대로 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=self_scope
     *
     * @effects own_file_needs_scope_at_least_as_broad_as_when_generated
     */
    public function test_own_file_blocked_after_source_scope_narrows(): void
    {
        $permissions = [
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
        ];
        $body = [
            'type' => 'orders', 'format' => 'csv', 'column_mode' => 'custom', 'fields' => ['order.order_number'], 'row_unit' => 'order',
            'scope' => 'all', 'period' => ['mode' => 'custom', 'date_type' => 'ordered_at', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->toDateString()],
            'locale_mode' => 'locale', 'include_totals' => false,
        ];
        $generate = function (User $user, string $key) use ($body): int {
            $id = (int) $this->actingAs($user)->postJson($this->exportsUrl(), $body, ['Idempotency-Key' => $key])->assertStatus(202)->json('data.id');
            app(ExportService::class)->generate($id);
            $this->assertSame(ExportStatusEnum::COMPLETED, Export::findOrFail($id)->status);

            return $id;
        };

        // 전체 범위일 때 만든 파일 — 나중에 범위가 「본인」으로 좁혀지면 받지 못한다
        $wide = $this->createAdminUser($permissions);
        $wideFile = $generate($wide, 'scope-wide');
        $this->actingAs($wide)->postJson($this->exportsUrl("/{$wideFile}/download-link"))->assertOk();
        $this->narrowOrderScopeToSelf($wide);
        $this->actingAs($wide->fresh())->postJson($this->exportsUrl("/{$wideFile}/download-link"))->assertStatus(403);

        // 처음부터 「본인」 범위로 만든 파일 — 같은 범위라 받는다
        $narrow = $this->createAdminUser($permissions);
        $this->narrowOrderScopeToSelf($narrow);
        $narrowFile = $generate($narrow->fresh(), 'scope-narrow');
        $this->actingAs($narrow->fresh())->postJson($this->exportsUrl("/{$narrowFile}/download-link"))->assertOk();
    }
}
