<?php

namespace Tests\Feature\Analytics;

use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\CollectFailureReason;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 비콘 수집 — 화면 크기를 알 수 없는 방문자의 해상도는 「값 없음」으로 남긴다.
 *
 * 화면 크기를 알려 주지 않는 환경(앱 내 웹뷰·헤드리스 브라우저·일부 봇)은 `0x0` 처럼 한 변이
 * 0 인 해상도를 보낸다. 해상도가 아니라 「모름」이므로 해상도 통계에 `0x0` 같은 항목이 생기면
 * 안 되고, 그렇다고 그 페이지뷰를 통째로 버려서도 안 된다 — 해상도는 선택 항목이다.
 * 이미 배포된(캐시된) 옛 트래커가 계속 `0x0` 을 보내므로 서버에서도 걸러야 한다.
 */
#[Group('analytics')]
class CollectResolutionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 준비 — 수집을 켜고 IP 저장 모드로 고정한다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['g7_settings.core.analytics.enabled' => true]);
        config(['g7_settings.core.analytics.privacy_mode' => 'full']);
    }

    /**
     * 한 변이 0(또는 음수)인 해상도 값들.
     *
     * @return array<string, array{0: string}> 사례명 => [해상도 값]
     */
    public static function unknownResolutions(): array
    {
        return [
            '둘 다 0' => ['0x0'],
            '가로만 0' => ['0x1080'],
            '세로만 0' => ['1920x0'],
            '0 을 두 자리로' => ['00x00'],
            '음수' => ['-1x800'],
        ];
    }

    /**
     * 한 변이 0 인 해상도를 보낸 비콘도 페이지뷰로 적재되고, 해상도는 비어 있다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects unknown_screen_size_is_not_recorded_as_a_resolution
     *
     * @param  string  $resolution  비콘이 보낸 해상도
     * @return void 없음
     */
    #[DataProvider('unknownResolutions')]
    public function test_beacon_with_unknown_screen_size_is_recorded_without_resolution(string $resolution): void
    {
        $actionMark = (int) AnalyticsAction::query()->max('id');
        $failureMark = (int) AnalyticsCollectFailure::query()->max('id');

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/board/free',
            'res' => $resolution,
            'init' => true,
        ])->assertNoContent();

        $this->assertSame(
            0,
            AnalyticsCollectFailure::query()
                ->where('id', '>', $failureMark)
                ->where('reason', CollectFailureReason::Validation->value)
                ->count(),
            '해상도를 모른다는 이유로 페이지뷰를 버리면 안 된다',
        );

        $action = AnalyticsAction::query()->where('id', '>', $actionMark)->first();
        $this->assertNotNull($action, '페이지뷰가 적재되어야 한다');

        $visit = AnalyticsVisit::query()->find($action->visit_id);
        $this->assertNotNull($visit);
        $this->assertNull($visit->screen_resolution, '「'.$resolution.'」은 해상도로 남기지 않는다');
    }

    /**
     * 정상 해상도는 그대로 남는다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects unknown_screen_size_is_not_recorded_as_a_resolution
     *
     * @return void 없음
     */
    public function test_beacon_with_real_screen_size_keeps_resolution(): void
    {
        $actionMark = (int) AnalyticsAction::query()->max('id');

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/board/free',
            'res' => '1536x864',
            'init' => true,
        ])->assertNoContent();

        $action = AnalyticsAction::query()->where('id', '>', $actionMark)->first();
        $this->assertNotNull($action);
        $this->assertSame('1536x864', AnalyticsVisit::query()->find($action->visit_id)?->screen_resolution);
    }

    /**
     * 수집 요청 조립기도 한 변이 0 인 값을 해상도로 넘기지 않는다 — 비콘 외 경로(확장이 검증 규칙을
     * 바꾼 경우 포함)도 같은 조립기를 거친다.
     *
     * @scenario source=beacon
     *
     * @effects unknown_screen_size_is_not_recorded_as_a_resolution
     *
     * @return void 없음
     */
    public function test_factory_turns_unknown_screen_size_into_null(): void
    {
        $request = Request::create('/api/analytics/collect', 'POST');

        foreach (array_column(self::unknownResolutions(), 0) as $value) {
            $dto = TrackingRequestFactory::fromHttp($request, ['t' => 'pageview', 'url' => 'https://example.test/', 'res' => $value]);
            $this->assertNull($dto->resolution, '「'.$value.'」');
        }

        $dto = TrackingRequestFactory::fromHttp($request, ['t' => 'pageview', 'url' => 'https://example.test/', 'res' => '390x844']);
        $this->assertSame('390x844', $dto->resolution);
    }

    /**
     * 0 이 아닌 이상한 값은 종전처럼 형식 오류로 남는다 — 이번 변경은 「크기 모름」만 받아들인다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects failures_are_recorded_with_a_reason
     *
     * @return void 없음
     */
    public function test_malformed_resolution_is_still_rejected(): void
    {
        $failureMark = (int) AnalyticsCollectFailure::query()->max('id');

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/',
            'res' => 'abcxdef',
        ])->assertNoContent();

        $this->assertSame(
            1,
            AnalyticsCollectFailure::query()
                ->where('id', '>', $failureMark)
                ->where('reason', CollectFailureReason::Validation->value)
                ->count(),
        );
    }
}
