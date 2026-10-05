<?php

namespace Tests\Feature\Rules;

use App\Rules\WhitelistedEndpoint;
use Tests\TestCase;

/**
 * WhitelistedEndpoint 검증 규칙 — 레이아웃 데이터 소스 엔드포인트를 라우트 표에 등록된 코어 API
 * 네임스페이스와 확장 API 프리픽스로만 제한하는지 검증한다.
 */
class WhitelistedEndpointTest extends TestCase
{
    private WhitelistedEndpoint $rule;

    /**
     * 네임스페이스 캐시를 비우고 검증 규칙 인스턴스를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 네임스페이스 집합은 요청 수명 캐시라 프로세스를 공유하는 테스트 간에 남는다 —
        // 라우트 등록 상태가 다른 테스트끼리 서로의 캐시를 보지 않도록 매 케이스 비운다.
        WhitelistedEndpoint::flushNamespaceCache();

        $this->rule = new WhitelistedEndpoint;
    }

    /**
     * 라우트 표에 등록된 admin 네임스페이스 엔드포인트(하위 경로 포함)를 허용한다.
     *
     * @scenario input_kind=registered_core_namespace
     *
     * @effects registered_core_namespace_allowed
     *
     * @return void 반환값 없음
     */
    public function test_allows_whitelisted_admin_endpoints(): void
    {
        $validEndpoints = [
            '/api/admin/users',
            '/api/admin/products',
            '/api/admin/settings',
            '/api/admin/dashboard/stats',
        ];

        foreach ($validEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Expected endpoint '{$endpoint}' to be allowed");
        }
    }

    /**
     * 라우트 표에 등록된 auth 네임스페이스 엔드포인트를 허용한다.
     *
     * @scenario input_kind=registered_core_namespace
     *
     * @effects registered_core_namespace_allowed
     *
     * @return void 반환값 없음
     */
    public function test_allows_whitelisted_auth_endpoints(): void
    {
        $validEndpoints = [
            '/api/auth/login',
            '/api/auth/logout',
            '/api/auth/profile',
            '/api/auth/password/reset',
        ];

        foreach ($validEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Expected endpoint '{$endpoint}' to be allowed");
        }
    }

    /**
     * 코어가 실제 등록한 API 네임스페이스는 전부 허용한다 (라우트 표 기반 동적 검증).
     *
     * 종전 규칙은 `/api/(admin|auth|public)/` 만 하드코딩해, 코어 라우트 표의 14개 네임스페이스 중
     * 5개만 알고 있었다. 그래서 번들 레이아웃이 정상 출하 중인 코어 엔드포인트를 44+회 쓰는데도
     * **레이아웃 편집기 저장 API 가 그 레이아웃들을 거부**했다(설치 경로는 이 규칙을 안 돌려 출하만
     * 무사했다). 화이트리스트를 손으로 관리하는 한 라우트가 늘 때마다 같은 갭이 재발하므로
     * 라우트 표를 근거로 판정한다.
     *
     * @scenario input_kind=registered_core_namespace
     *
     * @effects route_table_namespaces_beyond_legacy_list_allowed
     *
     * @return void 반환값 없음
     */
    public function test_allows_registered_core_api_namespaces(): void
    {
        $validEndpoints = [
            '/api/identity/purposes',
            '/api/identity/challenges',
            '/api/user/notifications',
            '/api/users/1',
            '/api/me',
            '/api/me/password',
            '/api/search?q=hello',
            '/api/templates/sirsoft-admin_basic/components',
            '/api/locales',
        ];

        foreach ($validEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Expected endpoint '{$endpoint}' to be allowed");
        }
    }

