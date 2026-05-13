<?php

namespace Tests\Feature\Rules;

use App\Rules\WhitelistedEndpoint;
use Tests\TestCase;

class WhitelistedEndpointTest extends TestCase
{
    private WhitelistedEndpoint $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new WhitelistedEndpoint();
    }

    /**
     * 허용된 API 엔드포인트 테스트
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

    public function test_allows_whitelisted_public_endpoints(): void
    {
        $validEndpoints = [
            '/api/public/posts',
            '/api/public/products',
            '/api/public/search',
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
     * 허용되지 않은 API 엔드포인트 차단 테스트
     */
    public function test_blocks_non_whitelisted_api_endpoints(): void
    {
        $invalidEndpoints = [
            '/api/internal/secret',
            '/api/system/config',
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
     * 직접 경로 접근 차단 테스트
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
     * 외부 URL 차단 테스트
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
     * 경로 트래버설 공격 차단 테스트
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
     * 빈 값 처리 테스트
     */
    public function test_allows_empty_string(): void
    {
        $failed = false;
        $this->rule->validate('endpoint', '', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected empty string to be allowed (handled by required rule)');
    }

    public function test_allows_null_value(): void
    {
        $failed = false;
        $this->rule->validate('endpoint', null, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected null to be allowed (handled by required rule)');
    }

    /**
     * 타입 검증 테스트
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
     * 대소문자 구분 테스트
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
     * 특수문자 포함 엔드포인트 테스트
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
