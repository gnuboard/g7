<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상태 쓰기 게이트 패리티 테스트
 *
 * 신청(REQUESTED)·결제완료(PAID)·철회(WITHDRAWN)는 각각 접수·결제 확정·신청자 행위만이
 * 만드는 상태다. 관리자 쓰기 경로가 유형 Enum 의 `values()` 전체를 허용 집합으로 쓰면
 * 결제 없이 결제완료가 되거나 신청자가 하지 않은 철회가 기록된다 — 오류 없이 데이터만
 * 어긋나는 결함이다. 환불은 처리중·완료까지 환불 **실행**(`RefundExecutionService`) 소유라
 * 제외 폭이 더 넓다.
 *
 * 그래서 쓰기 경로는 `adminAssignableValues()` 를, 조회 필터 경로(`*ListRequest`)는
 * `values()` 전체를 쓴다 — 주문상태 게이트(`OrderStatusWriteGateParityTest`)와 같은 구분이며,
 * 이 테스트가 클레임 3유형 축에서 그 구분을 계약으로 고정한다. 모집단은 `Http/Requests`
 * 전수 스캔으로 도출한다 — 열거는 새로 추가된 Request 를 증명하지 못한다.
 */
class ClaimStatusWriteGateParityTest extends ModuleTestCase
{
    /**
     * 클레임 상태 **허용 집합**을 만드는 표현식들. (단건 case 참조는 비교·분기용이라 대상이 아니다)
     *
     * @var array<int, string>
     */
    protected const SET_PRODUCERS = [
        'ExchangeStatusEnum::values()',
        'ExchangeStatusEnum::cases()',
        'ReturnStatusEnum::values()',
        'ReturnStatusEnum::cases()',
        'RefundStatusEnum::values()',
        'RefundStatusEnum::cases()',
    ];

    /**
     * 모듈의 FormRequest 소스 파일을 전수 수집합니다.
     *
     * @return array<string, string> 파일 경로 => 소스
     */
    protected function requestSources(): array
    {
        $dir = dirname(__DIR__, 3).'/src/Http/Requests';
        $sources = [];

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $sources[$file->getPathname()] = File::get($file->getPathname());
        }

        return $sources;
    }

    /**
     * 조회 필터 경로인지 판정합니다 (`*ListRequest` = 목록 필터 — values() 전체가 정답).
     *
     * @param  string  $path  파일 경로
     * @return bool 조회 경로면 true
     */
    protected function isFilterRequest(string $path): bool
    {
        return str_ends_with(basename($path, '.php'), 'ListRequest');
    }

    public function test_request_population_is_not_empty(): void
    {
        // 판정기 자신이 빈 모집단을 보면 아래 단언들이 전부 공허하게 통과한다.
        $sources = $this->requestSources();

        $this->assertGreaterThan(20, count($sources), 'FormRequest 스캔 결과가 비어 있습니다 — 경로를 확인하세요.');
        $this->assertNotEmpty(
            array_filter(array_keys($sources), fn ($p) => ! $this->isFilterRequest($p)),
            '쓰기 경로 FormRequest 가 한 건도 잡히지 않았습니다.'
        );
    }

    public function test_write_requests_do_not_use_full_claim_status_values(): void
    {
        $offenders = [];

        foreach ($this->requestSources() as $path => $source) {
            if ($this->isFilterRequest($path)) {
                continue; // 조회 필터는 전 상태가 대상이라 values() 전체가 정답이다.
            }
            foreach (self::SET_PRODUCERS as $producer) {
                if (str_contains($source, $producer)) {
                    $offenders[] = basename($path).' ('.$producer.')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '쓰기 경로 FormRequest 는 클레임 상태 허용 집합을 adminAssignableValues() 로 만들어야 합니다: '
            .implode(', ', $offenders)
        );
    }

    public function test_exchange_admin_assignable_excludes_exactly_owned_path_statuses(): void
    {
        // 제외 3종을 정확히 고정한다 — 새 case 가 생기면 아래 개수 단언이 깨져 판정을 강제한다.
        $excluded = ['requested', 'paid', 'withdrawn'];

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(ExchangeStatusEnum::values(), $excluded)),
            ExchangeStatusEnum::adminAssignableValues(),
        );
        $this->assertCount(14, ExchangeStatusEnum::values(), '교환 상태 case 가 변했습니다 — 관리자 지정 가능 여부를 판정해 이 테스트를 갱신하세요.');
        $this->assertCount(11, ExchangeStatusEnum::adminAssignableValues());
    }

    public function test_return_admin_assignable_excludes_exactly_owned_path_statuses(): void
    {
        $excluded = ['requested', 'paid', 'withdrawn'];

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(ReturnStatusEnum::values(), $excluded)),
            ReturnStatusEnum::adminAssignableValues(),
        );
        $this->assertCount(11, ReturnStatusEnum::values(), '반품 상태 case 가 변했습니다 — 관리자 지정 가능 여부를 판정해 이 테스트를 갱신하세요.');
        $this->assertCount(8, ReturnStatusEnum::adminAssignableValues());
    }

    public function test_refund_admin_assignable_is_exactly_the_review_axis(): void
    {
        // 환불은 승인·보류·반려만 — 처리중·완료는 환불 실행 소유, 신청·철회는 유저 소유.
        $this->assertEqualsCanonicalizing(
            ['approved', 'on_hold', 'rejected'],
            RefundStatusEnum::adminAssignableValues(),
        );
        $this->assertCount(7, RefundStatusEnum::values(), '환불 상태 case 가 변했습니다 — 관리자 지정 가능 여부를 판정해 이 테스트를 갱신하세요.');
    }
}
