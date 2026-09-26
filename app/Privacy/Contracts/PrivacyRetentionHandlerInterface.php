<?php

namespace App\Privacy\Contracts;

use App\Privacy\DTO\PrivacyRetentionTarget;
use App\Privacy\PrivacyRetentionManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 개인정보 보유기간 처리 핸들러 계약
 *
 * 한 도메인(코어 회원, 이커머스 주문, 게시판 글 …)의 "보유기간이 지난 개인정보를
 * 어떻게 파기·익명화할 것인가" 를 그 도메인 소유자가 스스로 구현합니다.
 * 코어는 무엇이 지워지는지 알지 못한 채 수집·실행·집계만 합니다.
 *
 * ## 등록
 *
 * 코어 기본 핸들러는 {@see PrivacyRetentionManager} 가 직접 등록하고,
 * 확장은 필터 훅으로 자기 도메인 핸들러를 더합니다.
 *
 * ```php
 * HookManager::addFilter('core.privacy.retention_handlers', function (array $handlers) {
 *     $handlers[] = OrderRetentionHandler::class;
 *
 *     return $handlers;
 * });
 * ```
 *
 * ## 기본은 언제나 꺼짐이다
 *
 * 이 계약이 하는 일은 **되돌릴 수 없는 파기**입니다. 확장을 설치했다는 사실만으로
 * 회원 데이터가 지워지면 안 되므로, {@see isEnabled()} 는 운영자가 자기 도메인 설정에서
 * 명시적으로 켰을 때만 true 를 돌려줍니다(기본 false 폴백). 커맨드는 꺼진 핸들러를
 * 건너뛰되 "꺼져 있어 건너뜀" 과 "대상 0건" 을 구분해 보고합니다 — 둘을 같은 0 으로
 * 뭉개면 운영자가 켠 줄 알고 있는 기능이 조용히 놀고 있어도 알아챌 수 없습니다.
 *
 * ## 미리보기와 실행은 같은 모집단을 봐야 한다
 *
 * {@see preview()} 가 돌려준 건수와 `process(dryRun: false)` 의 처리 건수가 어긋나면
 * 운영자가 화면에서 본 숫자와 실제로 지워진 양이 다릅니다. 두 메서드는 **같은 조건식**을
 * 공유해야 하며, 멱등(이미 처리된 행은 다시 세지 않음)도 그 조건식이 책임집니다.
 * {@see targets()} 도 같은 조건식을 씁니다 — 관리자 화면의 「대상 목록」은 배치가 처리할
 * 명단 그 자체여야 합니다.
 *
 * ## 대상은 꺼져 있어도 볼 수 있어야 한다
 *
 * {@see preview()}·{@see targets()} 는 {@see isEnabled()} 와 무관하게 저장된 기간값으로
 * 셉니다. 운영자는 켜기 **전에** 규모와 명단을 확인해야 하기 때문입니다. 꺼짐 여부는
 * 수집기가 따로 알려 주므로 건수를 null 로 가릴 필요가 없습니다. 실제 파기(`process()`)만
 * 토글을 봅니다.
 */
interface PrivacyRetentionHandlerInterface
{
    /**
     * 핸들러 식별자를 반환합니다.
     *
     * `--handler=` 옵션과 보고 표의 키로 쓰이므로 전역 유일해야 하며,
     * 확장 핸들러는 확장 식별자를 접두사로 둡니다(`sirsoft-ecommerce.order`).
     *
     * @return string 핸들러 식별자
     */
    public function id(): string;

    /**
     * 운영자에게 보여 줄 이름을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string;

    /**
     * 자기 도메인 설정을 읽어 활성 여부를 반환합니다 (기본 false).
     *
     * @return bool 활성 여부
     */
    public function isEnabled(): bool;

    /**
     * 보유기간이 지난 대상 건수를 반환합니다 (데이터를 바꾸지 않음).
     *
     * @return int 대상 건수
     */
    public function preview(): int;

