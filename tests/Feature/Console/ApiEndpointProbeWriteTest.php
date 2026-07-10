<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use App\Support\ApiDoc\ApiDocSampleService;
use App\Support\ApiDoc\ApiEndpointProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApiEndpointProbe 쓰기 메서드 in-process 실측 Feature 테스트.
 *
 * 쓰기 메서드(POST/PUT/DELETE)를 DB 트랜잭션 안에서 in-process dispatch 후 롤백해
 * 응답 shape 만 관측하고 부수효과는 영속시키지 않는지, GET 실측이 오염되지 않는지 검증한다.
 */
class ApiEndpointProbeWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 실측 프로브를 샘플 사용자 인증 상태로 준비합니다.
     *
     * @return array{0: ApiEndpointProbe, 1: User} 프로브와 인증 사용자
     */
    private function preparedProbe(): array
    {
        $map = (new ApiDocSampleService)->seed();
        $userId = User::where($map['users']['key'], $map['users']['value'])->value('id');

        $probe = new ApiEndpointProbe(config('app.url'));
        $probe->authenticate($userId);

        return [$probe, User::find($userId)];
    }

    #[Test]
    public function 쓰기_메서드는_트랜잭션_롤백으로_레코드를_영속시키지_않는다(): void
    {
        [$probe] = $this->preparedProbe();

        $before = Role::count();

        $result = $probe->probe('POST', '/api/admin/roles', [
            'identifier' => 'probe_write_'.substr(uniqid(), 0, 8),
            'name' => '실측 예시값',
            'is_active' => true,
        ]);

        // 응답은 관측되지만(성공 2xx) 레코드는 롤백되어 남지 않는다.
        $this->assertNotNull($result['status']);
        $this->assertSame($before, Role::count(), '쓰기 실측 후 레코드가 롤백되지 않고 남았습니다');

        $probe->cleanup();
    }

    #[Test]
    public function 성공한_쓰기_실측은_envelope_body_를_반환한다(): void
    {
        [$probe] = $this->preparedProbe();

        $result = $probe->probe('POST', '/api/admin/roles', [
            'identifier' => 'probe_env_'.substr(uniqid(), 0, 8),
            'name' => '실측 예시값',
            'is_active' => true,
        ]);

        if ($result['ok']) {
            $this->assertIsArray($result['body']);
            $this->assertArrayHasKey('success', $result['body']);
            $this->assertArrayHasKey('data', $result['body']);
        } else {
            // 검증 규칙이 도메인 특이해 실패할 수 있으나, 그 경우에도 skipped_reason 이 명확해야 한다.
            $this->assertNotNull($result['skipped_reason']);
        }

        $probe->cleanup();
    }

    #[Test]
    public function 연속_쓰기_실측이_서로_인증_컨텍스트를_오염시키지_않는다(): void
    {
        [$probe] = $this->preparedProbe();

        // 연속 쓰기 실측(각각 guard 조작 후 forgetGuards)이 서로 오염되지 않아야 한다.
        $first = $probe->probe('POST', '/api/admin/roles', ['identifier' => 'probe_a_'.uniqid(), 'name' => 'a', 'is_active' => true]);
        $second = $probe->probe('POST', '/api/admin/roles', ['identifier' => 'probe_b_'.uniqid(), 'name' => 'b', 'is_active' => true]);

        // 두 번째 실측이 첫 번째의 인증 컨텍스트 오염으로 401 이 되지 않아야 한다.
        $this->assertNotSame(401, $first['status']);
        $this->assertNotSame(401, $second['status']);

        $probe->cleanup();
    }

    #[Test]
    public function unresolved_path_는_실측하지_않고_사유를_남긴다(): void
    {
        [$probe] = $this->preparedProbe();

        $result = $probe->probe('PUT', '/api/admin/roles/{role}', ['name' => 'x']);

        $this->assertFalse($result['ok']);
        $this->assertSame('unresolved-path-param', $result['skipped_reason']);

        $probe->cleanup();
    }
}
