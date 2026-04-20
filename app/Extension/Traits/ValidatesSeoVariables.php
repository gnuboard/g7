<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * SEO 변수 중복 검증 기능을 제공하는 트레이트.
 *
 * 모듈 및 플러그인 설치 시 seoVariables()에서 반환하는
 * 변수명이 기존 활성 확장과 충돌하지 않는지 검증합니다.
 *
 * 검증 대상:
 * - _common 변수: 모든 활성 확장의 _common 및 page_type별 변수와 중복 확인
 * - page_type별 변수: 동일 page_type 내 기존 변수 + _common 변수와 중복 확인
 */
trait ValidatesSeoVariables
{
    /**
     * 확장의 SEO 변수명이 기존 활성 확장과 중복되지 않는지 검증합니다.
     *
     * @param  object  $extension  검증할 모듈 또는 플러그인 인스턴스
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     *
     * @throws \Exception SEO 변수명이 중복될 때
     */
    protected function validateSeoVariables(object $extension, string $extensionType = 'module'): void
    {
        $newVars = $extension->seoVariables();
        $identifier = $extension->getIdentifier();

        // SEO 변수가 없으면 검증 통과
        if (empty($newVars)) {
            return;
        }

        // 1. 자체 _common과 page_type별 변수 간 중복 검증
        $selfErrors = $this->validateSelfSeoVariableConflicts($newVars);

        // 2. 기존 활성 확장들의 SEO 변수 수집
        $existingVars = $this->collectExistingSeoVariables($identifier);

        // 3. 기존 변수와 충돌 검증
        $crossErrors = $this->validateCrossSeoVariableConflicts($newVars, $existingVars);

        $errors = array_merge($selfErrors, $crossErrors);
        if (! empty($errors)) {
            throw new \Exception(
                $this->formatSeoValidationError($extensionType, $identifier, implode("\n- ", $errors))
            );
        }
    }

    /**
     * SEO 변수 검증 에러 메시지를 포맷합니다.
     *
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     * @param  string  $identifier  확장 식별자
     * @param  string  $reason  에러 사유
     * @return string 포맷된 에러 메시지
     */
    private function formatSeoValidationError(string $extensionType, string $identifier, string $reason): string
    {
        $translationKey = $extensionType === 'module'
            ? 'modules.errors.seo_variable_conflict'
            : 'plugins.errors.seo_variable_conflict';

        return __($translationKey, [
            'identifier' => $identifier,
            'reason' => $reason,
        ]);
    }

    /**
     * 확장 자체 내에서 _common과 page_type별 변수명 충돌을 검증합니다.
     *
     * @param  array  $vars  SEO 변수 정의
     * @return array 에러 메시지 배열
     */
    private function validateSelfSeoVariableConflicts(array $vars): array
    {
        $errors = [];
        $commonVarNames = array_keys($vars['_common'] ?? []);

        foreach ($vars as $pageType => $pageVars) {
            if ($pageType === '_common' || ! is_array($pageVars)) {
                continue;
            }

            $pageVarNames = array_keys($pageVars);
            $conflicts = array_intersect($commonVarNames, $pageVarNames);

            if (! empty($conflicts)) {
                $errors[] = "SEO variable name conflict: _common variables [".implode(', ', $conflicts)."] duplicated in page_type '{$pageType}'";
            }
        }

        return $errors;
    }

    /**
     * 기존 활성 확장들의 SEO 변수를 수집합니다.
     *
     * @param  string  $excludeIdentifier  제외할 확장 식별자 (현재 설치 중인 확장)
     * @return array ['_common' => [변수명 => 소유자], 'page_type' => [변수명 => 소유자]]
     */
    private function collectExistingSeoVariables(string $excludeIdentifier): array
    {
        $collected = ['_common' => []];

        // 활성 모듈들의 SEO 변수 수집
        try {
            $moduleManager = app(ModuleManagerInterface::class);
            foreach ($moduleManager->getActiveModules() as $module) {
                if ($module->getIdentifier() === $excludeIdentifier) {
                    continue;
                }

                $this->mergeExtensionSeoVars($collected, $module->seoVariables(), $module->getIdentifier());
            }
        } catch (\Throwable $e) {
            Log::debug('[SEO] Failed to collect module SEO variables', ['error' => $e->getMessage()]);
        }

        // 활성 플러그인들의 SEO 변수 수집
        try {
            $pluginManager = app(PluginManagerInterface::class);
            foreach ($pluginManager->getActivePlugins() as $plugin) {
                if ($plugin->getIdentifier() === $excludeIdentifier) {
                    continue;
                }

                $this->mergeExtensionSeoVars($collected, $plugin->seoVariables(), $plugin->getIdentifier());
            }
        } catch (\Throwable $e) {
            Log::debug('[SEO] Failed to collect plugin SEO variables', ['error' => $e->getMessage()]);
        }

        return $collected;
    }

    /**
     * 확장의 SEO 변수를 수집 결과에 병합합니다.
     *
     * @param  array  &$collected  수집 결과 (참조)
     * @param  array  $vars  확장의 seoVariables() 반환값
     * @param  string  $owner  소유자 식별자
     */
    private function mergeExtensionSeoVars(array &$collected, array $vars, string $owner): void
    {
        foreach ($vars as $pageType => $pageVars) {
            if (! is_array($pageVars)) {
                continue;
            }

            if (! isset($collected[$pageType])) {
                $collected[$pageType] = [];
            }

            foreach (array_keys($pageVars) as $varName) {
                $collected[$pageType][$varName] = $owner;
            }
        }
    }

    /**
     * 새 확장의 변수가 기존 확장과 충돌하는지 검증합니다.
     *
     * @param  array  $newVars  새 확장의 SEO 변수
     * @param  array  $existingVars  기존 활성 확장들의 변수 맵
     * @return array 에러 메시지 배열
     */
    private function validateCrossSeoVariableConflicts(array $newVars, array $existingVars): array
    {
        $errors = [];

        foreach ($newVars as $pageType => $pageVars) {
            if (! is_array($pageVars)) {
                continue;
            }

            foreach (array_keys($pageVars) as $varName) {
                // 동일 pageType 내 중복 확인
                if (isset($existingVars[$pageType][$varName])) {
                    $owner = $existingVars[$pageType][$varName];
                    $errors[] = "Variable '{$varName}' in '{$pageType}' conflicts with {$owner}";

                    continue;
                }

                // 새 _common 변수가 기존 page_type별 변수와 충돌 확인
                if ($pageType === '_common') {
                    foreach ($existingVars as $existPageType => $existPageVars) {
                        if ($existPageType === '_common') {
                            continue;
                        }
                        if (isset($existPageVars[$varName])) {
                            $owner = $existPageVars[$varName];
                            $errors[] = "Common variable '{$varName}' conflicts with '{$existPageType}' variable of {$owner}";
                        }
                    }
                }

                // 새 page_type별 변수가 기존 _common 변수와 충돌 확인
                if ($pageType !== '_common' && isset($existingVars['_common'][$varName])) {
                    $owner = $existingVars['_common'][$varName];
                    $errors[] = "Variable '{$varName}' in '{$pageType}' conflicts with _common variable of {$owner}";
                }
            }
        }

        return $errors;
    }
}
