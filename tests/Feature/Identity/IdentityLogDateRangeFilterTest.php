<?php

namespace Tests\Feature\Identity;

use App\Helpers\TimezoneHelper;
use App\Models\IdentityVerificationLog;
use App\Repositories\IdentityVerificationLogRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 본인인증 이력 기간 필터 회귀 테스트 (#492 D-21).
 *
 * 두 가지가 함께 어긋나 있었다.
 *
 *  1. 타임존 — `created_at` 은 UTC 로 저장되고 화면은 사이트 타임존으로 보여주는데,
 *     기간 필터는 입력 문자열을 변환 없이 UTC 값과 비교했다.
 *  2. 종료일 경계 — 화면 입력이 `<input type="date">` 라 'YYYY-MM-DD' 만 오는데
 *     datetime 비교에 그대로 넣어 '00:00:00' 으로 해석됐다. 종료일 당일이 통째로 빠진다.
 *
 * 기준은 활동 로그(`ActivityLogRepository`)와 같다 — 입력은 **사이트 타임존 기준**으로 읽고,
 * 시각이 없는 종료일은 그날 끝까지 포함한다.
 *
 * 부하 설계: 경계 시각 9건을 **INSERT 1회**로 심고 한 메서드 안에서 네 경계를 모두 단언한다.
 * 케이스마다 픽스처를 다시 만들면 같은 행을 4번 심게 되고, 행마다 create→forceFill→refresh 로
 * 3쿼리를 쓰면 그 3배가 다시 곱해진다.
 */
class IdentityLogDateRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 경계 판정에 쓰는 사이트 타임존 기준 시각 목록.
     *
     * 한 벌로 네 가지 경계(종료일 종일 확장 / 시작일 당일 포함 / 같은 날 범위 / 시각 명시)를
     * 모두 판정할 수 있도록 전날 끝 · 당일 처음/중간/끝 · 다음날 처음을 함께 둔다.
     */
    private const BOUNDARY_TIMES = [
        '2026-07-28 23:59:59',
        '2026-07-29 00:00:00',
        '2026-07-29 09:00:00',
        '2026-07-29 11:00:00',
        '2026-07-29 12:00:00',
        '2026-07-29 13:45:00',
        '2026-07-29 23:59:59',
        '2026-07-30 00:00:00',
        '2026-07-30 00:00:01',
    ];

    /**
     * 경계 시각 이력을 INSERT 1회로 심습니다.
     *
     * `created_at` 은 fillable 이 아니라 모델 create() 로 지정되지 않는다. 모델을 거치면
     * 저장 후 덮어쓰기(UPDATE)와 재조회(SELECT)가 행마다 붙으므로 쿼리 빌더로 직접 넣는다.
     */
    private function seedBoundaryLogs(): void
    {
        $timezone = TimezoneHelper::getSiteTimezone();

        $rows = array_map(function (string $siteLocal) use ($timezone) {
            $utc = Carbon::parse($siteLocal, $timezone)->utc();

            return [
                'id' => (string) Str::uuid(),
                'provider_id' => 'g7:core.mail',
                'purpose' => 'sensitive_action',
                'channel' => 'email',
                'target_hash' => hash('sha256', $siteLocal),
                'status' => 'verified',
                'attempts' => 1,
                'max_attempts' => 5,
                'created_at' => $utc,
                'updated_at' => $utc,
            ];
        }, self::BOUNDARY_TIMES);

        DB::table((new IdentityVerificationLog)->getTable())->insert($rows);
    }

    /**
     * 리포지토리 검색 결과 총건수를 반환합니다.
     *
     * @param  array  $filters  필터 배열
     * @return int 총건수
     */
    private function totalFor(array $filters): int
    {
        return app(IdentityVerificationLogRepository::class)->search($filters, 50)->total();
    }

    /**
     * 기간 필터의 네 경계가 사이트 타임존 기준으로 해석되어야 한다.
     */
    public function test_기간필터_경계가_사이트_타임존_기준으로_해석된다(): void
    {
        $this->seedBoundaryLogs();

        // 종료일만 지정 — 시각이 없으므로 그날 23:59:59 까지 포함한다
        // (07-28 1건 + 07-29 6건 = 7건, 07-30 2건 제외)
        $this->assertSame(
            7,
            $this->totalFor(['date_to' => '2026-07-29']),
            '종료일 당일이 통째로 빠지면 종일 확장이 적용되지 않은 것입니다.'
        );

        // 시작일만 지정 — 그날 00:00:00 부터 포함한다 (07-29 6건 + 07-30 2건 = 8건)
        $this->assertSame(
            8,
            $this->totalFor(['date_from' => '2026-07-29']),
            '전날 23:59:59 가 섞이면 타임존 변환이 빠진 것입니다.'
        );

        // 같은 날 범위 — 그날 하루 전체 (07-29 6건)
        $this->assertSame(
            6,
            $this->totalFor(['date_from' => '2026-07-29', 'date_to' => '2026-07-29']),
            '같은 날짜 범위는 그날 00:00:00~23:59:59 전체여야 합니다.'
        );

        // 시각까지 지정한 종료값 — 종일 확장이 그 시각을 덮어쓰면 안 된다
        // (07-28 23:59:59 + 07-29 00:00:00 + 07-29 09:00:00 = 3건)
        $this->assertSame(
            3,
            $this->totalFor(['date_to' => '2026-07-29 10:00:00']),
            '시각이 포함된 종료값은 그 시각까지만 조회해야 합니다.'
        );
    }
}
