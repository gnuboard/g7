<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문상태 쓰기 게이트 패리티 테스트
 *
 * 클레임 코스 상태 4종(교환진행중/반품진행중/교환완료/반품완료)은 교환·반품 서비스가
 * 신청서와 짝을 맞춰 기록하는 값이다. 관리자가 주문 화면에서 직접 지정하면 신청서 없는
 * 코스 상태가 남아 이후 클레임 흐름과 어긋난다 — 오류 없이 데이터만 어긋나는 결함이라
 * 화면에서도 로그에서도 드러나지 않는다.
 *
 * 그래서 쓰기 경로는 `OrderStatusEnum::adminAssignableValues()`(코스 4종 제외)를,
 * 조회 필터 경로는 `values()` 전체를 쓴다. 이 구분은 현재 세 FormRequest 에 관습으로만
 * 존재하고 강제 장치가 없어서, 새 쓰기 엔드포인트가 `values()` 를 쓰면 게이트가 조용히
 * 넓어진다. 본 테스트가 그 구분을 계약으로 고정한다.
 *
 * 모집단은 손으로 적은 클래스 목록이 아니라 `Http/Requests` 디렉토리 전수 스캔으로
 * 도출한다 — 열거는 새로 추가된 Request 를 증명하지 못한다.
 */
class OrderStatusWriteGateParityTest extends ModuleTestCase
{
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
     * 조회 필터 경로인지 판정합니다 (`*ListRequest` = 목록 필터).
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

    /**
     * 주문상태 **허용 집합**을 만드는 표현식들.
     *
     * `OrderStatusEnum::CANCELLED` 같은 단건 참조는 비교·분기에 쓰이므로 대상이 아니다 —
     * 게이트가 문제되는 것은 규칙의 허용 집합을 통째로 생산할 때뿐이다.
     *
     * @var array<int, string>
     */
    protected const SET_PRODUCERS = [
        'OrderStatusEnum::values()',
        'OrderStatusEnum::cases()',
    ];

    protected const GATE = 'OrderStatusEnum::adminAssignableValues()';

    public function test_write_requests_do_not_use_full_order_status_values(): void
    {
        $offenders = [];

        foreach ($this->requestSources() as $path => $source) {
            if ($this->isFilterRequest($path)) {
                continue; // 조회 필터는 클레임 진행 건도 대상이라 values() 전체가 정답이다.
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
            '쓰기 경로 FormRequest 는 주문상태 허용 집합을 adminAssignableValues() 로 만들어야 합니다: '
            .implode(', ', $offenders)
        );
    }

    /**
     * 게이트를 경유하는 쓰기 경로 FormRequest 의 FQCN 을 전수 수집합니다.
     *
     * @return array<int, class-string> FormRequest 클래스명 배열
     */
    protected function gatedWriteRequestClasses(): array
    {
        $classes = [];

        foreach ($this->requestSources() as $path => $source) {
            if ($this->isFilterRequest($path) || ! str_contains($source, self::GATE)) {
                continue;
            }
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1) {
                $classes[] = trim($ns[1]).'\\'.basename($path, '.php');
            }
        }

        return $classes;
    }

    public function test_gated_write_requests_reject_every_claim_course_status(): void
    {
        $classes = $this->gatedWriteRequestClasses();

        $this->assertNotEmpty($classes, '주문상태 게이트를 경유하는 쓰기 경로 FormRequest 가 검출되지 않았습니다.');

        foreach ($classes as $class) {
            $rules = (new $class)->rules();

            $statusRules = array_filter(
                $rules,
                fn ($key) => preg_match('/(^|\.)(order_)?status$/', (string) $key) === 1,
                ARRAY_FILTER_USE_KEY
            );

            $this->assertNotEmpty(
                $statusRules,
                "{$class} 가 게이트를 참조하는데 상태 규칙 키를 찾지 못했습니다 — 판정기가 그 규칙을 놓치고 있습니다."
            );

            foreach ($statusRules as $key => $rule) {
                $expression = implode(
                    '|',
                    array_map(fn ($r) => is_object($r) ? (string) $r : (string) $r, (array) $rule)
                );

                foreach (OrderStatusEnum::claimCourseValues() as $course) {
                    $this->assertStringNotContainsString(
                        $course,
                        $expression,
                        "{$class}::rules()['{$key}'] 이 코스 상태 '{$course}' 를 허용합니다."
                    );
                }

                // 허용 집합이 통째로 비어 통과하는 거짓 green 방지.
                $this->assertStringContainsString(
                    OrderStatusEnum::PAYMENT_COMPLETE->value,
                    $expression,
                    "{$class}::rules()['{$key}'] 의 허용 집합이 비어 있습니다 — 게이트가 아니라 사문입니다."
                );
            }
        }
    }

    public function test_admin_assignable_values_excludes_every_claim_course_status(): void
    {
        $assignable = OrderStatusEnum::adminAssignableValues();

        $this->assertNotEmpty(OrderStatusEnum::claimCourseValues());

        foreach (OrderStatusEnum::claimCourseValues() as $course) {
            $this->assertNotContains(
                $course,
                $assignable,
                "코스 상태 '{$course}' 가 관리자 지정 가능 집합에 포함되어 있습니다."
            );
        }

        // 게이트가 통째로 비어 통과하는 거짓 green 방지 — 지정 가능 상태는 남아 있어야 한다.
        $this->assertContains(OrderStatusEnum::PAYMENT_COMPLETE->value, $assignable);
    }

    /**
     * 취소는 상태 변경이 아니라 **장부 행위**다 — 일괄 상태변경으로 만들 수 없어야 한다.
     *
     * 코스 상태 4종과 같은 이유가 `cancelled` 에도 그대로 적용된다. 관리자 주문 화면의 일괄
     * 상태변경으로 상품을 `cancelled` 로 옮기면 취소신청서도 환불신청서도 없이 취소 상태만
     * 남는다 — 그 경로는 `OrderOptionService` 를 지나므로 **재고는 복원되는데** 주문 합계·
     * `total_cancelled_amount`·`cancellation_count`·결제행은 전혀 갱신되지 않는다. 돈은 그대로
     * 두고 물건만 돌려받은 상태가 원장 어디에도 근거 없이 생긴다.
     *
     * 결제행을 정리하지 않는다는 것은 P4 가 막은 결함도 함께 부활한다는 뜻이다 — 그렇게 취소된
     * 주문은 이후 입금확인·PG 입금통보로 결제완료로 되살아난다.
     *
     * 취소의 입구는 하나다(`POST .../orders/{id}/cancel` → 클레임 접수).
     */
    public function test_admin_assignable_values_excludes_cancelled(): void
    {
        $assignable = OrderStatusEnum::adminAssignableValues();

        $this->assertNotContains(
            OrderStatusEnum::CANCELLED->value,
            $assignable,
            '취소가 관리자 지정 가능 집합에 있으면 일괄 상태변경이 신청서 없는 취소를 만든다.'
        );

        // 게이트가 통째로 비어 통과하는 거짓 green 방지.
        $this->assertContains(OrderStatusEnum::PAYMENT_COMPLETE->value, $assignable);
    }
}
