<?php

namespace Tests\Support\Concerns;

use App\Benchmark\BenchmarkProfileRegistry;
use App\Benchmark\ListIndexAdvisor;
use App\Enums\BenchmarkAxis;

/**
 * 목록 프로파일이 요구하는 색인이 스키마에 있는지 검사하는 공통 로직.
 *
 * 코어 스위트는 코어 프로파일만, 각 모듈 스위트는 자기 프로파일만 검사한다. 모듈 테이블은
 * 그 모듈 스위트에서만 마이그레이션되므로, 코어 스위트에 모두 몰면 테이블이 없어 건너뛰고
 * **초록인 채 미검사**가 된다.
 *
 * 같은 이유로 "테이블이 없으면 skip" 도 하지 않는다. 검사 대상이 자기 스위트에 있는데
 * 테이블이 없다는 것은 마이그레이션이 빠졌다는 뜻이므로 실패로 보고한다.
 */
trait AssertsListIndexCoverage
{
    /**
     * 지정한 출처의 목록 프로파일이 요구하는 색인을 전수 검사합니다.
     *
     * @param  string  $sourceIdentifier  프로파일 출처 (코어는 'core', 모듈은 모듈 식별자)
     */
    protected function assertListIndexCoverage(string $sourceIdentifier): void
    {
        $registry = app(BenchmarkProfileRegistry::class);

        $profiles = array_filter(
            $registry->byAxis(BenchmarkAxis::ListQuery),
            fn ($profile) => $profile->sourceIdentifier === $sourceIdentifier
        );

        $this->assertNotEmpty(
            $profiles,
            "출처 '{$sourceIdentifier}' 의 목록 프로파일을 하나도 찾지 못했다 — "
            .'검사 모집단이 비어 이 테스트가 아무것도 검증하지 않는다'
        );

        $this->assertIndexCoverageForOptions(
            array_map(fn ($profile) => $profile->options ?? [], $profiles)
        );
    }

    /**
     * 확장 클래스의 선언을 직접 읽어 검사합니다.
     *
     * 확장 프로파일은 레지스트리에서 "활성 모듈" 을 거쳐 수집되는데, 테스트 DB 에는 확장이
     * 활성으로 등록돼 있지 않아 모집단이 비게 된다. 검사 대상은 DB 상태가 아니라 **코드에
     * 선언된 것**이므로 선언을 직접 읽는다.
     *
     * @param  object  $extension  모듈/플러그인 인스턴스
     */
    protected function assertListIndexCoverageForExtension(object $extension): void
    {
        $this->assertTrue(
            method_exists($extension, 'getBenchmarkProfiles'),
            get_class($extension).' 에 getBenchmarkProfiles() 가 없다 — 계측 대상 선언이 사라졌는지 확인할 것'
        );

        $declared = $extension->getBenchmarkProfiles();

        $lists = array_filter(
            $declared,
            fn ($options) => is_array($options) && ($options['type'] ?? null) === 'list'
        );

        $this->assertNotEmpty(
            $lists,
            get_class($extension).' 의 목록(list) 프로파일이 하나도 없다 — '
            .'검사 모집단이 비어 이 테스트가 아무것도 검증하지 않는다'
        );

        $this->assertIndexCoverageForOptions($lists);
    }

    /**
     * 프로파일 옵션 배열을 검사합니다.
     *
     * @param  array<string, array<string, mixed>>  $profileOptions  프로파일 키 → 옵션
     */
    private function assertIndexCoverageForOptions(array $profileOptions): void
    {
        $advisor = app(ListIndexAdvisor::class);
        $failures = [];

        foreach ($profileOptions as $key => $options) {
            $row = array_merge(
                ['profile' => (string) $key],
                $advisor->inspect($options),
                ['exemption' => $advisor->exemptionReason($options)]
            );
            if ($row['exemption'] !== null) {
                // 면제는 사유와 함께 선언된 경우에만 인정한다
                continue;
            }

            $required = implode(', ', $row['required']);

            $failures[] = match ($row['status']) {
                'satisfied' => null,
                'tiebreak_missing' => sprintf(
                    "%s (%s): 색인 '%s' 가 정렬을 덮지만 끝에 기본키가 없어 동률 구간에서 filesort 가 남는다. "
                    .'필요: (%s). 기존 색인에 기본키를 덧붙여 교체할 것',
                    $row['profile'],
                    $row['table'],
                    $row['partial_by'],
                    $required
                ),
                'missing' => sprintf(
                    '%s (%s): 정렬을 덮는 색인이 없어 뒤쪽 페이지가 테이블 전체를 훑는다. 필요: (%s)',
                    $row['profile'],
                    $row['table'],
                    $required
                ),
                'table_absent' => sprintf(
                    '%s (%s): 테이블이 없다 — 이 스위트에서 마이그레이션되지 않았다 (건너뛰면 미검사가 초록으로 보인다)',
                    $row['profile'],
                    $row['table']
                ),
                default => sprintf('%s: 대상 테이블 선언이 없다', $row['profile']),
            };
        }

        $failures = array_values(array_filter($failures));

        $this->assertSame(
            [],
            $failures,
            '목록 색인 미충족 '.count($failures)."건:\n  - ".implode("\n  - ", $failures)
            ."\n\n색인이 불필요한 목록(행 수 고정·상한이 작음)은 프로파일에 "
            ."'index_exempt' => '사유' 를 선언해 면제한다."
        );
    }
}