    /**
     * 보유기간이 지난 대상 목록의 한 페이지를 반환합니다 (데이터를 바꾸지 않음).
     *
     * {@see preview()} 와 **같은 조건식**을 씁니다 — 관리자가 모달에서 본 명단이 곧 배치가
     * 처리할 명단이어야 합니다. 기능이 꺼져 있어도 저장된 기간값 기준으로 돌려줍니다(켜기
     * 전에 규모와 대상을 확인하는 자리이기 때문입니다). 정렬은 기준일 오름차순(가장 먼저
     * 대상이 된 것부터)을 권장합니다.
     *
     * @param  int  $page  페이지 번호 (1부터)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator<int, PrivacyRetentionTarget> 대상 행 페이지
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator;

    /**
     * 대상 목록의 열 라벨을 반환합니다.
     *
     * 키는 {@see PrivacyRetentionTarget} 의 열 이름(`title`·`subtitle`·`context`·`date`)이고
     * 값은 다국어 라벨입니다. 쓰지 않는 열은 키를 빼면 화면이 그 열을 그리지 않습니다.
     *
     * @return array<string, string> 열 이름 => 라벨
     */
    public function targetColumns(): array;

    /**
     * 대상 목록을 볼 수 있는 관리자 권한 식별자를 반환합니다 (모두 필요).
     *
     * 목록에는 이름·이메일 같은 개인정보가 그대로 나오므로 **설정 읽기 + 그 도메인 읽기**를
     * 함께 요구합니다(예: `sirsoft-ecommerce.settings.read` + `sirsoft-ecommerce.orders.read`).
     * 설정 화면을 볼 수 있다는 이유만으로 회원·주문 명단을 보는 우회 경로가 생기면 안 됩니다.
     * 코어 목록 API 가 요청 시점에 이 목록을 검사합니다.
     *
     * @return array<int, string> 권한 식별자 목록
     */
    public function targetPermissions(): array;

    /**
     * 관리자 직권 실행(화면의 「지금 실행」)에 필요한 권한 식별자를 반환합니다 (모두 필요).
     *
     * 명단 권한({@see targetPermissions()})의 **쓰기 대칭** — 설정 수정 + 그 도메인 수정
     * (예: `sirsoft-ecommerce.settings.update` + `sirsoft-ecommerce.orders.update`). 처리 방식이
     * 행을 지우는 것이면 그 도메인의 삭제 권한도 더합니다 — 삭제 권한 없이 설정 화면을 거쳐
     * 행을 지우는 우회 경로가 생기면 안 됩니다. 코어 실행 API 가 요청 시점에 검사하며, 명단과
     * 같이 각 권한의 적용 범위가 **전체**일 때만 허용합니다.
     *
     * @return array<int, string> 권한 식별자 목록
     */
    public function runPermissions(): array;

    /**
     * 보유기간이 지난 대상을 처리합니다.
     *
     * 자동 배치(`$force = false`)는 {@see isEnabled()} 를 다시 확인해 꺼져 있으면 아무것도 바꾸지
     * 않습니다. **관리자 직권 실행**(`$force = true`)은 토글과 무관하게 저장된 기간값(없으면
     * 기본값)으로 처리합니다 — 자동 배치는 끄고 운영자가 필요할 때만 손으로 돌리는 운영을
     * 허용하기 위함입니다(2026-09-25 확정). 직권 실행도 처리 조건식은 {@see preview()}·
     * {@see targets()} 와 같습니다.
     *
     * @param  bool  $dryRun  true 면 건수만 세고 데이터를 바꾸지 않습니다
     * @param  bool  $force  true 면 관리자 직권 실행 — 활성 토글을 보지 않습니다
     * @return array{count: int, mode: string} 처리 건수와 처리 방식(anonymize|delete 등)
     */
    public function process(bool $dryRun, bool $force = false): array;
}
