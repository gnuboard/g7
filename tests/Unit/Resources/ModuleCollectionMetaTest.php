<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\ModuleCollection;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 모듈 목록 응답 메타의 활성 모듈 수 (7.1.0 전수 실측 #736 O-F2).
 *
 * 목록 행은 매니저가 만든 배열이고 활성 여부는 `status` 로 온다. 메타는 행에 없는 `is_active` 로 세어,
 * 활성 모듈이 3개인데도 `meta.active_modules` 가 늘 0 으로 나갔다. 플러그인 목록(`active_plugins`)처럼 상태로 센다.
 */
class ModuleCollectionMetaTest extends TestCase
{
    /**
     * 활성 모듈 수는 행의 상태(status=active)로 센다.
     *
     * @scenario case=status_active_rows
     *
     * @effects module_list_meta_counts_active_by_status
     *
     * @return void 반환값 없음
     */
    public function test_active_modules_counts_status_active_rows(): void
    {
        $rows = collect([
            ['identifier' => 'acme-a', 'status' => 'active'],
            ['identifier' => 'acme-b', 'status' => 'active'],
            ['identifier' => 'acme-c', 'status' => 'inactive'],
            ['identifier' => 'acme-d', 'status' => 'uninstalled'],
        ]);

        $meta = (new ModuleCollection($rows))->with(Request::create('/'))['meta'];

        $this->assertSame(4, $meta['total_modules']);
        $this->assertSame(2, $meta['active_modules']);
    }
}
