<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 생애주기(모듈 비활성화·제거)·설정·기본 권한 테스트
 *
 * - 비활성 동안에는 정리 스케줄이 돌지 않으므로, 끄기 직전에 보관 기간이 지난 파일을 정리한다(남은 기간의 파일은 둔다).
 * - 제거는 「데이터 유지」여도 개인정보가 담길 수 있는 파일을 모두 지우고 기록을 만료로 바꾼다.
 * - 보관 기간 설정은 1~30일 밖이면 저장이 거절된다.
 * - 개인정보 포함·다른 운영자 내보내기 관리 권한의 기본 역할은 관리자뿐이다.
 */
class ExportLifecycleTest extends ExportTestCase
{
    /**
     * 파일이 있는 완료 작업을 만듭니다.
     *
     * @param  string  $expiresAt  만료 시각
     * @return Export 작업
     */
    private function completedExport(string $expiresAt): Export
    {
        $user = $this->createAdminUser([]);
        $path = 'fixture/'.uniqid().'.csv';
        Storage::disk('modules')->put($this->storedPath($path), "a,b\r\n");

        return Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::COMPLETED->value,
            'requested_by' => $user->id,
            'options' => ['fields' => ['order.order_number']],
            'file_name' => 'orders.csv',
            'file_path' => $path,
            'requested_at' => now()->subDays(10),
            'completed_at' => now()->subDays(10),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 끄기 직전에 만료 파일만 정리하고, 기간이 남은 파일은 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects deactivate_prunes_expired_files
     */
    public function test_deactivate_prunes_only_expired_files(): void
    {
        $expired = $this->completedExport(now()->subDay()->toDateTimeString());
        $alive = $this->completedExport(now()->addDays(3)->toDateTimeString());

        $this->assertTrue((new Module)->deactivate());

        $expiredPath = (string) $expired->file_path;
        $this->assertSame(ExportStatusEnum::EXPIRED, $expired->refresh()->status);
        Storage::disk('modules')->assertMissing($this->storedPath($expiredPath));
        $this->assertSame(ExportStatusEnum::COMPLETED, $alive->refresh()->status);
        Storage::disk('modules')->assertExists($this->storedPath((string) $alive->file_path));
    }

    /**
     * 제거하면 파일을 모두 지우고, 남는 기록은 만료로 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects uninstall_purges_all_files
     */
    public function test_uninstall_purges_all_files(): void
    {
        $alive = $this->completedExport(now()->addDays(3)->toDateTimeString());
        $path = (string) $alive->file_path;

        (new Module)->purgeExportFiles();

        Storage::disk('modules')->assertMissing($this->storedPath($path));
        $alive->refresh();
        $this->assertSame(ExportStatusEnum::EXPIRED, $alive->status);
        $this->assertNull($alive->file_path);
    }

    /**
     * 보관 기간은 1~30일만 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=settings
     *
     * @effects retention_range_validated
     */
    public function test_retention_days_must_be_between_1_and_30(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $url = '/api/modules/'.self::IDENTIFIER.'/admin/settings';
        $basic = fn (int $days): array => [
            '_tab' => 'basic_info',
            'basic_info' => ['shop_name' => '테스트샵', 'route_path' => 'shop', 'export_retention_days' => $days],
        ];

        foreach ([0, 31] as $invalid) {
            $this->actingAs($admin)->putJson($url, $basic($invalid))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['basic_info.export_retention_days']);
        }

        $this->actingAs($admin)->putJson($url, $basic(14))->assertOk();
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $this->assertSame(14, (int) $settings->getSetting('basic_info.export_retention_days'));
    }

    /**
     * 개인정보 포함 내보내기는 기본으로 관리자에게만 준다. 다른 운영자의 내보내기를 다루는 범위는 별도 권한이 아니라
     * 권한 범위로 정한다({@see ExportPermissionScopeDeclarationTest}).
     *
     * @return void 반환값 없음
     *
     * @scenario permission=personal_data
     *
     * @effects sensitive_permissions_default_admin_only
     */
    public function test_sensitive_permissions_default_to_admin_only(): void
    {
        $roles = [];
        $category = collect((new Module)->getPermissions()['categories'])->firstWhere('identifier', 'exports');
        foreach ($category['permissions'] as $permission) {
            $roles[$permission['action']] = $permission['roles'];
        }

        $this->assertSame(['admin'], $roles['personal_data']);
        $this->assertArrayNotHasKey('all', $roles);
        $this->assertSame(['admin', 'sirsoft-ecommerce.manager'], $roles['create']);
    }

    /**
     * 「내보내기 관리」 메뉴는 이커머스 하위 맨 뒤에 조회 권한으로 걸리고, 정리 예약 작업은 등록된 명령을 가리킨다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=declaration
     *
     * @effects menu_and_schedule_declared
     */
    public function test_menu_and_prune_schedule_are_declared(): void
    {
        $module = new Module;
        $children = collect($module->getAdminMenus()[0]['children']);
        $menu = $children->firstWhere('slug', 'sirsoft-ecommerce-exports');

        $this->assertNotNull($menu);
        $this->assertSame('/admin/ecommerce/exports', $menu['url']);
        $this->assertSame('sirsoft-ecommerce.exports.read', $menu['permission']);
        $this->assertSame($children->max('order'), $menu['order'], '다른 하위 메뉴의 순서를 밀어내면 안 됩니다.');

        $schedule = collect($module->getSchedules())->firstWhere('name', 'prune-exports');
        $this->assertNotNull($schedule);
        $this->assertArrayHasKey($schedule['command'], Artisan::all());
    }
}
