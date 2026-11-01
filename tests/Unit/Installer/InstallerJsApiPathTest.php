<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치 화면 스크립트가 하위 폴더 설치에서도 맞는 API 주소를 쓰는지 정적으로 검증한다.
 *
 * 회귀 1: 설치 완료 직후 `.env` 확정 요청만 `/install/api/finalize-env.php` 로 하드코딩돼, `/g7/install`
 * 처럼 하위 폴더에 설치하면 404 가 나고 `.env` 가 확정되지 않은 채 runtime.php 가 남았다. 다른 API 호출은
 * 모두 `INSTALLER_BASE_URL` 기준이다.
 * 회귀 2: 실행 중(running) 재진입 처리 경로가 정의되지 않은 `apiUrl` 을 참조해 ReferenceError 로 빠졌다.
 */
class InstallerJsApiPathTest extends TestCase
{
    /**
     * installer.js 원문.
     *
     * @return string 파일 내용
     */
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');
    }

    /**
     * 루트 고정 `/install/api/` 문자열 리터럴이 없다.
     *
     * @effects installer_js_api_paths_follow_base_url
     *
     * @return void 반환값 없음
     */
    public function test_no_root_absolute_install_api_literal(): void
    {
        $this->assertDoesNotMatchRegularExpression("#['\"`]/install/api/#", $this->source());
    }

    /**
     * finalize 요청이 INSTALLER_BASE_URL 에서 파생된다.
     *
     * @effects installer_js_finalize_uses_base_url
     *
     * @return void 반환값 없음
     */
    public function test_finalize_request_uses_installer_base_url(): void
    {
        $this->assertMatchesRegularExpression('/INSTALLER_BASE_URL\}?\/api\/finalize-env\.php/', $this->source());
    }

    /**
     * checkAndStartInstallation 이 지역에서 선언하지 않은 apiUrl 을 참조하지 않는다.
     *
     * @effects installer_js_running_branch_has_no_undefined_identifier
     *
     * @return void 반환값 없음
     */
    public function test_running_branch_does_not_reference_undeclared_api_url(): void
    {
        $source = $this->source();
        $start = strpos($source, 'async function checkAndStartInstallation()');
        $this->assertNotFalse($start);
        $end = strpos($source, "\n    }\n", $start);
        $body = substr($source, $start, $end - $start);

        $uses = preg_match_all('/\bapiUrl\b/', $body);
        $declares = preg_match_all('/\b(?:const|let|var)\s+apiUrl\b/', $body);

        $this->assertTrue($uses === 0 || $declares > 0, 'checkAndStartInstallation 이 선언 없는 apiUrl 을 참조한다');
    }
}
