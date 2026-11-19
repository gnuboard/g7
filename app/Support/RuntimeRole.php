<?php

namespace App\Support;

/**
 * 이 서버의 실행 역할 — `live`(기본) 또는 `stage`.
 *
 * 같은 DB·캐시·저장소를 공유하는 두 번째 서버를 「검수용」으로 띄우면, 그 서버가 스케줄·큐 워커·사이트맵·SEO 캐시·방문 분석을
 * 함께 돌려 운영 데이터를 건드리고, 관리자 화면에서 확장·코어 수명주기를 바꿀 수 있었다. 역할은 서버 로컬 `.env`
 * (`G7_RUNTIME_ROLE`)로 정하고 공유 설정 JSON 보다 우선한다. 판정은 이 클래스 하나로 한다.
 *
 * @since 7.2.0
 */
final class RuntimeRole
{
    /**
     * 운영 서버 (기본)
     */
    public const LIVE = 'live';

    /**
     * 검수 서버 — 공유 데이터를 읽기만 하고 운영 작업은 하지 않는다
     */
    public const STAGE = 'stage';

    /**
     * 지금 역할을 돌려줍니다.
     *
     * @return string live|stage
     *
     * @throws \InvalidArgumentException live·stage 가 아닌 값일 때 (부팅 때 검증)
     */
    public static function current(): string
    {
        $role = strtolower(trim((string) config('core.runtime_role', self::LIVE)));
        if ($role === '') {
            return self::LIVE;
        }
        if (! in_array($role, [self::LIVE, self::STAGE], true)) {
            throw new \InvalidArgumentException((string) __('common.console.runtime_role.invalid', ['role' => $role]));
        }

        return $role;
    }

    /**
     * 검수 서버인지.
     *
     * @return bool stage 면 true
     */
    public static function isStage(): bool
    {
        return self::current() === self::STAGE;
    }

    /**
     * 사이트 주소(`app.url`)를 서버 로컬 `.env` 의 `APP_URL` 이 정하는지 — 검수 서버이고 `APP_URL` 을 적었을 때 true.
     *
     * 검수 서버는 운영과 같은 공유 설정을 읽으므로 공유 설정 「사이트 URL」(운영 주소)이 사이트 주소가 되면, 자기 주소로 들어온
     * 화면 요청을 정규 주소 이동이 운영 주소로 301 시키고 결제·본인인증 콜백도 운영 주소를 가리킨다. 그래서 검수 서버에서는
     * 서버 로컬 `APP_URL` 이 이긴다(`G7_ENV_PRIORITY` 와 무관). 값은 요청이 아니라 `.env` 에서 오므로 Host 신뢰 범위는 넓어지지
     * 않는다. 명시 여부는 설정 캐시 때 잡아 둔 `env-priority.explicit` 로 본다(`env()` 는 캐시 환경에서 null).
     *
     * @return bool 검수 서버이고 `.env` 에 APP_URL 이 있으면 true
     */
    public static function siteUrlFromLocalEnv(): bool
    {
        return self::isStage() && (config('env-priority.explicit.APP_URL') ?? false) === true;
    }

    /**
     * 이 서버가 스케줄을 실행하는지 — 스케줄 실행 판정의 단일 지점.
     *
     * stage 역할이면 false, 아니면 서버 로컬 스위치 `core.scheduler.enabled`(`G7_SCHEDULER=on|off`, 기본 on).
     * 공유 설정 `system.cron_mode` 는 서버마다 다르게 둘 수 없어, 서버 한 대만 스케줄을 돌리게 할 때 이 스위치를 쓴다.
     *
     * @return bool 실행하면 true
     */
    public static function schedulerEnabled(): bool
    {
        if (self::isStage()) {
            return false;
        }

        return (bool) config('core.scheduler.enabled', true);
    }
}
