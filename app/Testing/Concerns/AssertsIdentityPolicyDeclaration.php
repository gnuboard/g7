<?php

namespace App\Testing\Concerns;

use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Models\IdentityPolicy;

/**
 * 모듈/플러그인의 `getIdentityPolicies()` 선언 검증을 위한 공통 어설션 trait.
 *
 * 게시판/이커머스 등 IDV 정책을 declarative 하게 선언하는 모든 번들 확장에서
 * 동일한 검증 로직(동기화 / cleanupStale / user_overrides 보존) 을 반복 작성하는
 * 비용을 제거합니다.
 *
 * 사용 패턴:
 * ```php
 * class BoardIdentityPolicyDeclarationTest extends ModuleTestCase
 * {
 *     use AssertsIdentityPolicyDeclaration;
 *
 *     private const DECLARED_KEYS = ['sirsoft-board.post.delete', ...];
 *
 *     public function test_policies_are_synced_for_module_source(): void
 *     {
 *         $this->assertIdentityPoliciesSyncedForExtension(
 *             extensionType: 'module',
 *             extensionIdentifier: 'sirsoft-board',
 *             declaredKeys: self::DECLARED_KEYS,
 *             syncCallback: fn () => $this->syncBoardIdentityPolicies(),
 *         );
 *     }
 * }
 * ```
 *
 * @since 7.0.0-beta.4
 */
trait AssertsIdentityPolicyDeclaration
{
    /**
     * 선언된 정책이 정확한 source 컨텍스트로 동기화되었는지 검증합니다.
     *
     * @param  string  $extensionType  'module' 또는 'plugin'
     * @param  string  $extensionIdentifier  확장 식별자 (예: 'sirsoft-board')
     * @param  list<string>  $declaredKeys  module.php / plugin.php 의 getIdentityPolicies() 가 반환하는 정책 키 목록
     * @param  callable  $syncCallback  실제 동기화 동작 (보통 ModuleManager::syncModuleIdentityPolicies 의 helper 직접 호출)
     */
    protected function assertIdentityPoliciesSyncedForExtension(
        string $extensionType,
        string $extensionIdentifier,
        array $declaredKeys,
        callable $syncCallback,
    ): void {
        $syncCallback();

        foreach ($declaredKeys as $key) {
            $policy = IdentityPolicy::query()->where('key', $key)->first();
            $this->assertNotNull($policy, "정책 {$key} 가 동기화되지 않음");
            $this->assertSame($extensionType, $policy->source_type->value, "{$key} 의 source_type 이 {$extensionType} 가 아님");
            $this->assertSame($extensionIdentifier, $policy->source_identifier, "{$key} 의 source_identifier 가 {$extensionIdentifier} 가 아님");
            $this->assertFalse((bool) $policy->enabled, "정책 {$key} 의 기본값은 enabled=false 여야 함");
        }
    }

    /**
     * cleanupStalePolicies 가 선언에 없는 정책을 정리하는지 검증합니다.
     *
     * 호출 흐름: syncCallback() → 운영자가 1건 제거 시뮬레이션 → cleanupStalePolicies →
     * 제거된 키는 DB 에서 삭제 / 유지된 키는 보존.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @param  list<string>  $declaredKeys  전체 선언 키 (마지막 1건이 stale 시뮬레이션 대상)
     * @param  callable  $syncCallback  초기 동기화 콜백
     */
    protected function assertIdentityPolicyStaleCleanup(
        string $extensionType,
        string $extensionIdentifier,
        array $declaredKeys,
        callable $syncCallback,
    ): void {
        $syncCallback();

        $reducedKeys = array_slice($declaredKeys, 0, count($declaredKeys) - 1);
        $removedKey = $declaredKeys[count($declaredKeys) - 1];

        $helper = app(IdentityPolicySyncHelper::class);
        $helper->cleanupStalePolicies($extensionType, $extensionIdentifier, $reducedKeys);

        $this->assertNull(
            IdentityPolicy::query()->where('key', $removedKey)->first(),
            "stale 정책 {$removedKey} 가 정리되지 않음"
        );

        foreach ($reducedKeys as $key) {
            $this->assertNotNull(
                IdentityPolicy::query()->where('key', $key)->first(),
                "유지되어야 할 정책 {$key} 가 잘못 삭제됨"
            );
        }
    }

    /**
     * 운영자 수정값이 모듈 재동기화 후에도 보존되는지 검증합니다.
     *
     * @param  string  $key  검증 대상 정책 key (단일)
     * @param  array<string, mixed>  $overrides  운영자 수정 값 (예: ['enabled' => true, 'grace_minutes' => 120])
     * @param  callable  $syncCallback  동기화 콜백 (2회 호출됨)
     */
    protected function assertIdentityPolicyUserOverridesPreserved(
        string $key,
        array $overrides,
        callable $syncCallback,
    ): void {
        $syncCallback();

        $repository = app(\App\Contracts\Repositories\IdentityPolicyRepositoryInterface::class);
        $repository->updateByKey($key, $overrides, array_keys($overrides));

        // 재동기화 (모듈 update 시뮬레이션)
        $syncCallback();

        $policy = IdentityPolicy::query()->where('key', $key)->first()->refresh();
        foreach ($overrides as $field => $expected) {
            $actual = $policy->{$field};
            if (is_bool($expected)) {
                $actual = (bool) $actual;
            } elseif (is_int($expected)) {
                $actual = (int) $actual;
            }
            $this->assertSame(
                $expected,
                $actual,
                "재동기화 후 user_overrides 의 {$field} 값이 보존되지 않음"
            );
        }

        $this->assertEqualsCanonicalizing(
            array_keys($overrides),
            $policy->user_overrides ?? [],
            "user_overrides 컬럼이 정확히 기록되지 않음"
        );
    }
}
