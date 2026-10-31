<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\NotificationTemplate;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ExportNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 사이트 내 알림을 누르면 내보내기 관리 화면으로 간다
 *
 * 알림 센터는 같은 탭 이동을 same-origin 절대 경로(`/…`)로만 허용하고, 스킴·호스트가 붙은 주소는 첫 화면(`/`)으로
 * 떨어뜨린다. 그래서 클릭 주소는 사이트 주소를 붙이지 않은 경로여야 한다.
 */
class ExportNotificationLinkTest extends ExportTestCase
{
    /**
     * 완료·실패 알림의 클릭 주소가 내보내기 관리 경로로 치환된다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=notify
     *
     * @effects notification_click_opens_export_management
     */
    public function test_click_url_is_same_origin_export_management_path(): void
    {
        $user = $this->createAdminUser([]);
        $export = Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'products',
            'format' => 'csv',
            'status' => ExportStatusEnum::COMPLETED->value,
            'requested_by' => $user->id,
            'options' => ['fields' => ['product.name']],
            'file_name' => 'products.csv',
            'processed_rows' => 100,
            'requested_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $definitions = array_values(array_filter(
            (new Module)->getNotificationDefinitions(),
            fn (array $definition) => in_array($definition['type'], ['export_completed', 'export_failed'], true),
        ));
        $this->assertCount(2, $definitions);

        foreach ($definitions as $definition) {
            $data = app(ExportNotificationDataListener::class)
                ->extractData([], $definition['type'], [$export])['data'];

            foreach ($definition['templates'] as $template) {
                $clickUrl = (new NotificationTemplate(['click_url' => $template['click_url']]))
                    ->replaceVariablesInString($template['click_url'], $data);

                $this->assertSame('/admin/ecommerce/exports', $clickUrl, $definition['type']);
            }
        }
    }
}