    /**
     * 등록되지 않은 네임스페이스는 차단한다 (대조군).
     *
     * `/api/public/` 은 종전 하드코딩 화이트리스트에 있었으나 **등록된 라우트도 사용처도 0건**인
     * 유령 항목이었다. 라우트 표 기반으로 바뀌면서 자연히 빠진다 — 실재하지 않는 네임스페이스를
     * 열어두지 않는 것이 이 규칙의 취지에 맞다.
     *
     * @scenario input_kind=unregistered_namespace
     *
     * @effects unregistered_namespace_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_unregistered_api_namespaces(): void
    {
        $invalidEndpoints = [
            '/api/public/posts',
            '/api/internal/secret',
            '/api/sysinfo/config', // 'system' 은 자산 프로브(api/system/asset-probe)가 등록한 네임스페이스라 예시로 쓸 수 없다
        ];

        foreach ($invalidEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertTrue($failed, "Expected endpoint '{$endpoint}' to be blocked");
        }
    }

    /**
     * 모듈/플러그인 API 프리픽스 허용 테스트
     *
     * ModuleRouteServiceProvider/PluginRouteServiceProvider 가 등록하는
     * /api/(modules|plugins)/{vendor-id}/ 프리픽스는 코어 정식 라우트 네임스페이스다
     *
     * @scenario input_kind=extension_api_prefix
     *
     * @effects extension_api_prefix_with_identifier_allowed
     *
     * @return void 반환값 없음
     */
    public function test_allows_module_and_plugin_api_endpoints(): void
    {
        $validEndpoints = [
            '/api/modules/sirsoft-board/posts',
            '/api/modules/sirsoft-ecommerce/checkout',
            '/api/modules/gnuboard7-hello_module/memos',
            '/api/plugins/sirsoft-payment/methods',
            '/api/modules/sirsoft-board/users/1/posts/stats?page=1',
        ];

        foreach ($validEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Expected endpoint '{$endpoint}' to be allowed");
        }
    }

    /**
     * 확장 식별자(vendor-name) 형식이 아닌 modules/plugins 세그먼트(에셋 경로·하이픈 없는 이름·식별자 없음)를 차단한다.
     *
     * @scenario input_kind=invalid_extension_segment
     *
     * @effects non_identifier_extension_segment_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_non_extension_module_segments(): void
    {
        $invalidEndpoints = [
            '/api/modules/assets/sirsoft-board/app.js', // 에셋 서빙 경로 — data source 대상 아님
            '/api/modules/internal/secret',              // 하이픈 없는 세그먼트
            '/api/modules/',                             // 식별자 없음
            '/api/plugins/system/config',
        ];

        foreach ($invalidEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertTrue($failed, "Expected endpoint '{$endpoint}' to be blocked");
        }
    }

    /**
     * 미등록 API 엔드포인트를 차단하고 비어 있지 않은 실패 메시지를 넘긴다.
     *
     * @scenario input_kind=unregistered_namespace
     *
     * @effects blocked_endpoint_reports_failure_message
     *
     * @return void 반환값 없음
     */
    public function test_blocks_non_whitelisted_api_endpoints(): void
    {
        $invalidEndpoints = [
            '/api/internal/secret',
            '/api/sysinfo/config', // 'system' 은 자산 프로브(api/system/asset-probe)가 등록한 네임스페이스라 예시로 쓸 수 없다
            '/api/debug/info',
        ];

        foreach ($invalidEndpoints as $endpoint) {
            $failed = false;
            $failMessage = '';
            $this->rule->validate('endpoint', $endpoint, function ($message) use (&$failed, &$failMessage) {
                $failed = true;
                $failMessage = $message;
            });

            $this->assertTrue($failed, "Expected endpoint '{$endpoint}' to be blocked");
            // 메시지는 다국어로 반환되므로 실패 여부만 확인
            $this->assertNotEmpty($failMessage);
        }
    }

