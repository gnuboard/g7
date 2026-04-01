<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\Public\Module\ServeModuleAssetRequest;
use App\Rules\AllowedModuleFileType;
use App\Rules\SafeModulePath;
use Tests\TestCase;

class ServeModuleAssetRequestTest extends TestCase
{
    /**
     * authorize()가 항상 true를 반환하는지 테스트
     */
    public function test_authorize_returns_true(): void
    {
        $request = new ServeModuleAssetRequest();
        $this->assertTrue($request->authorize());
    }

    /**
     * 검증 규칙이 올바르게 정의되어 있는지 테스트
     */
    public function test_has_correct_validation_rules(): void
    {
        $request = new ServeModuleAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/modules/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-module');
            $route->setParameter('path', 'dist/js/module.iife.js');

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
     * SafeModulePath Rule이 포함되어 있는지 테스트
     */
    public function test_includes_safe_path_rule(): void
    {
        $request = new ServeModuleAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/modules/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-module');
            $route->setParameter('path', 'dist/js/module.iife.js');

            return $route;
        });

        $rules = $request->rules();

        $hasSafePathRule = false;
        foreach ($rules['path'] as $rule) {
            if ($rule instanceof SafeModulePath) {
                $hasSafePathRule = true;
                break;
            }
        }

        $this->assertTrue($hasSafePathRule, 'SafeModulePath rule should be included in path validation');
    }

    /**
     * AllowedModuleFileType Rule이 포함되어 있는지 테스트
     */
    public function test_includes_file_type_rule(): void
    {
        $request = new ServeModuleAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/modules/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-module');
            $route->setParameter('path', 'dist/js/module.iife.js');

            return $route;
        });

        $rules = $request->rules();

        $hasFileTypeRule = false;
        foreach ($rules['path'] as $rule) {
            if ($rule instanceof AllowedModuleFileType) {
                $hasFileTypeRule = true;
                break;
            }
        }

        $this->assertTrue($hasFileTypeRule, 'AllowedModuleFileType rule should be included in path validation');
    }

    /**
     * prepareForValidation이 라우트 파라미터를 병합하는지 테스트
     */
    public function test_merges_route_parameters(): void
    {
        $request = new ServeModuleAssetRequest();

        // 라우트 파라미터 시뮬레이션
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('GET', 'api/modules/assets/{identifier}/{path}', []);
            $route->bind($request);
            $route->setParameter('identifier', 'test-module');
            $route->setParameter('path', 'dist/js/module.iife.js');

            return $route;
        });

        // prepareForValidation 호출
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        // 병합된 파라미터 확인
        $this->assertEquals('test-module', $request->input('identifier'));
        $this->assertEquals('dist/js/module.iife.js', $request->input('path'));
    }
}
