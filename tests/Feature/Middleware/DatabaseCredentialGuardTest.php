<?php

namespace Tests\Feature\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DatabaseCredentialGuard 미들웨어 회귀 테스트
 *
 * 배경: 설치 후 `.env` 의 DB 사용자명을 최고권한 계정으로 바꾸거나 비우면
 * CoreServiceProvider 가 확장 로딩 전체를 스킵한다. 그 결과 템플릿이 없는 깨진
 * 화면만 뜨고 원인은 storage/logs 의 ERROR 한 줄에만 남아, 운영자가 무엇이
 * 잘못됐는지 알 방법이 없었다("조용한 실패"). 본 미들웨어가 그 상황을 전용
 * 에러 페이지로 드러내는지 보장한다.
 *
 * 검증 축 (계정종류 × 요청유형 × 설치상태):
 *  - 계정: 정상 / 최고권한(root·postgres·대문자·공백) / 빈 값
 *  - 요청: 웹 / API(JSON) — 콘솔은 미들웨어를 타지 않으므로 구조로 보장
 *  - 설치상태: 완료 / 미완료(설치 전에는 통과해야 함)
 *  - 정보 유출: 에러 페이지에 실제 계정명이 노출되지 않을 것
 */
class DatabaseCredentialGuardTest extends TestCase
{
    /**
     * DB 커넥션 설정의 사용자명을 교체합니다.
     *
     * 미들웨어는 DB 에 접속하지 않고 config 배열만 읽으므로, 실제 커넥션을
     * 훼손하지 않고(=RefreshDatabase 트랜잭션 유지) 판정만 바꿀 수 있습니다.
     *
     * @param  string|null  $username  주입할 사용자명
     */
    private function setDbUsername(?string $username): void
    {
        $connection = config('database.default');

        config([
            "database.connections.{$connection}.username" => $username,
            "database.connections.{$connection}.read.username" => $username,
            "database.connections.{$connection}.write.username" => $username,
        ]);
    }

    /**
     * 설치 완료 상태에서 정상 계정이면 웹 요청을 통과시켜야 한다.
     *
     * @scenario account_kind=normal, enforcement_point=runtime_web, install_state=completed
     *
     * @effects runtime_passes_through_with_normal_account
     */
    public function test_passes_through_with_normal_account(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('g7user');

        $response = $this->get('/');

        $this->assertNotEquals(503, $response->getStatusCode());
    }

    /**
     * 정상 계정이면 API 요청도 통과시켜야 한다 (과잉 차단 방지).
     *
     * @scenario account_kind=normal, enforcement_point=runtime_api, install_state=completed
     *
     * @effects runtime_passes_through_with_normal_account
     */
    public function test_passes_through_with_normal_account_for_api(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('g7user');

        $response = $this->getJson('/api/test');

        $this->assertNotEquals(503, $response->getStatusCode());
    }