    /**
     * /api 프리픽스가 없는 직접 경로(/admin·/auth·/users 등)를 차단한다.
     *
     * @scenario input_kind=direct_path
     *
     * @effects non_api_direct_path_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_direct_path_access(): void
    {
        $invalidEndpoints = [
            '/admin/dashboard',
            '/auth/login',
            '/public/posts',
            '/users',
            '/settings',
        ];

        foreach ($invalidEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertTrue($failed, "Expected endpoint '{$endpoint}' to be blocked");
        }
    }

    /**
     * http·https 외부 URL(대문자 스킴 포함)을 차단하고 실패 메시지를 넘긴다.
     *
     * @scenario input_kind=external_url
     *
     * @effects external_url_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_external_http_urls(): void
    {
        $externalUrls = [
            'http://evil.com/api/admin/users',
            'https://attacker.com/api',
            'HTTP://MALICIOUS.COM/data',
        ];

        foreach ($externalUrls as $url) {
            $failed = false;
            $failMessage = '';
            $this->rule->validate('endpoint', $url, function ($message) use (&$failed, &$failMessage) {
                $failed = true;
                $failMessage = $message;
            });

            $this->assertTrue($failed, "Expected external URL '{$url}' to be blocked");
            // 메시지는 다국어로 반환되므로 실패 여부만 확인
            $this->assertNotEmpty($failMessage);
        }
    }

    /**
     * 허용 네임스페이스로 시작하더라도 ../ 또는 ..\ 트래버설이 들어간 경로를 차단한다.
     *
     * @scenario input_kind=path_traversal
     *
     * @effects path_traversal_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_path_traversal_attacks(): void
    {
        $traversalPaths = [
            '/api/admin/../../../etc/passwd',
            '/api/auth/../../internal/secret',
            '/api/public/..\\..\\system\\config',
        ];

        foreach ($traversalPaths as $path) {
            $failed = false;
            $failMessage = '';
            $this->rule->validate('endpoint', $path, function ($message) use (&$failed, &$failMessage) {
                $failed = true;
                $failMessage = $message;
            });

            $this->assertTrue($failed, "Expected path traversal '{$path}' to be blocked");
            // 메시지는 다국어로 반환되므로 실패 여부만 확인
            $this->assertNotEmpty($failMessage);
        }
    }

    /**
     * 빈 문자열은 이 규칙이 실패시키지 않는다 (필수 여부는 required 규칙 몫).
     *
     * @scenario input_kind=empty_value
     *
     * @effects empty_value_deferred_to_required_rule
     *
     * @return void 반환값 없음
     */
    public function test_allows_empty_string(): void
    {
        $failed = false;
        $this->rule->validate('endpoint', '', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected empty string to be allowed (handled by required rule)');
    }

    /**
     * null 은 이 규칙이 실패시키지 않는다 (필수 여부는 required 규칙 몫).
     *
     * @scenario input_kind=empty_value
     *
     * @effects empty_value_deferred_to_required_rule
     *
     * @return void 반환값 없음
     */
    public function test_allows_null_value(): void
    {
        $failed = false;
        $this->rule->validate('endpoint', null, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected null to be allowed (handled by required rule)');
    }

    /**
     * 문자열이 아닌 값(정수·실수·불리언·레이아웃 구조가 아닌 배열)을 차단하고 실패 메시지를 넘긴다.
     *
     * @scenario input_kind=non_string
     *
     * @effects non_string_value_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_non_string_values(): void
    {
        $nonStringValues = [
            123,
            12.34,
            true,
            ['array'],
        ];

        foreach ($nonStringValues as $value) {
            $failed = false;
            $failMessage = '';
            $this->rule->validate('endpoint', $value, function ($message) use (&$failed, &$failMessage) {
                $failed = true;
                $failMessage = $message;
            });

            $this->assertTrue($failed, 'Expected non-string value to be blocked');
            // 메시지는 다국어로 반환되므로 실패 여부만 확인
            $this->assertNotEmpty($failMessage);
        }
    }

    /**
     * 소문자 경로는 허용하고 대문자로 쓴 같은 경로(/API/ADMIN/USERS)는 차단한다.
     *
     * @scenario input_kind=case_mismatch
     *
     * @effects uppercase_path_blocked
     *
     * @return void 반환값 없음
     */
    public function test_case_sensitivity(): void
    {
        // 소문자는 허용
        $failed = false;
        $this->rule->validate('endpoint', '/api/admin/users', function () use (&$failed) {
            $failed = true;
        });
        $this->assertFalse($failed, 'Expected lowercase endpoint to be allowed');

        // 대문자 혼합은 패턴에 따라 처리됨
        $failed = false;
        $this->rule->validate('endpoint', '/API/ADMIN/USERS', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed, 'Expected uppercase endpoint to be blocked (pattern is case-sensitive)');
    }

    /**
     * 허용 경로 뒤에 붙은 쿼리 문자열·숫자 ID 는 판정에 영향을 주지 않는다.
     *
     * @scenario input_kind=registered_core_namespace
     *
     * @effects query_and_id_suffix_allowed
     *
     * @return void 반환값 없음
     */
    public function test_allows_special_characters_in_allowed_paths(): void
    {
        $validEndpoints = [
            '/api/admin/users?page=1',
            '/api/admin/products/123',
            '/api/auth/password/reset?token=abc',
        ];

        foreach ($validEndpoints as $endpoint) {
            $failed = false;
            $this->rule->validate('endpoint', $endpoint, function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed, "Expected endpoint with special chars '{$endpoint}' to be allowed");
        }
    }
}
