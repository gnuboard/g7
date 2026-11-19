<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiRouteInventory;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ApiRouteInventory 문서 도메인 그룹 산출 — 하위 리소스 분리 (#17).
 *
 * 큐 관리 API 는 스케줄 관리 화면 아래 경로(`/api/admin/schedules/queue/*`, 라우트명
 * `api.admin.schedules.queue.*`)에 있지만 별도 레퍼런스 문서(`queue.md`)로 다룬다.
 * 도메인을 리소스 첫 세그먼트로만 정하면 큐 10개 엔드포인트가 `schedules.md` 로 묶여,
 * 생성기를 돌릴 때마다 큐 블록이 스케줄 문서에 다시 생기고 `queue.md` 는 색인에서 빠진다.
 *
 * 시나리오 매니페스트: tests/scenarios/api-doc-domain-group.yaml
 */
class ApiRouteInventoryDomainGroupTest extends TestCase
{
    /**
     * private domainGroup() 을 호출합니다.
     *
     * @param  string  $name  라우트명
     * @param  string  $uri  라우트 URI
     * @return string 도메인 키
     */
    private function domainOf(string $name, string $uri): string
    {
        $method = new ReflectionMethod(ApiRouteInventory::class, 'domainGroup');

        return $method->invoke(app(ApiRouteInventory::class), $name, $uri, ['type' => 'core', 'id' => null, 'key' => 'core']);
    }

    /**
     * 스케줄 아래 큐 관리 라우트는 queue 도메인으로 분리된다.
     *
     * @scenario route=schedules_queue
     *
     * @effects queue_routes_grouped_into_queue_doc
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 스케줄_아래_큐_라우트는_queue_도메인이다(): void
    {
        $this->assertSame('queue', $this->domainOf('api.admin.schedules.queue.status', '/api/admin/schedules/queue/status'));
        $this->assertSame('queue', $this->domainOf('api.admin.schedules.queue.failed.retry-bulk', '/api/admin/schedules/queue/failed/retry'));
        $this->assertSame('queue', $this->domainOf('api.admin.schedules.queue.logs.show', '/api/admin/schedules/queue/logs/{id}'));
    }

    /**
     * 스케줄 자신의 라우트는 그대로 schedules 도메인이다.
     *
     * @scenario route=schedules_self
     *
     * @effects schedule_routes_stay_in_schedules_doc
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 스케줄_자체_라우트는_schedules_도메인을_유지한다(): void
    {
        $this->assertSame('schedules', $this->domainOf('api.admin.schedules.index', '/api/admin/schedules'));
        $this->assertSame('schedules', $this->domainOf('api.admin.schedules.owners', '/api/admin/schedules/owners'));
        $this->assertSame('schedules', $this->domainOf('api.admin.schedules.run', '/api/admin/schedules/{schedule}/run'));
    }
}
