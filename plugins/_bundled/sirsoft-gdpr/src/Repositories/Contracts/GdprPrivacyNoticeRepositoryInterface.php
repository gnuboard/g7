<?php

namespace Plugins\Sirsoft\Gdpr\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Plugins\Sirsoft\Gdpr\Models\GdprPrivacyNoticeLog;

/** 통지 대상과 추가 전용 증적의 접근 계약. */
interface GdprPrivacyNoticeRepositoryInterface
{
    /**
     * 가입 1년이 지난 활성 회원을 순차 조회합니다.
     *
     * @return LazyCollection 처리 결과
     */
    public function candidates(): LazyCollection;

    /**
     * 통지 접수 중인 회원 행을 잠급니다.
     *
     * @param  int  $userId  회원 ID
     * @return ?User 처리 결과
     */
    public function lockUser(int $userId): ?User;

    /**
     * 성공한 통지와 미완료 접수를 제외한 채널을 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array  $channels  채널 목록
     * @return array 처리 결과
     */
    public function dueChannels(int $userId, array $channels): array;

    /**
     * 기존 증적을 수정하지 않고 새 이력을 추가합니다.
     *
     * @param  array  $data  이력 데이터
     * @return GdprPrivacyNoticeLog 처리 결과
     */
    public function append(array $data): GdprPrivacyNoticeLog;

    /**
     * 접수 후 결과가 아직 없는 채널을 반환합니다.
     *
     * @param  string  $attemptId  접수 식별자
     * @return array 처리 결과
     */
    public function pending(string $attemptId): array;

    /**
     * 통지 요청의 최초 접수 이력을 조회합니다.
     *
     * @param  string  $attemptId  접수 식별자
     * @return ?GdprPrivacyNoticeLog 처리 결과
     */
    public function findAttempt(string $attemptId): ?GdprPrivacyNoticeLog;

    /**
     * 통지 이력을 최신 순으로 페이지 조회합니다.
     *
     * @param  int  $perPage  페이지당 행 수
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * 이력을 보존하며 삭제 회원 연결을 제거합니다.
     *
     * @param  int  $userId  회원 ID
     * @return void 반환값 없음
     */
    public function anonymize(int $userId): void;
}
