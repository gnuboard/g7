<?php

namespace Plugins\Sirsoft\Gdpr\Support;

use Illuminate\Support\Collection;

/**
 * 한 요청 안의 동의 조회 메모 (#860)
 *
 * 쿠키 동의 미들웨어·동의 제공자·동의 현황 리소스가 한 요청에서 현재 정책 버전과 같은 주체의 동의 행을
 * 여러 번 읽던 것을 한 번으로 줄인다. 서비스는 지금처럼 필요할 때마다 만들고(저장소 교체 등 기존 사용 방식
 * 유지), 메모만 이 요청 단위(scoped) 보관함에 둔다 — 큐 워커·상주 프로세스는 작업·요청마다 새로 받는다.
 *
 * 무효화: 정책 버전·동의 행 모델이 저장·삭제되면 비우고(모델 이벤트), 이벤트 없는 대량 쓰기 뒤에는
 * 동의 서비스가 직접 비운다.
 */
class GdprConsentReadMemo
{
    /** 현재 정책 버전 (null = 아직 안 읽음) */
    public ?int $policyVersion = null;

    /**
     * 회원 ID → 동의 행.
     *
     * @var array<int, Collection>
     */
    public array $userConsentRows = [];

    /**
     * 게스트 세션 ID → 동의 내역.
     *
     * @var array<string, Collection>
     */
    public array $sessionHistoryRows = [];

    /**
     * 지금 요청의 보관함을 반환합니다 (플러그인 provider 가 등록되지 않은 맥락이면 메모 없는 일회용).
     *
     * @return self 보관함
     */
    public static function current(): self
    {
        return app()->bound(self::class) ? app(self::class) : new self;
    }

    /**
     * 지금 요청의 정책 버전 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public static function forgetPolicyVersion(): void
    {
        if (app()->bound(self::class) && app()->resolved(self::class)) {
            app(self::class)->policyVersion = null;
        }
    }

    /**
     * 지금 요청의 동의 행 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public static function forgetConsentRows(): void
    {
        if (app()->bound(self::class) && app()->resolved(self::class)) {
            $memo = app(self::class);
            $memo->userConsentRows = [];
            $memo->sessionHistoryRows = [];
        }
    }
}
