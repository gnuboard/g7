<?php

namespace App\Database\Sample;

use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 본인인증 이력 샘플 시더 추상 베이스.
 *
 * 코어/모듈/플러그인이 각자 영역의 IDV 이력을 채울 수 있도록 공통 골격을 제공한다.
 * - 등록된 IdentityPolicy 중 자기 영역 정책만 추려서 사용
 * - user_id = 실제 등록된 G7 사용자
 * - provider_id = IdentityVerificationManager 에 등록된 실제 프로바이더
 * - 상태 분포 = 운영 트래픽 비율 (verified 55, expired 15, failed 12, sent 7,
 *   cancelled 5, requested 3, policy_violation_logged 3)
 * - attempts/expires_at/verified_at/consumed_at = 상태별 라이프사이클 일관성 보장
 *
 * 서브클래스는 영역 필터(applyPolicyScope) + 카운트 키/기본값 + 라벨을 정의한다.
 */
abstract class AbstractIdentityVerificationLogSampleSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 상태별 가중치 (총합 100).
     *
     * @var array<int, array{0: IdentityVerificationStatus, 1: int}>
     */
    protected array $statusBuckets;

    /**
     * 한국/해외 IP 풀.
     *
     * @var array<int, string>
     */
    protected array $ips = [
        '121.78.45.12', '211.234.111.5', '125.142.88.91', '210.94.0.74',
        '203.241.185.20', '180.182.50.7', '175.223.18.143', '218.236.42.61',
        '14.45.110.222', '112.184.99.180', '61.43.232.18', '59.16.7.205',
        '110.45.234.12', '106.247.83.190', '220.86.55.121',
        '203.0.113.42', '198.51.100.7', '172.217.27.142',
        '8.8.8.8', '1.1.1.1',
    ];

    /**
     * 데스크톱/모바일 UA 풀.
     *
     * @var array<int, string>
     */
    protected array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; SM-S921N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
    ];

    public function __construct()
    {
        $this->statusBuckets = [
            [IdentityVerificationStatus::Verified, 55],
            [IdentityVerificationStatus::Expired, 15],
            [IdentityVerificationStatus::Failed, 12],
            [IdentityVerificationStatus::Sent, 7],
            [IdentityVerificationStatus::Cancelled, 5],
            [IdentityVerificationStatus::Requested, 3],
            [IdentityVerificationStatus::PolicyViolationLogged, 3],
        ];
    }

    /**
     * IdentityPolicy 쿼리에 영역 필터를 적용한다.
     *
     * @param  Builder  $query  IdentityPolicy 쿼리
     * @return Builder 영역 필터가 적용된 쿼리
     */
    abstract protected function applyPolicyScope(Builder $query): Builder;

    /**
     * 카운트 옵션 키.
     *
     * @return string 카운트 옵션 키
     */
    abstract protected function countKey(): string;

    /**
     * 기본 생성 건수.
     *
     * @return int 기본 건수
     */
    abstract protected function defaultCount(): int;

    /**
     * 콘솔 메시지에 사용할 영역 라벨.
     *
     * @return string 영역 라벨
     */
    abstract protected function scopeLabel(): string;

    /**
     * 시더 실행.
     */
    public function run(): void
    {
        $count = $this->getSeederCount($this->countKey(), $this->defaultCount());
        $label = $this->scopeLabel();

        $users = User::query()->get(['id', 'name', 'email']);
        if ($users->isEmpty()) {
            $this->command->warn("사용자 데이터가 없어 {$label} 본인인증 이력 시더를 건너뜁니다.");

            return;
        }

        $policies = $this->applyPolicyScope(IdentityPolicy::query())
            ->get(['key', 'purpose', 'source_type', 'source_identifier', 'provider_id']);
        if ($policies->isEmpty()) {
            $this->command->warn("{$label} 영역 IdentityPolicy 가 없어 시더를 건너뜁니다.");

            return;
        }

        $manager = app(IdentityVerificationManager::class);
        $providerIds = array_keys($manager->all());
        if (empty($providerIds)) {
            $this->command->warn("등록된 본인인증 프로바이더가 없어 {$label} 시더를 건너뜁니다.");

            return;
        }

        $this->command->info("{$label} 본인인증 이력 시딩 시작... ({$count}건)");

        $ttlMinutes = (int) config('settings.identity.challenge_ttl_minutes', 15);
        $maxAttempts = (int) config('settings.identity.max_attempts', 5);
        $now = Carbon::now();
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            $user = $users->random();
            $policy = $policies->random();
            $providerId = $policy->provider_id ?: $providerIds[array_rand($providerIds)];
            $status = $this->pickStatus();
            $renderHint = mt_rand(1, 100) <= 70 ? 'text_code' : 'email_link';

            $createdAt = $this->randomCreatedAt($now);
            [$expiresAt, $verifiedAt, $consumedAt, $attempts] = $this->buildLifecycle(
                $status,
                $createdAt,
                $ttlMinutes,
                $maxAttempts,
            );

            $properties = $renderHint === 'text_code'
                ? ['code_length' => 6]
                : ['link_hint' => 'email_link'];

            $metadata = $status === IdentityVerificationStatus::PolicyViolationLogged
                ? ['violation_reason' => 'fail_mode_log_only']
                : ['hint_used' => $renderHint];

            $batch[] = [
                'id' => (string) Str::uuid(),
                'provider_id' => $providerId,
                'purpose' => $policy->purpose,
                'channel' => 'email',
                'user_id' => $user->id,
                'target_hash' => hash('sha256', mb_strtolower($user->email)),
                'status' => $status->value,
                'render_hint' => $renderHint,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'ip_address' => $this->ips[array_rand($this->ips)],
                'user_agent' => $this->userAgents[array_rand($this->userAgents)],
                // 본 시더의 모든 challenge 는 IdentityPolicy enforce 경로를 통한 것이므로
                // origin_type 은 'policy' 로 분류한다 (이전 버전에서는 source_type 을 잘못 매핑).
                'origin_type' => IdentityOriginType::Policy->value,
                'origin_identifier' => $policy->source_identifier,
                'origin_policy_key' => $policy->key,
                'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'verification_token' => $status === IdentityVerificationStatus::Verified
                    ? bin2hex(random_bytes(32))
                    : null,
                'expires_at' => $expiresAt,
                'verified_at' => $verifiedAt,
                'consumed_at' => $consumedAt,
                'created_at' => $createdAt,
                'updated_at' => $verifiedAt ?? $createdAt,
            ];
        }

        foreach (array_chunk($batch, 100) as $chunk) {
            IdentityVerificationLog::insert($chunk);
        }

        $this->command->info("{$label} 본인인증 이력 시딩 완료 ({$count}건)");
    }

    /**
     * 가중치 기반 상태 선택.
     *
     * @return IdentityVerificationStatus 선택된 상태
     */
    protected function pickStatus(): IdentityVerificationStatus
    {
        $r = mt_rand(1, 100);
        $acc = 0;
        foreach ($this->statusBuckets as [$status, $weight]) {
            $acc += $weight;
            if ($r <= $acc) {
                return $status;
            }
        }

        return IdentityVerificationStatus::Verified;
    }

    /**
     * 상태별 라이프사이클 일관성 있게 구성.
     *
     * @param  IdentityVerificationStatus  $status  Challenge 상태
     * @param  Carbon  $createdAt  생성 시각
     * @param  int  $ttlMinutes  TTL (분)
     * @param  int  $maxAttempts  최대 시도 횟수
     * @return array{0: Carbon|null, 1: Carbon|null, 2: Carbon|null, 3: int}  [expires_at, verified_at, consumed_at, attempts]
     */
    protected function buildLifecycle(
        IdentityVerificationStatus $status,
        Carbon $createdAt,
        int $ttlMinutes,
        int $maxAttempts,
    ): array {
        $expiresAt = (clone $createdAt)->addMinutes($ttlMinutes);
        $verifiedAt = null;
        $consumedAt = null;
        $attempts = 0;

        switch ($status) {
            case IdentityVerificationStatus::Verified:
                $attempts = mt_rand(1, 3);
                $verifiedAt = (clone $createdAt)->addSeconds(mt_rand(20, 600));
                if (mt_rand(0, 1)) {
                    $consumedAt = (clone $verifiedAt)->addSeconds(mt_rand(1, 30));
                }
                break;
            case IdentityVerificationStatus::Expired:
                $attempts = mt_rand(0, 2);
                break;
            case IdentityVerificationStatus::Failed:
                $attempts = $maxAttempts;
                break;
            case IdentityVerificationStatus::Cancelled:
                $attempts = mt_rand(0, 2);
                break;
            case IdentityVerificationStatus::Sent:
            case IdentityVerificationStatus::Requested:
                $attempts = 0;
                break;
            case IdentityVerificationStatus::PolicyViolationLogged:
                $expiresAt = null;
                $attempts = 0;
                break;
        }

        return [$expiresAt, $verifiedAt, $consumedAt, $attempts];
    }

    /**
     * 최근 60일 내 임의 생성 시각.
     *
     * @param  Carbon  $now  기준 시각
     * @return Carbon Challenge 생성 시각
     */
    protected function randomCreatedAt(Carbon $now): Carbon
    {
        return (clone $now)
            ->subDays(mt_rand(0, 60))
            ->subHours(mt_rand(0, 23))
            ->subMinutes(mt_rand(0, 59))
            ->subSeconds(mt_rand(0, 59));
    }
}
