<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\Public\Plugin\ServePluginAssetRequest;
use App\Rules\AllowedPluginFileType;
use App\Rules\SafePluginPath;
use Tests\TestCase;

class ServePluginAssetRequestTest extends TestCase
{
    /**
     * authorize()가 항상 true를 반환하는지 테스트
     */
    public function test_authorize_returns_true(): void
    {
        $request = new ServePluginAssetRequest();
        $this->assertTrue($request->authorize());
    }

    /**
     * 검증 규칙이 올바르게 정의되어 있는지 테스트
     */
    public function test_has_correct_validation_rules(): void
    {
        $request = new ServePluginAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/plugins/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-plugin');
            $route->setParameter('path', 'dist/js/plugin.iife.js');

            return $route;
        });

        $rules = $request->rules();

        $this->assertArrayHasKey('identifier', $rules);
        $this->assertArrayHasKey('path', $rules);
        $this->assertContains('required', $rules['identifier']);
        $this->assertContains('string', $rules['identifier']);
        $this->assertContains('required', $rules['path']);
        $this->assertContains('string', $rules['path']);
    }

    /**
     * SafePluginPath Rule이 포함되어 있는지 테스트
     */
    public function test_includes_safe_path_rule(): void
    {
        $request = new ServePluginAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/plugins/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-plugin');
            $route->setParameter('path', 'dist/js/plugin.iife.js');

            return $route;
        });

        $rules = $request->rules();

        $hasSafePathRule = false;
        foreach ($rules['path'] as $rule) {
            if ($rule instanceof SafePluginPath) {
                $hasSafePathRule = true;
                break;
            }
        }

        $this->assertTrue($hasSafePathRule, 'SafePluginPath rule should be included in path validation');
    }

    /**
     * AllowedPluginFileType Rule이 포함되어 있는지 테스트
     */
    public function test_includes_file_type_rule(): void
    {
        $request = new ServePluginAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/plugins/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-plugin');
            $route->setParameter('path', 'dist/js/plugin.iife.js');

            return $route;
        });

        $rules = $request->rules();

        $hasFileTypeRule = false;
        foreach ($rules['path'] as $rule) {
            if ($rule instanceof AllowedPluginFileType) {
                $hasFileTypeRule = true;
                break;
            }
        }

        $this->assertTrue($hasFileTypeRule, 'AllowedPluginFileType rule should be included in path validation');
    }

    /**
     * prepareForValidation이 라우트 파라미터를 병합하는지 테스트
     */
    public function test_merges_route_parameters(): void
    {
        $request = new ServePluginAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/plugins/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-plugin');
            $route->setParameter('path', 'dist/js/plugin.iife.js');

            return $route;
        });

        // prepareForValidation 호출
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        // 병합된 파라미터 확인
        $this->assertEquals('test-plugin', $request->input('identifier'));
        $this->assertEquals('dist/js/plugin.iife.js', $request->input('path'));
    }
}