    /**
     * 최고권한 계정이면 웹 요청에 503 전용 에러 페이지를 반환해야 한다.
     *
     * @scenario account_kind=privileged, enforcement_point=runtime_web, install_state=completed
     *
     * @effects runtime_returns_503_error_page
     */
    #[DataProvider('privilegedUsernameProvider')]
    public function test_returns_error_page_for_privileged_account(string $username): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername($username);

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertViewIs('database-credential-error');
    }

    /**
     * 빈 사용자명(설정 누락)도 동일하게 전용 에러 페이지를 반환해야 한다.
     *
     * @scenario account_kind=empty, enforcement_point=runtime_web, install_state=completed
     *
     * @effects runtime_returns_503_error_page
     */
    #[DataProvider('emptyUsernameProvider')]
    public function test_returns_error_page_for_empty_account(?string $username): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername($username);

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertViewIs('database-credential-error');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privilegedUsernameProvider(): array
    {
        return [
            'root' => ['root'],
            'postgres' => ['postgres'],
            '대문자 ROOT (우회 차단)' => ['ROOT'],
            '앞뒤 공백 " root " (우회 차단)' => [' root '],
        ];
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function emptyUsernameProvider(): array
    {
        return [
            '빈 문자열 (설정 누락)' => [''],
            'null (설정 누락)' => [null],
        ];
    }

    /**
     * 최고권한 계정일 때 API 요청에는 HTML 이 아니라 JSON 503 을 반환해야 한다.
     *
     * @scenario account_kind=privileged, enforcement_point=runtime_api, install_state=completed
     *
     * @effects runtime_returns_503_json_for_api
     */
    public function test_returns_json_for_api_requests(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('root');

        $response = $this->getJson('/api/test');

        $response->assertStatus(503);
        $response->assertJson(['success' => false]);
    }

    /**
     * 빈 사용자명일 때도 api/* 경로는 Accept 헤더와 무관하게 JSON 이어야 한다.
     *
     * @scenario account_kind=empty, enforcement_point=runtime_api, install_state=completed
     *
     * @effects runtime_returns_503_json_for_api
     */
    public function test_returns_json_for_api_path_requests(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('');

        $response = $this->get('/api/settings');

        $response->assertStatus(503);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * 설치 전(installer_completed=false)에는 최고권한 계정이어도 통과해야 한다.
     *
     * 설치 전에는 DB 설정이 비어 있는 것이 정상이며, public/index.php 가
     * 이미 /install 로 유도한다. 여기서 막으면 설치 자체가 불가능해진다.
     *
     * @scenario account_kind=privileged, enforcement_point=runtime_web, install_state=not_completed
     *
     * @effects runtime_passes_through_when_not_installed
     */
    public function test_passes_through_when_not_installed(): void
    {
        config(['app.installer_completed' => false]);
        $this->setDbUsername('root');

        $response = $this->get('/');

        $this->assertNotEquals(503, $response->getStatusCode());
    }

    /**
     * 설치 전에는 사용자명이 비어 있어도 통과해야 한다 (설치 전 정상 상태).
     *
     * @scenario account_kind=empty, enforcement_point=runtime_web, install_state=not_completed
     *
     * @effects runtime_passes_through_when_not_installed
     */
    public function test_passes_through_when_not_installed_with_empty_username(): void
    {
        config(['app.installer_completed' => false]);
        $this->setDbUsername('');

        $response = $this->get('/');

        $this->assertNotEquals(503, $response->getStatusCode());
    }

    /**
     * 에러 페이지는 미인증 공개 페이지이므로 실제 DB 계정명을 노출하면 안 된다.
     *
     * @effects error_page_does_not_leak_account_name
     */
    public function test_error_page_does_not_leak_account_name(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('postgres');

        $response = $this->get('/');
        $content = $response->getContent();

        $response->assertStatus(503);
        $this->assertStringNotContainsString('postgres', $content);
    }

    /**
     * 확장·템플릿이 로딩되지 않은 상태에서 뜨는 페이지이므로
     * 외부 에셋(JS/CSS)에 의존하지 않고 자체 완결이어야 한다.
     *
     * @effects error_page_is_self_contained_without_external_assets
     */
    public function test_error_page_has_no_external_dependencies(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('root');

        $response = $this->get('/');
        $content = $response->getContent();

        $response->assertStatus(503);
        $this->assertStringNotContainsString('<script src=', $content);
        $this->assertStringNotContainsString('<link rel="stylesheet" href=', $content);
        $this->assertStringContainsString('<style>', $content);
    }

    /**
     * SetLocale 미들웨어가 실행되지 않는 지점이므로 자체 로케일 감지가 동작해야 한다.
     *
     * @effects error_page_detects_locale_without_setlocale_middleware
     */
    #[DataProvider('localeProvider')]
    public function test_error_page_is_localized(string $acceptLanguage, string $locale): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('root');

        $response = $this->withHeaders(['Accept-Language' => $acceptLanguage])->get('/');

        $response->assertStatus(503);
        $response->assertSee(__('database_credential.title', [], $locale));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function localeProvider(): array
    {
        return [
            '한국어' => ['ko,en;q=0.9', 'ko'],
            '영어' => ['en,ko;q=0.9', 'en'],
        ];
    }

    /**
     * 메인터넌스 모드가 우선해야 한다 — 점검 중에는 점검 페이지가 맞다.
     *
     * @effects maintenance_mode_takes_precedence_over_credential_guard
     */
    public function test_maintenance_mode_takes_precedence(): void
    {
        config(['app.installer_completed' => true]);
        $this->setDbUsername('root');

        $downFile = storage_path('framework/down');
        file_put_contents($downFile, json_encode([
            'except' => [], 'redirect' => null, 'retry' => null,
            'refresh' => null, 'status' => 503, 'template' => null,
        ]));

        try {
            $response = $this->get('/');

            $response->assertStatus(503);
            $response->assertViewIs('maintenance');
        } finally {
            if (file_exists($downFile)) {
                unlink($downFile);
            }
        }
    }
}
