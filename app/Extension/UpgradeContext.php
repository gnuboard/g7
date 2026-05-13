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

    /**
     * 업그레이드 스텝의 spawn 프로세스(proc_open)에 전달할 환경변수 기본 세트를 반환합니다.
     *
     * 코어 업그레이드의 `updateVersionInEnv()` 는 Step 11 (마지막) 에서 실행되므로,
     * Step 10 의 upgrade step / spawn 은 디스크 `.env` 의 `APP_VERSION` 이 여전히
     * `fromVersion` 상태에서 부팅된다. 이 때 `CoreServiceProvider::boot()` 의
     * `validateAndDeactivateIncompatibleExtensions` 가 확장 manifest 의 요구 버전과
     * 구 APP_VERSION 을 비교하여 전 확장을 자동 비활성화하는 회귀가 발생한다.
     *
     * 본 헬퍼는 `APP_VERSION` 을 `toVersion` 으로 명시 전달해 spawn 자식이 새 버전 기준으로
     * 호환성 판정을 수행하도록 보장한다. 반환값을 `proc_open` 의 `$env` 인자로 그대로 넘기거나
     * `array_merge` 로 확장 전용 env 와 합쳐 사용한다.
     *
     * 추가 키를 병합할 때 예시:
     *   $env = $context->spawnEnv(['MY_FLAG' => '1']);
     *
     * @param  array<string, string>  $additional  병합할 추가 env 쌍 (APP_VERSION 보다 나중에 적용되어 덮어쓸 수 있음)
     * @return array<string, string>
     */
    public function spawnEnv(array $additional = []): array
    {
        return array_merge(
            $_ENV,
            ['APP_VERSION' => $this->toVersion],
            $additional,
        );
    }
}
