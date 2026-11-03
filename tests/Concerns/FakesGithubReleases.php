<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * 확장 설치·업데이트 확인 경로의 GitHub 실제 접속을 막습니다.
 *
 * 모듈·플러그인 설치와 업데이트 확인은 GitHub 최신 릴리스를 조회한다(`GithubHelper::fetchLatestRelease()`, 타임아웃 10초).
 * 테스트가 그 경로를 타면 실행마다 실제 네트워크를 쓰고, 네트워크가 느리거나 막힌 PC 에서는 한 번에 최대 10초씩 늘어진다.
 * 이 트레이트는 GitHub API 를 404(릴리스 없음)로 고정하고 그 밖의 실제 요청을 금지한다.
 *
 * 전역(TestCase)으로 걸지 않는다 — Http::fake 는 먼저 등록한 응답이 이기므로, GitHub 응답 자체를 검증하는 테스트의
 * fake 를 덮어 버린다. 설치·업데이트 확인 경로를 타는 테스트만 setUp 에서 부른다.
 */
trait FakesGithubReleases
{
    /**
     * GitHub 릴리스 조회를 404 로 고정하고 그 밖의 실제 HTTP 요청을 금지합니다.
     *
     * @return void 반환값 없음
     */
    protected function fakeGithubReleases(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
        Http::preventStrayRequests();
    }
}
