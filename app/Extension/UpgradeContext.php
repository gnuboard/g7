<?php

namespace App\Extension;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * 업그레이드 콜백/스텝에 전달되는 컨텍스트 객체
 *
 * 업그레이드 실행 중 필요한 버전 정보와 로거를 제공합니다.
 *
 * 정적 메뉴/권한 정리가 필요한 경우 UpgradeStep에서 아래와 같이 처리합니다:
 *
 * @example
 * // UpgradeStep에서 stale 메뉴 정리 예시
 * public function run(UpgradeContext $context): void
 * {
 *     $menuHelper = app(ExtensionMenuSyncHelper::class);
 *     $menuHelper->cleanupStaleMenus(
 *         ExtensionOwnerType::Module,
 *         'vendor-module',
 *         ['menu-slug-1', 'menu-slug-2'], // 유지할 slug 목록
 *     );
 * }
 */
class UpgradeContext
{
    /**
     * 업그레이드 전용 로그 채널
     */
    public readonly LoggerInterface $logger;

    /**
     * @param  string  $fromVersion  업그레이드 시작 버전 (현재 설치 버전)
     * @param  string  $toVersion  업그레이드 목표 버전
     * @param  string  $currentStep  현재 실행 중인 스텝 버전
     */
    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly string $currentStep = '',
    ) {
        $this->logger = Log::channel('upgrade');
    }

    /**
     * 테이블명에 DB 프리픽스를 적용하여 반환합니다.
     *
     * 업그레이드 스텝에서 raw SQL 사용 시 반드시 이 메서드로 테이블명을 감싸세요.
     * Schema 파사드는 자동으로 프리픽스를 적용하지만, DB::selectOne() 등
     * raw SQL에서는 프리픽스가 적용되지 않습니다.
     *
     * @param  string  $table  프리픽스 없는 테이블명 (예: 'users')
     * @return string 프리픽스가 적용된 테이블명 (예: 'g7_users')
     *
     * @example
     * // raw SQL에서 사용
     * DB::selectOne("SHOW COLUMNS FROM {$context->table('users')} WHERE Field = 'uuid'");
     *
     * // DB::table()은 자동 적용되므로 불필요 (하지만 사용해도 무방)
     * DB::table('users')->where(...); // 프리픽스 자동 적용
     */
    public function table(string $table): string
    {
        return DB::getTablePrefix().$table;
    }

    /**
     * 현재 스텝 버전을 변경한 새 컨텍스트를 반환합니다.
     *
     * @param  string  $stepVersion  현재 실행할 스텝 버전
     */
    public function withCurrentStep(string $stepVersion): self
    {
        return new self(
            fromVersion: $this->fromVersion,
            toVersion: $this->toVersion,
            currentStep: $stepVersion,
        );
    }
}
