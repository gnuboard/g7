<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Settings\SaveSettingsRequest;
use Tests\TestCase;

/**
 * `general.site_url` 은 저장 전에 후행 슬래시를 걷어낸다.
 *
 * 운영자가 `https://example.com/g7/` 로 적으면 그 값을 `.'/path'` 로 잇는 모든 소비처가
 * `//` 를 만든다. 정규화를 소비처마다 두면 한 곳이 빠지고, 저장 경로 진입 전 한 곳에서 걷어내면
 * 그 형태가 설정 파일에 남지 않는다 (16차 G3 라운드 B-4).
 */
class SaveSettingsRequestSiteUrlTest extends TestCase
{
    /**
     * 주어진 입력으로 설정 저장 요청을 만들고 검증 전 정규화(prepareForValidation)까지 돌린다.
     *
     * @param  array  $payload  요청 본문
     * @return SaveSettingsRequest 정규화를 마친 요청
     */
    private function prepared(array $payload): SaveSettingsRequest
    {
        $request = SaveSettingsRequest::create('/api/admin/settings', 'POST', $payload);
        $request->setContainer(app());

        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        return $request;
    }

    /**
     * site_url 끝의 슬래시가 저장 전에 잘리고 형제 키는 그대로인지 확인한다.
     *
     * @scenario base_path=subpath, navigation_direction=navigation
     *
     * @effects site_url_setting_is_stored_without_a_trailing_slash
     *
     * @return void 반환값 없음
     */
    public function test_trailing_slash_is_trimmed_from_site_url(): void
    {
        $request = $this->prepared(['general' => ['site_url' => 'https://example.com/g7/', 'site_name' => 'G7']]);

        $this->assertSame('https://example.com/g7', $request->input('general.site_url'));
        // 형제 키는 건드리지 않는다.
        $this->assertSame('G7', $request->input('general.site_name'));
    }

    /**
     * 루트 사이트(`https://example.com/`)도 같은 규칙 — 오리진만 남는다.
     *
     * @scenario base_path=root, navigation_direction=navigation
     *
     * @effects site_url_setting_is_stored_without_a_trailing_slash
     *
     * @return void 반환값 없음
     */
    public function test_root_site_url_keeps_only_the_origin(): void
    {
        $request = $this->prepared(['general' => ['site_url' => 'https://example.com/']]);

        $this->assertSame('https://example.com', $request->input('general.site_url'));
    }

    /**
     * `site_url` 이 없거나 문자열이 아니면 손대지 않는다 (검증 규칙이 판정한다).
     *
     * @scenario base_path=root, navigation_direction=navigation
     *
     * @effects site_url_setting_is_stored_without_a_trailing_slash
     *
     * @return void 반환값 없음
     */
    public function test_absent_or_non_string_site_url_is_left_alone(): void
    {
        $request = $this->prepared(['general' => ['site_name' => 'G7']]);
        $this->assertNull($request->input('general.site_url'));

        $request = $this->prepared(['general' => ['site_url' => ['nested']]]);
        $this->assertSame(['nested'], $request->input('general.site_url'));
    }
}
