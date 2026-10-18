<?php

namespace Tests\Feature\Console;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiDoc\ApiDocSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API 문서 실측 샘플 권한 정리 테스트
 *
 * 2026-10-07 실측(O-D2): 개발 DB 관리자 권한 트리에 docgen 이 남긴 「API 문서 샘플 권한」·「하위 권한」이 실제 권한처럼
 * 보였고, 하위 권한은 실제 역할에 배정할 수 있었다. 실측이 끝나면 샘플 권한과 그 역할 연결만 지우는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/apidoc-sample-permission-purge.yaml
 */
class ApiDocSamplePermissionPurgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 샘플 권한(부모·하위)과 그 역할 연결을 지우고, 다른 샘플은 남긴다. 다음 실행은 다시 만든다.
     *
     * @scenario sample=permission_purge
     *
     * @effects apidoc_sample_permissions_purged_after_probe
     *
     * @return void 반환값 없음
     */
    public function test_purge_removes_only_sample_permissions_and_their_role_links(): void
    {
        $service = new ApiDocSampleService;
        $service->seed();

        $sampleIds = Permission::where('identifier', 'like', 'apidoc-sample.%')->pluck('id')->all();
        $this->assertCount(2, $sampleIds, '샘플 권한(부모·하위)이 시드되지 않았다');

        // 운영자가 샘플 권한을 실제 역할에 배정해 둔 상태
        $role = Role::factory()->create(['identifier' => 'qa_sample_link_role']);
        $role->permissions()->attach($sampleIds);
        $otherPermissionCount = Permission::where('identifier', 'not like', 'apidoc-sample.%')->count();

        $this->assertSame(2, $service->purgeAssignableSamples());

        $this->assertSame(0, Permission::where('identifier', 'like', 'apidoc-sample.%')->count(), '샘플 권한이 남았다');
        $this->assertSame(0, $role->permissions()->count(), '샘플 권한의 역할 연결이 남았다');
        $this->assertSame($otherPermissionCount, Permission::count(), '샘플이 아닌 권한까지 지웠다');
        $this->assertNotNull(Role::where('identifier', 'apidoc-sample-role')->first(), '샘플 역할까지 지웠다');
        $this->assertNotNull(User::where('email', 'apidoc-sample-user@example.com')->first(), '샘플 사용자까지 지웠다');

        // 다음 실행은 다시 만든다(멱등)
        $service->seed();
        $this->assertSame(2, Permission::where('identifier', 'like', 'apidoc-sample.%')->count());
    }

    /**
     * api:docgen --seed 실행이 끝나면 샘플 권한이 DB 에 남지 않는다.
     *
     * @scenario sample=docgen_seed_run
     *
     * @effects apidoc_sample_permissions_purged_after_probe
     *
     * @return void 반환값 없음
     */
    public function test_docgen_seed_run_leaves_no_sample_permissions(): void
    {
        // 읽기 실측은 외부 HTTP 로 나간다 — 개발 사이트로 요청하지 않게 막는다
        Http::fake();

        $this->artisan('api:docgen', ['--scope' => 'plugin:sirsoft-ckeditor5', '--check' => true, '--seed' => true])
            ->assertSuccessful();

        $this->assertSame(0, Permission::where('identifier', 'like', 'apidoc-sample.%')->count());
    }
}
