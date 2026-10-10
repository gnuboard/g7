<?php

namespace App\Privacy\DTO;

use App\Helpers\TimezoneHelper;
use Illuminate\Support\Carbon;

/**
 * 개인정보 조치 대상 목록의 한 행
 *
 * 관리자 화면의 「대상 목록」 모달이 도메인과 무관하게 같은 모양으로 그리도록, 모든 목록
 * 공급자(보유기간 핸들러 · 휴면 단계 · 확장의 통지 대상)가 이 값 객체로 행을 내놓습니다.
 * 열의 뜻(무엇이 제목이고 무엇이 기준일인지)은 공급자가 따로 라벨로 알려 줍니다.
 *
 * 개인을 알아볼 수 있는 **최소한의 열**만 담습니다 — 모달은 「누가 대상인가」를 확인하는
 * 자리이지 상세 화면이 아닙니다. 더 볼 것은 {@see $url} 로 상세 화면을 엽니다.
 */
final readonly class PrivacyRetentionTarget
{
    /**
     * @param  int|string  $id  행 식별자 (목록 안에서 유일)
     * @param  string  $title  첫 열 — 회원 이름 · 주문번호 · 게시글 제목 등
     * @param  string|null  $subtitle  둘째 열 — 이메일 · 주문자 · 작성자 등
     * @param  string|null  $context  셋째 열 — 게시판 이름 · 글/댓글 구분 등 (없으면 null)
     * @param  Carbon|null  $date  기준일 — 탈퇴일 · 종결일 · 작성일 · 마지막 활동일 등
     * @param  string|null  $url  상세 화면 경로 (관리자가 새 탭으로 연다, 없으면 null)
     */
    public function __construct(
        public int|string $id,
        public string $title,
        public ?string $subtitle = null,
        public ?string $context = null,
        public ?Carbon $date = null,
        public ?string $url = null,
    ) {}

    /**
     * 응답용 배열로 변환합니다.
     *
     * 기준일은 사용자 시간대의 날짜(`Y-m-d`)로 내보냅니다 — 목록은 「언제부터 대상인가」를 보는
     * 자리라 시각까지는 필요 없고, 다른 관리자 화면의 날짜 열과 같은 형식이어야 합니다.
     *
     * @return array{id: int|string, title: string, subtitle: string|null, context: string|null, date: string|null, url: string|null} 행 배열
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'context' => $this->context,
            'date' => TimezoneHelper::toUserDateString($this->date),
            'url' => $this->url,
        ];
    }
}
