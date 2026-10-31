<?php

namespace App\Privacy\Contracts;

use App\Privacy\ManualRun\PrivacyManualRunRegistry;

/**
 * 개인정보 조치 관리자 직권 실행 대상 계약
 *
 * 관리자 화면의 「지금 실행」 버튼 하나가 곧 이 계약의 구현 하나다. 스케줄러가 매일 새벽
 * 돌리는 개인정보 조치(보유기간 파기·휴면 단계·연간 통지 …)를 운영자가 원할 때 큐에 넣어
 * 한 번 실행하게 한다. 코어는 무엇을 처리하는지 모른 채 접수·중복 거절·실행 기록·상태 조회를
 * 한 벌로 제공한다.
 *
 * ## 등록
 *
 * 코어는 보유기간 핸들러마다(`retention:{핸들러 식별자}`)와 휴면 4단계(`dormant:{단계}`)의
 * 대상을 스스로 등록한다. 보유기간 핸들러를 등록한 확장은 **추가 등록이 필요 없다.** 그 밖의
 * 조치를 가진 확장은 필터 훅으로 클래스명을 더한다({@see PrivacyManualRunRegistry}).
 *
 * ```php
 * HookManager::addFilter('core.privacy.manual_run_targets', function (array $runners) {
 *     $runners[] = AnnualNoticeManualRunner::class;
 *
 *     return $runners;
 * });
 * ```
 *
 * ## 토글과 무관하다
 *
 * 직권 실행은 자동 실행 토글({@see isEnabled()})을 보지 않는다 — 자동 실행을 끄고 필요할 때만
 * 손으로 돌리는 운영을 허용한다(2026-09-25 확정). 토글 값은 화면의 안내 문구에만 쓴다.
 * 대신 권한({@see permissions()})이 접수 시점에 검사된다.
 *
 * 예외: 처리 결과의 효력이 토글에 묶인 조치(비밀번호 만료 — 꺼져 있으면 로그인 관문이 변경을 요구하지
 * 않는다)는 {@see PrivacyManualRunRequiresEnabledInterface} 를 함께 구현한다. 그러면 꺼져 있을 때 코어가
 * 접수를 409 로 거절한다.
 *
 * ## 건수와 실행은 같은 모집단이다
 *
 * {@see count()} 는 확인 창에 보이는 숫자이고 {@see run()} 은 그 모집단을 처리한다. 두 메서드는
 * 같은 조건식을 공유해야 한다 — 운영자가 확인한 숫자와 실제로 처리된 양이 달라지면 안 된다.
 */
interface PrivacyManualRunnerInterface
{
    /**
     * 대상 키를 반환합니다 — `{종류}:{식별자}` 형태, 전역 유일.
     *
     * 실행 기록·접수 API 경로·상태 조회의 키다. 확장은 확장 식별자를 앞에 둔다
     * (`sirsoft-gdpr:annual_notice`).
     *
     * @return string 대상 키
     */
    public function key(): string;

    /**
     * 운영자에게 보여 줄 이름을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string;

    /**
     * 직권 실행에 필요한 관리자 권한 식별자를 반환합니다 (모두 필요, 적용 범위 전체만).
     *
     * 설정 수정 + 그 도메인 수정이 기준이다. 빈 배열이면 코어가 거부한다.
     *
     * @return array<int, string> 권한 식별자 목록
     */
    public function permissions(): array;

    /**
     * 자동 실행 토글이 켜져 있는지 반환합니다 (화면 안내용 — 직권 실행은 이 값을 보지 않는다.
     * {@see PrivacyManualRunRequiresEnabledInterface} 를 구현한 대상만 꺼져 있으면 접수가 거절된다).
     *
     * @return bool 자동 실행 활성 여부
     */
    public function isEnabled(): bool;

    /**
     * 「대기기간 생략」 옵션을 받는지 반환합니다.
     *
     * @return bool 생략 옵션 지원 여부
     */
    public function supportsSkipWaiting(): bool;

    /**
     * 처리 방식 식별자를 반환합니다 (`anonymize`·`delete`·`notify` 등, 없으면 null).
     *
     * @return string|null 처리 방식
     */
    public function mode(): ?string;

    /**
     * 지금 실행하면 처리될 건수를 반환합니다 (데이터를 바꾸지 않음).
     *
     * @param  bool  $skipWaiting  대기기간 생략 ({@see supportsSkipWaiting()} 가 false 면 무시)
     * @return int 대상 건수
     */
    public function count(bool $skipWaiting = false): int;

    /**
     * 직권으로 처리합니다 — 큐 작업 안에서 호출됩니다.
     *
     * 실패는 던진다. 코어가 실행 기록에 실패와 사유를 남긴다.
     *
     * @param  bool  $skipWaiting  대기기간 생략 ({@see supportsSkipWaiting()} 가 false 면 무시)
     * @return array{count: int, mode?: string|null, remaining?: int|null} 처리 건수·방식·남은 건수
     */
    public function run(bool $skipWaiting = false): array;

    /**
     * 스케줄러 배치와 공유하는 락 키를 반환합니다 (없으면 null).
     *
     * 같은 락을 자동 배치 커맨드도 잡는다 — 직권 실행과 새벽 배치가 같은 행을 동시에
     * 처리하지 않게 한다. 멱등이라 겹쳐도 안전한 조치는 null 을 돌려준다.
     *
     * @return string|null 캐시 락 키
     */
    public function lockKey(): ?string;
}
