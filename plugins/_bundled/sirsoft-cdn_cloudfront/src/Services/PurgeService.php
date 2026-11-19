<?php

namespace Plugins\Sirsoft\CdnCloudfront\Services;

use App\Contracts\Extension\CacheInterface;
use App\Support\SafeExceptionMessage;
use Aws\Exception\AwsException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\CdnCloudfront\Models\CloudfrontPurge;
use Plugins\Sirsoft\CdnCloudfront\Repositories\Contracts\PurgeQueueRepositoryInterface;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontSettings;
use Plugins\Sirsoft\CdnCloudfront\Support\InvalidationPaths;

/**
 * CloudFront 캐시 무효화 — 보내기·실패 대기열·재시도·현황
 *
 * 무효화는 코어 퍼지 묶음 하나당 CreateInvalidation 한 번이다(요청 단위 묶음). 접어도 요청 하나에 안 들어가면 나눠
 * 보낸다. 실패하면 그 요청의 경로와 호출자 참조를 대기열에 넣고, 정기 작업이 `BASE_DELAY_MINUTES × 2^(실패 횟수-1)` 분
 * 뒤에 다시 보낸다. 실패가 MAX_ATTEMPTS 회에 닿으면 재시도를 멈추고 행은 남겨 설정 화면에 보인다. 멈춘 행은
 * ABANDONED_RETENTION_DAYS 일 뒤 정기 작업이 지운다.
 *
 * 와일드카드 한도(진행 중 합계 15)는 배포 전체에 걸리므로 재시도는 시각이 된 대기 건들의 경로를 합쳐 다시 접어 보낸다.
 * 합친 요청이 거절되면 시도 횟수를 쓰지 않고 건별로 다시 보낸다 — 불량 건 하나가 함께 묶인 정상 건까지 실패로 세게 해
 * 버리게 만들지 않는다. 진행 중 한도, 호출 제한 오류(TRANSIENT_ERROR_CODES)는 앞선 무효화가 끝나면 풀리는 오류라
 * 시도 횟수로 세지 않는다. 세면 바쁜 시간대에 대기 건이 상한에 닿아 버려진다.
 *
 * 퍼지가 실패해도 예외를 밖으로 내지 않는다 — 코어는 파생본 삭제 요청의 일부로 이 훅을 부르므로,
 * CDN 장애가 게시글 삭제 같은 사용자 작업을 실패로 만들면 안 된다.
 */
class PurgeService
{
    /** 실패 횟수 상한 (첫 시도 포함) — 여기에 닿으면 재시도를 멈춘다 */
    public const MAX_ATTEMPTS = 5;

    /** 첫 재시도 대기 (분) — 이후 두 배씩 늘어난다 */
    public const BASE_DELAY_MINUTES = 5;

    /** 정기 작업 한 번에 다시 보낼 최대 건수 */
    public const RETRY_BATCH = 20;

    /** 재시도를 멈춘 행을 남겨 둘 기간 (일) — 설정 화면에서 사유를 확인할 시간을 준 뒤 지운다 */
    public const ABANDONED_RETENTION_DAYS = 30;

    /** 마지막 성공 시각 캐시 키 */
    private const LAST_SUCCESS_KEY = 'purge.last_success_at';

    /** 마지막 성공 시각 보관 (초) — 현황 표시용이라 캐시를 비우면 사라져도 된다 */
    private const LAST_SUCCESS_TTL = 2592000;

    /** 오류 문구 최대 길이 */
    private const ERROR_MAX = 1000;

    /** 기다리면 풀리는 오류 코드 — 시도 횟수로 세지 않고 BASE_DELAY_MINUTES 뒤 다시 보낸다 */
    public const TRANSIENT_ERROR_CODES = [
        'TooManyInvalidationsInProgress',
        'Throttling',
        'ThrottlingException',
        'TooManyRequestsException',
        'RequestLimitExceeded',
    ];

