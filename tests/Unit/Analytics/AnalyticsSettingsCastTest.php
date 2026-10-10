<?php

namespace Tests\Unit\Analytics;

use App\Http\Requests\Admin\Analytics\UpdateAnalyticsSettingsRequest;
use App\Http\Requests\Settings\SaveSettingsRequest;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Tests\TestCase;

/**
 * 설정 요청이 검증을 마친 값을 선언한 형으로 돌려주는지 — 그리고 값을 뽑는 방식이 부모와 같은지.
 *
 * 폼은 무엇을 치든 문자열로 보낸다. 그대로 저장하면 설정 파일과 응답에 "45" 가 남아, 숫자로
 * 알고 읽던 쪽이 문자열을 받는다(브라우저 실측 M6-T4). 형 변환을 얹으면서 `validated($key)` 를
 * 덮어썼으므로, 부모가 주던 점 표기 조회가 사라지지 않았는지도 함께 잠근다.
 */
class AnalyticsSettingsCastTest extends TestCase
{
    /**
     * 요청 객체를 만들고 검증까지 마칩니다.
     *
     * @param  class-string  $class  요청 클래스
     * @param  array<string, mixed>  $payload  보낼 값
     * @return mixed 검증을 마친 요청 객체
     */
    private function resolve(string $class, array $payload): mixed
    {
        /** @var Request $request */
        $request = $class::create('/test', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setValidator($this->app->make(ValidationFactory::class)->make($payload, $request->rules()));

        return $request;
    }

    /**
     * 전용 API 요청이 숫자·참거짓을 선언한 형으로 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_the_analytics_request_casts_form_strings(): void
    {
        $request = $this->resolve(UpdateAnalyticsSettingsRequest::class, [
            'retention_days' => '45',
            'enabled' => '1',
            'respect_dnt' => '0',
        ]);

        $validated = $request->validated();

        $this->assertSame(45, $validated['retention_days']);
        $this->assertTrue($validated['enabled']);
        $this->assertFalse($validated['respect_dnt']);
    }

    /**
     * 화면 열 때 집계 두 키는 선언한 형으로, 구간 경계는 문자열 그대로 저장되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_archive_keys_are_cast_and_bounds_stay_strings(): void
    {
        $validated = $this->resolve(UpdateAnalyticsSettingsRequest::class, [
            'archive_on_view_enabled' => '0',
            'archive_on_view_max_visits' => '2000',
            'duration_bucket_bounds' => '5,20',
        ])->validated();

        $this->assertFalse($validated['archive_on_view_enabled']);
        $this->assertSame(2000, $validated['archive_on_view_max_visits']);
        $this->assertSame('5,20', $validated['duration_bucket_bounds']);
    }

    /**
     * 키 하나만 뽑아도 같은 형이고, 없는 키는 기본값이 나오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_pulling_one_key_keeps_the_cast_and_the_default(): void
    {
        $request = $this->resolve(UpdateAnalyticsSettingsRequest::class, ['retention_days' => '45']);

        $this->assertSame(45, $request->validated('retention_days'));
        $this->assertSame('없음', $request->validated('visit_timeout_minutes', '없음'));
    }

    /**
     * 코어 설정 요청이 통계 절을 바꾸고 점 표기 조회도 그대로 두는지 확인합니다.
     *
     * 부모 `FormRequest::validated()` 는 `data_get` 이라 점 표기가 통한다 — 덮어쓰면서
     * 배열 접근으로 바꾸면 `analytics.retention_days` 가 조용히 null 이 된다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_the_core_settings_request_casts_and_keeps_dot_access(): void
    {
        $request = $this->resolve(SaveSettingsRequest::class, [
            '_tab' => 'analytics',
            'analytics' => ['retention_days' => '45', 'enabled' => '0'],
        ]);

        $validated = $request->validated();

        $this->assertSame(45, $validated['analytics']['retention_days']);
        $this->assertFalse($validated['analytics']['enabled']);
        $this->assertSame(45, $request->validated('analytics.retention_days'));
        $this->assertSame('없음', $request->validated('analytics.visit_timeout_minutes', '없음'));
    }
}
