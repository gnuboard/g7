<?php

namespace Tests\Unit\Benchmark;

use App\Benchmark\DTO\BenchmarkProfile;
use App\Enums\BenchmarkAxis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 계측 프로파일 VO 및 축 Enum 계약 단위 테스트.
 *
 * 축이 늘어나도 VO 를 고치지 않는다는 설계(축 고유 옵션은 `options` 에 남김)와, 변경 판정이
 * 어디서 결정되는지(축 기본값 → HTTP 메서드 → 선언 순)를 고정한다.
 */
class BenchmarkProfileTest extends TestCase
{
    /**
     * 프로파일을 만듭니다.
     *
     * @param  BenchmarkAxis  $axis  계측 축
     * @param  array<string, mixed>  $options  축 고유 옵션
     * @param  string  $sourceIdentifier  출처 식별자
     * @return BenchmarkProfile 프로파일
     */
    private function profile(BenchmarkAxis $axis, array $options = [], string $sourceIdentifier = 'core'): BenchmarkProfile
    {
        return new BenchmarkProfile(
            key: 'sample',
            axis: $axis,
            sourceKind: $sourceIdentifier === 'core' ? 'core' : 'module',
            sourceIdentifier: $sourceIdentifier,
            options: $options,
            label: '샘플',
        );
    }

    /**
     * 정규화 키는 출처와 키를 합쳐 전역 고유해집니다.
     */
    #[Test]
    public function 정규화_키는_출처를_포함한다(): void
    {
        $this->assertSame('core/sample', $this->profile(BenchmarkAxis::ListQuery)->qualifiedKey());
        $this->assertSame(
            'vendor-mod/sample',
            $this->profile(BenchmarkAxis::ListQuery, [], 'vendor-mod')->qualifiedKey()
        );
    }

    /**
     * 축 고유 옵션은 기본값과 함께 읽힙니다.
     */
    #[Test]
    public function 옵션은_기본값과_함께_읽힌다(): void
    {
        $profile = $this->profile(BenchmarkAxis::ListQuery, ['table' => 'users']);

        $this->assertSame('users', $profile->option('table'));
        $this->assertSame(['id'], $profile->option('columns', ['id']));
        $this->assertNull($profile->option('absent'));
    }

    /**
     * 목록/화면 축은 기본적으로 데이터를 변경하지 않고, 쓰기/배치 축은 변경합니다.
     */
    #[Test]
    public function 축_기본_변경_판정(): void
    {
        $this->assertFalse($this->profile(BenchmarkAxis::ListQuery)->mutates());
        $this->assertFalse($this->profile(BenchmarkAxis::Screen)->mutates());
        $this->assertTrue($this->profile(BenchmarkAxis::Write)->mutates());
        $this->assertTrue($this->profile(BenchmarkAxis::Batch)->mutates());
    }

    /**
     * 화면 축의 변경 판정은 선언된 HTTP 메서드를 따릅니다.
     *
     * @effects screen_mutation_verdict_follows_http_method
     */
    #[Test]
    public function 화면_축은_http_메서드로_변경을_판정한다(): void
    {
        $this->assertFalse($this->profile(BenchmarkAxis::Screen, ['method' => 'GET'])->mutates());
        // 대소문자와 무관하게 판정한다
        $this->assertFalse($this->profile(BenchmarkAxis::Screen, ['method' => 'get'])->mutates());
        $this->assertTrue($this->profile(BenchmarkAxis::Screen, ['method' => 'POST'])->mutates());
        $this->assertTrue($this->profile(BenchmarkAxis::Screen, ['method' => 'DELETE'])->mutates());
    }

    /**
     * 명시 선언이 축 기본값과 메서드 판정을 모두 덮어씁니다.
     *
     * 읽기 전용 배치(리포트 산출 등)를 `--allow-write` 없이 재려면 이 경로가 필요합니다.
     *
     * @effects explicit_mutating_declaration_overrides_axis_default
     */
    #[Test]
    public function 명시_mutating_선언이_기본값을_덮는다(): void
    {
        $this->assertFalse($this->profile(BenchmarkAxis::Batch, ['mutating' => false])->mutates());
        $this->assertTrue($this->profile(BenchmarkAxis::ListQuery, ['mutating' => true])->mutates());
        $this->assertFalse(
            $this->profile(BenchmarkAxis::Screen, ['method' => 'POST', 'mutating' => false])->mutates()
        );
    }

    /**
     * 직렬화 결과가 리포트/JSON 이 기대하는 키를 담습니다.
     */
    #[Test]
    public function 직렬화가_약속된_키를_담는다(): void
    {
        $payload = $this->profile(BenchmarkAxis::ListQuery, ['table' => 'users'])->toArray();

        foreach (['key', 'qualified_key', 'axis', 'source', 'label', 'options'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertSame('list', $payload['axis']);
        $this->assertSame(['kind' => 'core', 'identifier' => 'core'], $payload['source']);
        $this->assertSame(['table' => 'users'], $payload['options']);
    }

    /**
     * 축마다 필수 옵션이 대안 그룹으로 선언됩니다.
     *
     * 화면 축이 라우트명 또는 URI 중 하나만 있으면 되는 성질이 이 구조에 담깁니다.
     *
     * @effects screen_axis_accepts_route_or_uri_alternative
     */
    #[Test]
    public function 축별_필수_옵션은_대안_그룹으로_선언된다(): void
    {
        $this->assertSame([['table']], BenchmarkAxis::ListQuery->requiredOptions());
        $this->assertSame([['route', 'uri']], BenchmarkAxis::Screen->requiredOptions());
        $this->assertSame([['callback']], BenchmarkAxis::Write->requiredOptions());
        $this->assertSame([['command']], BenchmarkAxis::Batch->requiredOptions());
    }

    /**
     * 축 값 목록과 라벨이 정의됩니다.
     */
    #[Test]
    public function 축_값과_라벨이_정의된다(): void
    {
        $this->assertSame(['list', 'screen', 'write', 'batch'], BenchmarkAxis::values());

        foreach (BenchmarkAxis::cases() as $axis) {
            $this->assertNotSame('', $axis->label());
        }
    }
}
