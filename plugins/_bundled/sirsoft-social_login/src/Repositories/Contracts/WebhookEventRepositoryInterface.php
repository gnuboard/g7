<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 제공자 웹훅 수신 기록 저장소.
 *
 * @since 1.0.0
 */
interface WebhookEventRepositoryInterface
{
    /**
     * 처음 받은 알림이면 기록하고, 같은 event_key 가 이미 있으면 null 을 반환합니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (event_key 필수)
     * @return SocialLoginWebhookEvent|null 새 행, 중복이면 null
     */
    public function recordIfNew(array $attributes): ?SocialLoginWebhookEvent;

    /**
     * ID 로 이벤트를 찾습니다.
     *
     * @param  int  $id  이벤트 ID
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function find(int $id): ?SocialLoginWebhookEvent;

    /**
     * 사건 키로 이벤트를 찾습니다 (중복 수신 응답).
     *
     * @param  string  $eventKey  사건 키
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function findByEventKey(string $eventKey): ?SocialLoginWebhookEvent;

    /**
     * 확인 코드로 데이터 삭제 요청 이벤트를 찾습니다.
     *
     * @param  string  $code  확인 코드
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function findByConfirmationCode(string $code): ?SocialLoginWebhookEvent;

    /**
     * 이벤트 값을 바꿉니다.
     *
     * @param  SocialLoginWebhookEvent  $event  이벤트
     * @param  array<string, mixed>  $values  컬럼 값
     * @return void 반환값 없음
     */
    public function update(SocialLoginWebhookEvent $event, array $values): void;

    /**
     * 조건에 맞는 이벤트를 최신순 페이지로 반환합니다.
     *
     * @param  array<string, mixed>  $filters  provider·status·event_type
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 이벤트
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 제공자별 마지막 수신 시각을 반환합니다.
     *
     * @return array<string, Carbon|null> 제공자 키 => 시각
     */
    public function lastReceivedByProvider(): array;

    /**
     * 기간 안의 상태별 이벤트 수를 반환합니다.
     *
     * @param  \DateTimeInterface  $since  시작 시각
     * @return array<string, int> 상태 => 수
     */
    public function statusCountsSince(\DateTimeInterface $since): array;

    /**
     * 큐 실패 표에 남은 잡 수를 반환합니다.
     *
     * @param  string  $jobClass  잡 클래스 이름
     * @return int|null 수 (실패 표가 없으면 null)
     */
    public function failedQueueJobCount(string $jobClass): ?int;

    /**
     * 보존 기한이 지난 이벤트를 지웁니다.
     *
     * @param  \DateTimeInterface  $before  이 시각보다 오래된 행
     * @param  bool  $dryRun  true 면 세기만
     * @return int 대상 행 수
     */
    public function pruneBefore(\DateTimeInterface $before, bool $dryRun = false): int;
}