    /**
     * 무효화에 쓸 설정·클라이언트·대기열·캐시를 주입받습니다.
     *
     * @param  CloudFrontSettings  $settings  플러그인 설정
     * @param  CloudFrontClientFactory  $clients  API 클라이언트 생성기
     * @param  PurgeQueueRepositoryInterface  $queue  실패 대기열
     * @param  CacheInterface  $cache  플러그인 캐시 (마지막 성공 시각)
     */
    public function __construct(
        private readonly CloudFrontSettings $settings,
        private readonly CloudFrontClientFactory $clients,
        private readonly PurgeQueueRepositoryInterface $queue,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 코어 퍼지 묶음을 무효화 요청으로 보냅니다 (보통 한 건, 한도를 넘으면 나눠서).
     *
     * 자동 무효화가 꺼져 있거나 자격·CDN 주소가 없으면 아무것도 하지 않는다. CDN 호스트를 가리키는
     * 경로가 하나도 없으면(로컬 공개 디스크의 파생본 등) 역시 보내지 않는다.
     *
     * 나눈 요청은 각자 호출자 참조를 갖고 실패한 요청만 대기열에 들어간다. 호출 제한에 걸리면 남은 요청은 보내 봐야 같은
     * 오류이므로 보내지 않고 바로 대기열에 넣는다.
     *
     * @param  list<array<string, mixed>>  $items  코어 퍼지 항목 (`core.thumbnail.after_purge` 인자)
     * @return string 결과 — sent(모두 보냄), queued(일부라도 실패해 대기열에 넣음), skipped(보낼 것 없음), lost(대기열에도 못 넣은 요청이 있음)
     */
    public function purgeItems(array $items): string
    {
        if (! $this->settings->purgeReady()) {
            return 'skipped';
        }

        $requests = InvalidationPaths::requests(InvalidationPaths::fromItems($items, (string) $this->settings->cdnHost()));

        if ($requests === []) {
            return 'skipped';
        }

        $outcome = 'sent';
        $throttled = null;

        foreach ($requests as $paths) {
            // 재시도도 이 값을 그대로 쓴다 — 응답만 잃은 첫 요청을 CloudFront 가 같은 무효화로 알아본다.
            $reference = Str::uuid()->toString();

            if ($throttled !== null) {
                $outcome = $this->enqueue($paths, $reference, $throttled) && $outcome !== 'lost' ? 'queued' : 'lost';

                continue;
            }

            try {
                $this->invalidate($paths, self::callerReference($reference));
                $this->markSuccess();
            } catch (\Throwable $e) {
                $throttled = $this->isTransient($e) ? $e : null;
                $outcome = $this->enqueue($paths, $reference, $e) && $outcome !== 'lost' ? 'queued' : 'lost';
            }
        }

        return $outcome;
    }

    /**
     * 재시도 시각이 된 대기 건을 합쳐 한 번에 다시 보냅니다 (정기 작업).
     *
     * 자격이 없거나 자동 무효화가 꺼져 있으면 시도 횟수를 쓰지 않고 그대로 둔다 — 운영자가 키를 고치는 동안
     * 대기 건이 상한에 닿아 버려지지 않게 한다.
     *
     * 먼저 합쳐서 보낸다. 와일드카드 한도는 배포 전체의 진행 중 합계라서, 대기 건 스무 개를 연달아 보내면
     * 앞의 몇 건이 한도를 채우고 나머지는 `TooManyInvalidationsInProgress` 로 거절돼 시도 횟수만 쓴다.
     * 합친 요청이 거절되면 어느 건이 불량인지 모르므로 시도 횟수를 쓰지 않고 건별로 다시 보낸다. 대기 건이 하나면 합칠
     * 것이 없어 바로 건별 전송이다.
     *
     * @param  Carbon|null  $now  기준 시각 (기본: 지금)
     * @return array{retried: int, succeeded: int, failed: int, abandoned: int, deferred: int} 처리 건수 (deferred 는 한도나 호출 제한으로 미룬 건, 시도 횟수 그대로)
     */
    public function retryDue(?Carbon $now = null): array
    {
        $result = ['retried' => 0, 'succeeded' => 0, 'failed' => 0, 'abandoned' => 0, 'deferred' => 0];
        $now ??= Carbon::now();

        // 자격을 고치는 중이어도 오래된 중단 건은 지운다 — 다시 보낼 건이 아니다.
        $this->queue->deleteAbandonedBefore($now->copy()->subDays(self::ABANDONED_RETENTION_DAYS));

        if (! $this->settings->purgeReady()) {
            return $result;
        }

        $due = $this->queue->due($now, self::RETRY_BATCH)->sortBy('id')->values();

        if ($due->isEmpty()) {
            return $result;
        }

        $result['retried'] = $due->count();

        if ($due->count() > 1) {
            try {
                $this->sendMerged($due);
            } catch (\Throwable $e) {
                if ($this->isTransient($e)) {
                    $this->defer($due, $this->describe($e), $now, $result);

                    return $result;
                }

                return $this->resendEach($due, $now, $result);
            }

            foreach ($due as $purge) {
                $this->queue->delete($purge);
            }

            $this->markSuccess();
            $result['succeeded'] = $due->count();

            return $result;
        }

        return $this->resendEach($due, $now, $result);
    }

    /**
     * 대기 건을 하나씩 첫 시도의 본문과 호출자 참조로 다시 보냅니다.
     *
     * 실패한 건만 시도 횟수를 올린다. 호출 제한에 걸리면 그 건과 남은 건을 시도 횟수 없이 미룬다.
     *
     * @param  Collection<int, CloudfrontPurge>  $due  대기 건 (id 순)
     * @param  Carbon  $now  기준 시각
     * @param  array{retried: int, succeeded: int, failed: int, abandoned: int, deferred: int}  $result  처리 건수
     * @return array{retried: int, succeeded: int, failed: int, abandoned: int, deferred: int} 처리 건수
     */
    private function resendEach(Collection $due, Carbon $now, array $result): array
    {
        foreach ($due as $index => $purge) {
            try {
                $this->invalidate(array_values((array) $purge->paths), self::callerReference((string) $purge->caller_reference));
            } catch (\Throwable $e) {
                if ($this->isTransient($e)) {
                    $this->defer($due->slice($index), $this->describe($e), $now, $result);

                    return $result;
                }

                $this->recordFailedAttempt($purge, $this->describe($e), $now, $result);

                continue;
            }

            $this->queue->delete($purge);
            $this->markSuccess();
            $result['succeeded']++;
        }

        return $result;
    }

    /**
     * 대기 건을 시도 횟수 없이 다음 정기 작업 차례로 미룹니다 — 앞선 무효화가 끝나야 풀리는 오류일 때.
     *
     * @param  Collection<int, CloudfrontPurge>  $purges  미룰 건
     * @param  string  $error  실패 사유
     * @param  Carbon  $now  기준 시각
     * @param  array{retried: int, succeeded: int, failed: int, abandoned: int, deferred: int}  $result  처리 건수 (참조로 갱신)
     * @return void 반환값 없음
     */
    private function defer(Collection $purges, string $error, Carbon $now, array &$result): void
    {
        $next = $now->copy()->addMinutes(self::BASE_DELAY_MINUTES);

        foreach ($purges as $purge) {
            $this->queue->recordFailure($purge, $purge->attempts, $error, $next);
            $result['deferred']++;
        }
    }

    /**
     * 대기 건의 시도 횟수를 올리고 다음 시각을 잡습니다 (상한에 닿으면 재시도를 멈춘다).
     *
     * @param  CloudfrontPurge  $purge  대기 건
     * @param  string  $error  실패 사유
     * @param  Carbon  $now  기준 시각
     * @param  array{retried: int, succeeded: int, failed: int, abandoned: int, deferred: int}  $result  처리 건수 (참조로 갱신)
     * @return void 반환값 없음
     */
    private function recordFailedAttempt(CloudfrontPurge $purge, string $error, Carbon $now, array &$result): void
    {
        $attempts = $purge->attempts + 1;
        $next = $attempts >= self::MAX_ATTEMPTS ? null : $now->copy()->addMinutes($this->delayMinutes($attempts));
        $this->queue->recordFailure($purge, $attempts, $error, $next);
        $result['failed']++;

        if ($next === null) {
            $result['abandoned']++;
            Log::warning('CloudFront 캐시 무효화 재시도 상한 도달 — 재시도를 멈춥니다', ['id' => $purge->id, 'attempts' => $attempts]);
        }
    }

    /**
     * 설정 화면 「무효화 대기 현황」
     *
     * @return array{pending: int, abandoned: int, last_error: ?string, last_failed_at: ?string, last_success_at: ?string, purge_ready: bool} 현황
     */
    public function status(): array
    {
        $latest = $this->queue->latestFailure();
        $lastSuccess = $this->cache->get(self::LAST_SUCCESS_KEY);

        return [
            'pending' => $this->queue->countPending(),
            'abandoned' => $this->queue->countAbandoned(),
            'last_error' => $latest?->last_error,
            'last_failed_at' => $latest?->updated_at?->toIso8601String(),
            'last_success_at' => is_string($lastSuccess) ? $lastSuccess : null,
            'purge_ready' => $this->settings->purgeReady(),
        ];
    }

    /**
     * 저장된 설정으로 배포를 조회해 연결을 시험합니다 (GetDistribution).
     *
     * @return array{ok: bool, reason: ?string, error: ?string, status: ?string, domain_name: ?string, enabled: ?bool, cdn_host_matches: ?bool} 결과
     */
    public function testConnection(): array
    {
        $empty = ['status' => null, 'domain_name' => null, 'enabled' => null, 'cdn_host_matches' => null];

        if (! $this->settings->hasCredentials()) {
            return ['ok' => false, 'reason' => 'missing_credentials', 'error' => null] + $empty;
        }

        try {
            $result = $this->clients
                ->make($this->settings->accessKey(), $this->settings->secretKey())
                ->getDistribution(['Id' => $this->settings->distributionId()]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'request_failed', 'error' => $this->describe($e)] + $empty;
        }

        $distribution = (array) ($result['Distribution'] ?? []);
        $domain = isset($distribution['DomainName']) ? strtolower((string) $distribution['DomainName']) : null;
        $aliases = array_map('strtolower', (array) ($distribution['DistributionConfig']['Aliases']['Items'] ?? []));
        $host = $this->settings->cdnHost();

        return [
            'ok' => true,
            'reason' => null,
            'error' => null,
            'status' => isset($distribution['Status']) ? (string) $distribution['Status'] : null,
            'domain_name' => $domain,
            'enabled' => isset($distribution['DistributionConfig']['Enabled']) ? (bool) $distribution['DistributionConfig']['Enabled'] : null,
            // CDN 주소가 배포 도메인이나 대체 도메인이 아니면 무효화가 다른 배포를 향한다 — 화면이 경고한다.
            'cdn_host_matches' => $host === null ? null : ($host === $domain || in_array($host, $aliases, true)),
        ];
    }

    /**
     * 대기 건 여러 개의 경로를 합쳐 다시 접어 보냅니다.
     *
     * CloudFront 는 같은 호출자 참조에 같은 본문이 오면 새 무효화를 만들지 않고 앞선 것을 돌려주고, 본문이 다르면
     * `InvalidationBatchAlreadyExists` 로 거절한다. 그래서 참조는 대기 건 참조들과 요청 순번으로 정해지는 값이다 — 같은
     * 묶음을 다시 보내면 본문도 참조도 같다(`InvalidationPaths::fold()` 는 입력 순서와 무관한 결과를 낸다). 나눈 요청
     * 중간에 실패해 다시 보내도 앞서 접수된 요청은 CloudFront 가 같은 무효화로 알아본다.
     *
     * @param  Collection<int, CloudfrontPurge>  $due  대기 건 (id 순)
     * @return void 반환값 없음
     *
     * @throws \Throwable 요청 실패
     */
    private function sendMerged(Collection $due): void
    {
        $paths = InvalidationPaths::fold($due->flatMap(static fn (CloudfrontPurge $purge) => (array) $purge->paths)->all());
        $references = $due->map(static fn (CloudfrontPurge $purge) => (string) $purge->caller_reference)->sort()->implode(',');

        foreach (InvalidationPaths::requests($paths) as $index => $request) {
            $this->invalidate($request, 'g7-merge-'.sha1($references.'#'.$index));
        }
    }

    /**
     * 실패한 요청을 대기열에 넣습니다.
     *
     * 대기열 쓰기 실패(DB 장애 등)도 밖으로 내지 않는다 — 코어 삭제 요청의 일부로 불린다.
     *
     * @param  list<string>  $paths  이 요청의 경로
     * @param  string  $reference  호출자 참조 UUID
     * @param  \Throwable  $e  실패 원인
     * @return bool 대기열에 넣었으면 true
     */
    private function enqueue(array $paths, string $reference, \Throwable $e): bool
    {
        $error = $this->describe($e);
        $transient = $this->isTransient($e);
        $next = Carbon::now()->addMinutes($transient ? self::BASE_DELAY_MINUTES : $this->delayMinutes(1));

        try {
            $this->queue->create($paths, $reference, $transient ? 0 : 1, $error, $next);
        } catch (\Throwable $queueError) {
            Log::warning('CloudFront 캐시 무효화 실패 — 재시도 대기열에도 넣지 못했습니다', [
                'paths' => count($paths),
                'error' => $error,
                'queue_error' => class_basename($queueError).': '.$queueError->getMessage(),
            ]);

            return false;
        }

        Log::warning('CloudFront 캐시 무효화 실패 — 재시도 대기열에 넣었습니다', ['paths' => count($paths), 'error' => $error]);

        return true;
    }

    /**
     * 대기 건 참조(UUID)를 CloudFront 호출자 참조 문자열로 만듭니다.
     *
     * @param  string  $uuid  참조 UUID
     * @return string 호출자 참조
     */
    private static function callerReference(string $uuid): string
    {
        return 'g7-'.$uuid;
    }

    /**
     * 기다리면 풀리는 오류인지 (진행 중 무효화 한도, 호출 제한)
     *
     * @param  \Throwable  $e  예외
     * @return bool 시도 횟수로 세지 않을 오류면 true
     */
    private function isTransient(\Throwable $e): bool
    {
        if (! $e instanceof AwsException) {
            return false;
        }

        return in_array($e->getAwsErrorCode(), self::TRANSIENT_ERROR_CODES, true) || $e->getStatusCode() === 429;
    }

    /**
     * CreateInvalidation 을 보냅니다.
     *
     * @param  list<string>  $paths  무효화 경로
     * @param  string  $callerReference  호출자 참조
     * @return void 반환값 없음
     *
     * @throws \Throwable 요청 실패
     */
    private function invalidate(array $paths, string $callerReference): void
    {
        $paths = array_values($paths);

        $this->clients
            ->make($this->settings->accessKey(), $this->settings->secretKey())
            ->createInvalidation([
                'DistributionId' => $this->settings->distributionId(),
                'InvalidationBatch' => [
                    'CallerReference' => $callerReference,
                    'Paths' => ['Quantity' => count($paths), 'Items' => $paths],
                ],
            ]);
    }

    /**
     * 마지막 성공 시각을 남깁니다.
     *
     * @return void 반환값 없음
     */
    private function markSuccess(): void
    {
        $this->cache->put(self::LAST_SUCCESS_KEY, Carbon::now()->toIso8601String(), self::LAST_SUCCESS_TTL);
    }

    /**
     * n 번째 실패 뒤의 대기 (분)
     *
     * @param  int  $attempts  누적 실패 횟수 (1부터)
     * @return int 대기 (분)
     */
    private function delayMinutes(int $attempts): int
    {
        return self::BASE_DELAY_MINUTES * (2 ** max(0, $attempts - 1));
    }

    /**
     * 실패 사유 문구 — AWS 오류는 오류 코드와 메시지만 남긴다(요청 서명·자격은 담지 않는다).
     *
     * 이 값은 설정 화면(현황, 연결 시험 응답)에 나가므로 그 밖의 예외는 운영 모드에서 내부 원문(서버 경로, SQL)을
     * 가린다. 원문은 서버 로그에 남는다.
     *
     * @param  \Throwable  $e  예외
     * @return string 사유
     */
    private function describe(\Throwable $e): string
    {
        // 오류 코드가 없는 AWS 예외는 통신 실패다 — SDK 메시지(요청한 API 주소와 원인)를 그대로 쓴다.
        $message = $e instanceof AwsException && $e->getAwsErrorCode() !== null
            ? $e->getAwsErrorCode().': '.($e->getAwsErrorMessage() ?? '')
            : class_basename($e).': '.SafeExceptionMessage::for($e, ['plugin' => CloudFrontSettings::IDENTIFIER]);

        return Str::limit($message, self::ERROR_MAX, '');
    }
}
