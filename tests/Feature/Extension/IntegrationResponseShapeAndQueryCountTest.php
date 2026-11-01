<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\Support\IntegrationReverseIndex;
use App\Extension\Support\LifecycleWarnings;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\BaseApiController;
use App\Http\Controllers\Concerns\RespondsWithIntegrationNotices;
use App\Http\Resources\ModuleResource;
use App\Models\Module;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 연동 안내를 응답에 실어도 빈 객체가 유지되고, 목록의 「연동 중인 확장」은 요청당 한 번만 조회한다 (#822 검토 F2·F4)
 *
 * - F4: 응답 본문을 연관 배열로 풀었다 다시 쓰면 빈 객체 `{}` 가 `[]` 로 바뀐다(#758 결함군). 객체로 풀어 고친다.
 * - F2: 목록 행마다 저장소 3종을 조회하면 20행에 약 60쿼리가 된다. 역맵을 요청 스코프로 한 번만 만든다.
 */
class IntegrationResponseShapeAndQueryCountTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 연동 안내·연동 중인 확장·부분 실패 경고를 실어도 `data` 안의 빈 객체는 `{}` 그대로다.
     *
     * @scenario case=response_keeps_empty_objects
     *
     * @effects integration_response_keeps_empty_objects
     *
     * @return void 반환값 없음
     */
    public function test_response_keeps_empty_objects(): void
    {
        $harness = new class
        {
            use RespondsWithIntegrationNotices {
                withIntegrationNotices as public;
                withIntegratedBy as public;
            }
        };
        $data = ['config' => new \stdClass, 'nested' => ['settings' => new \stdClass, 'list' => []]];

        $notices = [['type' => 'module', 'identifier' => 'acme-x', 'name' => 'acme-x', 'state' => 'not_installed', 'required_version' => '*', 'installed_version' => null, 'message' => 'acme-x 미설치']];
        $content = $harness->withIntegrationNotices(ResponseHelper::success('common.success', $data), $notices)->getContent();
        $this->assertStringContainsString('"config":{}', $content);
        $this->assertStringContainsString('"settings":{},"list":[]', $content);
        $this->assertStringContainsString('"integration_notices":[{', $content);

        $content = $harness->withIntegratedBy(ResponseHelper::success('common.success', $data), 'module', 'acme-nobody')->getContent();
        $this->assertStringContainsString('"config":{}', $content);
        $this->assertStringContainsString('"integrated_by":[]', $content);

        // 같은 결함군 — 생명주기 부분 실패 경고(data.warnings)를 싣는 기반 컨트롤러 경로
        LifecycleWarnings::current()->add('layout_register', 'a.json', '실패');
        $controller = new class extends BaseApiController
        {
            /**
             * 성공 응답을 그대로 노출합니다.
             *
             * @param  mixed  $data  응답 데이터
             * @return JsonResponse 성공 응답
             */
            public function exposeSuccess(mixed $data): JsonResponse
            {
                return $this->success('common.success', $data);
            }
        };
        $content = $controller->exposeSuccess($data)->getContent();
        $this->assertStringContainsString('"config":{}', $content);
        $this->assertStringContainsString('"warnings":[{', $content);
    }

    /**
     * 목록 20행에 `integrated_by` 를 실어도 활성 확장 조회는 요청당 한 번이다 — 행 수에 비례하지 않는다.
     * 확장 상태가 바뀌면(상태 캐시 무효화) 역맵을 버리고 다시 만든다.
     *
     * @scenario case=integrated_by_query_count
     *
     * @effects integrated_by_built_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_integrated_by_queries_do_not_grow_with_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = Module::factory()->create(['identifier' => "acme-m{$i}", 'status' => ExtensionStatus::Active->value]);
        }
        Module::factory()->create(['identifier' => 'acme-shop', 'name' => ['ko' => '쇼핑몰', 'en' => 'Shop'], 'status' => ExtensionStatus::Active->value]);

        $this->mock(ModuleManagerInterface::class, function ($mock): void {
            $mock->shouldReceive('getModule')->andReturnUsing(function (string $id) {
                $instance = Mockery::mock(ModuleInterface::class);
                $instance->shouldReceive('getIntegrations')->andReturn([
                    'modules' => $id === 'acme-shop' ? ['acme-m1', 'acme-m2'] : [],
                    'plugins' => [],
                    'templates' => [],
                ]);

                return $instance;
            });
        });

        app()->setLocale('ko');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $payloads = array_map(
            fn (Module $row) => (new ModuleResource(['identifier' => $row->identifier, 'name' => $row->identifier, 'status' => 'active']))->toArray(Request::create('/')),
            $rows,
        );
        $queries = count(DB::getQueryLog());

        $this->assertSame([['type' => 'module', 'identifier' => 'acme-shop', 'name' => '쇼핑몰']], $payloads[0]['integrated_by']);
        $this->assertSame('acme-shop', $payloads[1]['integrated_by'][0]['identifier']);
        $this->assertSame([], $payloads[2]['integrated_by']);
        $this->assertLessThanOrEqual(10, $queries, "20행 integrated_by 에 {$queries}쿼리 — 행마다 조회하면 약 60쿼리");

        // 상태가 바뀌면 같은 요청에서도 다시 만든다
        Module::where('identifier', 'acme-shop')->update(['status' => ExtensionStatus::Inactive->value]);
        ModuleManager::invalidateModuleStatusCache();
        $this->assertSame([], app(IntegrationReverseIndex::class)->integratedBy('module', 'acme-m1'));
    }
}
