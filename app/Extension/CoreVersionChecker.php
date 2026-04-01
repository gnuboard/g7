<?php

namespace App\Extension;

use Composer\Semver\Semver;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 그누보드7 코어 버전 호환성 검증 유틸리티
 *
 * 확장(모듈, 플러그인, 템플릿)이 요구하는 그누보드7 코어 버전과
 * 현재 설치된 코어 버전의 호환성을 검증합니다.
 */
class CoreVersionChecker
{
    /**
     * 캐시 키 접두사
     */
    private const CACHE_PREFIX = 'core_version_check.';

    /**
     * 캐시 유효 시간 (초)
     */
    private const CACHE_TTL = 3600;

    /**
     * 현재 설치된 그누보드7 코어 버전 반환
     *
     * @return string 코어 버전
     */
    public static function getCoreVersion(): string
    {
        return config('app.version', '7.0.0-alpha.1');
    }

    /**
     * 버전 제약 조건 충족 여부 확인
     *
     * @param  string  $constraint  Semantic Versioning 제약 문자열
     * @return bool 충족 여부
     */
    public static function satisfies(string $constraint): bool
    {
        try {
            return Semver::satisfies(self::getCoreVersion(), $constraint);
        } catch (Exception $e) {
            Log::warning(__('extensions.errors.version_check_failed'), [
                'constraint' => $constraint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 확장의 코어 버전 호환성 검증
     *
     * @param  string|null  $requiredVersion  요구 버전 제약
     * @param  string  $identifier  확장 식별자
     * @param  string  $type  확장 타입 (module, plugin, template)
     * @return bool 항상 true (검증 실패 시 예외 발생)
     *
     * @throws Exception 버전 미충족 시
     */
    public static function validateExtension(
        ?string $requiredVersion,
        string $identifier,
        string $type
    ): bool {
        if ($requiredVersion === null) {
            return true;
        }

        if (! self::satisfies($requiredVersion)) {
            throw new Exception(__('extensions.errors.core_version_mismatch', [
                'extension' => $identifier,
                'type' => __('extensions.types.'.$type),
                'required' => $requiredVersion,
                'installed' => self::getCoreVersion(),
            ]));
        }

        return true;
    }

    /**
     * 확장의 코어 버전 호환성 확인 (예외 없음)
     *
     * @param  string|null  $requiredVersion  요구 버전 제약
     * @return bool 호환 여부
     */
    public static function isCompatible(?string $requiredVersion): bool
    {
        if ($requiredVersion === null) {
            return true;
        }

        return self::satisfies($requiredVersion);
    }

    /**
     * 버전 검증 캐시 키 생성
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @return string 캐시 키
     */
    public static function getCacheKey(string $type): string
    {
        return self::CACHE_PREFIX.$type.'.'.self::getCoreVersion();
    }

    /**
     * 모든 버전 검증 캐시 삭제
     */
    public static function clearCache(): void
    {
        Cache::forget(self::getCacheKey('modules'));
        Cache::forget(self::getCacheKey('plugins'));
        Cache::forget(self::getCacheKey('templates'));
    }

    /**
     * 캐시 TTL 반환
     *
     * @return int 캐시 유효 시간 (초)
     */
    public static function getCacheTtl(): int
    {
        return self::CACHE_TTL;
    }
}
