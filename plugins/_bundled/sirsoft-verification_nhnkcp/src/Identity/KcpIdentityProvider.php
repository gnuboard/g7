<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Enums\KcpDuplicateField;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCertClient;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCertClientInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpDuplicateIdentityChecker;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;

/**
 * NHN KCP 휴대폰 본인확인 IDV Provider.
 *
 * 코어 IdentityVerificationManager 의 표준 12 메서드 인터페이스를 구현하여 회원가입·비밀번호
 * 재설정·정보 변경·민감 작업 등 모든 강제 지점에서 메일 인증 대신 KCP 표준창이 동작한다.
 *
 * 비로그인 사용자의 PII 는 verify 시점에 Cache 에 stash 되고, after_register 훅의 listener 가
 * 가입 후 record 로 흡수한다.
 *
 * @since 1.0.0
 */
class KcpIdentityProvider implements IdentityVerificationInterface
{
    /** Provider 식별자 (코어 표준 — 플러그인은 벤더 slug 사용) */
    public const PROVIDER_ID = 'nhnkcp';

    /**
     * 라이브 사이트코드 프리픽스 (KCP 정책값).
     *
     * 라이브 사이트코드 = 이 프리픽스 + 운영자 입력값. 운영자는 프리픽스를 제외한 값만 입력하고
     * 런타임이 부착하되, 프리픽스를 포함해 입력한 경우 중복 부착하지 않는다.
     */
    public const LIVE_SITE_CD_PREFIX = 'SM';

    /**
     * KCP 가 공개한 테스트 사이트코드 (테스트 모드 기본값).
     *
     * plugin.php 에도 같은 값이 리터럴로 있다 — 설치 시점에는 플러그인 클래스가 아직 로드되지
     * 않아 상수를 참조할 수 없기 때문이다(코어 설치 순서). 값 변경 시 두 곳을 함께 고친다.
     */
    public const TEST_SITE_CD = 'AO7F3';

    /** KCP 가 공개한 테스트 암호화 키 (테스트 모드 기본값 — plugin.php 와 동일 값) */
    public const TEST_ENC_KEY = 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e';

    /**
     * 성인인증 purpose 키 (Plugin::getIdentityPurposes 선언값과 동일 — 단일 출처).
     *
     * 이 purpose 로 발행된 challenge 는 verify 시 만 19세 이상만 통과시킨다.
     */
    public const ADULT_PURPOSE = 'nhnkcp.adult_verification';

    /** 본인확인 채널 (KCP 휴대폰 본인확인) */
    public const CHANNEL = 'phone';

    /** 성인 기준 연령 (만) */
    private const ADULT_AGE = 19;

    /** Cache key prefix — 비로그인 verify 시 PII stash. Listener 가 동일 키로 회수 (public 노출) */
    public const PENDING_RECORD_CACHE_PREFIX = 'nhnkcp:pending_record:';

    /**
     * @param  KcpCertClientInterface  $client  KCP REST 게이트웨이
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  코어 IDV 로그 Repository
     * @param  KcpCertTransactionRepositoryInterface  $transactionRepository  거래 매핑 Repository
     * @param  KcpIdentityRecordRepositoryInterface  $recordRepository  PII record Repository
     * @param  KcpDuplicateIdentityChecker  $duplicateChecker  동일인 판정 Service
     * @param  CacheInterface  $cache  비로그인 verify PII stash 용 캐시 (PluginCacheDriver 자동 prefix)
     * @param  array<string, mixed>  $config  settings 주입값 (PluginSettingsService::get 결과)
     */
    public function __construct(
        protected readonly KcpCertClientInterface $client,
        protected readonly IdentityVerificationLogRepositoryInterface $logRepository,
        protected readonly KcpCertTransactionRepositoryInterface $transactionRepository,
        protected readonly KcpIdentityRecordRepositoryInterface $recordRepository,
        protected readonly KcpDuplicateIdentityChecker $duplicateChecker,
        protected readonly CacheInterface $cache,
        protected readonly array $config = [],
    ) {}

    /**
     * 프로바이더 식별자.
     *
     * @return string provider 식별자
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * 관리자 UI 표시 라벨.
     *
     * @return string 관리자 UI 라벨
     */
    public function getLabel(): string
    {
        return __('sirsoft-verification_nhnkcp::messages.title');
    }

    /**
     * 지원 채널.
     *
     * @return array<int, string>
     */
    public function getChannels(): array
    {
        return [self::CHANNEL];
    }

    /**
     * 채널 키 → 다국어 표시 라벨 맵.
     *
     * @return array<string, string> 채널 키 → 라벨 맵
     */
    public function getChannelLabels(): array
    {
        return [
            self::CHANNEL => __('sirsoft-verification_nhnkcp::messages.channels.phone'),
        ];
    }

    /**
     * 프론트엔드 모달 UI 분기 힌트.
     *
     * 'text_code' 반환 — 코어 모달의 `identity_provider_ui:provider` Extension Point 슬롯에
     * 본 plugin 의 extension JSON (mode: append) 이 안내 창을 주입하고, 코어 기본 OTP UI 는
     * provider_id 가 코어 mail 이 아니므로 자동으로 감춰진다. 사용자가 모달 안 버튼을 클릭해야
     * 표준창이 열려 브라우저 팝업 차단을 회피한다.
     *
     * @return string 프론트 렌더 힌트
     */
    public function getRenderHint(): string
    {
        return 'text_code';
    }

    /**
     * 모든 purpose 지원 — 코어 4종 + 본 plugin 의 성인인증 + 타 확장이 등록한 커스텀 purpose.
     *
     * @param  string  $purpose  IDV purpose
     * @return bool 항상 true
     */
    public function supportsPurpose(string $purpose): bool
    {
        return true;
    }

    /**
     * 운영 가능 여부 — 모드별 사이트코드/암호화 키 존재 + 라이브 프리픽스 검증.
     *
     * @return bool 운영 가능 여부
     */
    public function isAvailable(): bool
    {
        if ($this->isTestMode()) {
            return $this->testSiteCd() !== '' && $this->testEncKey() !== '';
        }

        $liveSiteCd = $this->buildLiveSiteCd((string) ($this->config['live_site_cd'] ?? ''));

        return $liveSiteCd !== ''
            && (string) ($this->config['live_enc_key'] ?? '') !== ''
            && str_starts_with($liveSiteCd, self::LIVE_SITE_CD_PREFIX);
    }

    /**
     * Challenge 발행 — KCP 거래등록 실호출 후 표준창 호출 정보를 프론트에 전달한다.
     *
     * 거래등록이 실패하면 challenge 를 발행하지 않는다 (RemoteCallException) — 프론트가
     * 열 수 없는 표준창을 위해 로그 행만 남기는 것을 막는다.
     *
     * @param  User|array  $target  로그인 사용자 또는 가입 전 ['email' => string, ...]
     * @param  array<string, mixed>  $context  origin_* / purpose / ip / user_agent
     * @return VerificationChallenge 발행된 challenge
     *
     * @throws RemoteCallException 거래등록 통신 실패 또는 KCP 거절
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $purpose = (string) ($context['purpose'] ?? 'signup');
        $email = $target instanceof User ? (string) $target->email : (string) ($target['email'] ?? '');
        $userId = $target instanceof User ? (int) $target->id : null;
        $targetHash = hash('sha256', mb_strtolower($email));

        $challengeId = (string) Str::uuid();
        $ordrIdxx = $this->generateOrdrIdxx();
        $ttlMinutes = (int) config('settings.identity.challenge_ttl_minutes', 15);
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        // 거래등록 — challenge 행 생성 전에 호출하여 실패 시 흔적을 남기지 않는다.
        // param_opt_1 에 challenge_id 를 실어, 콜백이 거래키를 echo 하지 않는 경우에도 역조회가 가능하다.
        $registration = $this->client()->registerTrade(
            ordrIdxx: $ordrIdxx,
            retUrl: $this->callbackUrl(),
            webSiteid: (string) ($this->config['web_siteid'] ?? ''),
            paramOpts: [$challengeId],
        );

        if ($registration['res_cd'] !== KcpCertClient::RES_CD_SUCCESS
            || $registration['call_url'] === ''
            || $registration['reg_cert_key'] === '') {
            Log::error('KCP 본인확인: 거래등록 실패', [
                'res_cd' => $registration['res_cd'],
                'res_msg' => $registration['res_msg'],
                'purpose' => $purpose,
            ]);

            throw new RemoteCallException(
                '['.$registration['res_cd'].'] '.$registration['res_msg'],
            );
        }

        $this->logRepository->create([
            'id' => $challengeId,
            'provider_id' => self::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => self::CHANNEL,
            'user_id' => $userId,
            'target_hash' => $targetHash,
            'status' => 'sent',
            'render_hint' => $this->getRenderHint(),
            'attempts' => 0,
            'max_attempts' => 0,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'origin_type' => $context['origin_type'] ?? null,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $context['origin_policy_key'] ?? null,
            'metadata' => [
                'site_cd' => $this->resolveSiteCd(),
                'is_test_mode' => $this->isTestMode(),
                'ordr_idxx' => $ordrIdxx,
            ],
            'expires_at' => $expiresAt,
        ]);

        $this->transactionRepository->create($ordrIdxx, $registration['reg_cert_key'], $challengeId);

        return new VerificationChallenge(
            id: $challengeId,
            providerId: self::PROVIDER_ID,
            purpose: $purpose,
            channel: self::CHANNEL,
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: $this->getRenderHint(),
            redirectUrl: null,
            publicPayload: [
                // 표준창 form POST 에 그대로 실리는 값 — KCP 규격상 브라우저가 전송하므로 비밀이 아니다.
                'call_url' => $registration['call_url'],
                'reg_cert_key' => $registration['reg_cert_key'],
                'is_test_mode' => $this->isTestMode(),
            ],
            metadata: ['ordr_idxx' => $ordrIdxx],
        );
    }

    /**
     * Challenge 검증 — 결과조회 응답을 받아 record upsert + verification_token 발급.
     *
     * `$input` 은 KcpCallbackResolver 가 콜백 body + 결과조회 응답을 합쳐 전달한다.
     *
     * @param  string  $challengeId  IdentityVerificationLog UUID
     * @param  array<string, mixed>  $input  콜백 + 결과조회 합산 페이로드
     * @param  array<string, mixed>  $context  ip_address / user_agent
     * @return VerificationResult 검증 결과
     *
     * @throws AlreadyConsumedException 이미 처리된 challenge 재진입 시
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log) {
            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'NOT_FOUND',
                failureReason: 'sirsoft-verification_nhnkcp::exceptions.not_found',
            );
        }

        if ($log->isVerified() || $log->consumed_at !== null) {
            throw new AlreadyConsumedException($challengeId);
        }

        if ($log->expires_at !== null && Carbon::now()->greaterThan($log->expires_at)) {
            $this->logRepository->updateById((string) $log->id, ['status' => 'expired']);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'EXPIRED',
                failureReason: 'sirsoft-verification_nhnkcp::exceptions.not_found',
            );
        }

        $resCd = (string) ($input['res_cd'] ?? '');

        if ($resCd !== KcpCertClient::RES_CD_SUCCESS) {
            // 실패 사유를 기록에 남겨 운영자가 인증 이력 화면에서 원인을 구분할 수 있게 한다.
            $this->logRepository->updateById((string) $log->id, [
                'status' => $resCd === '9999' ? 'cancelled' : 'failed',
                'metadata' => array_merge((array) $log->metadata, [
                    'failure_code' => $resCd !== '' ? $resCd : 'PROVIDER_ERROR',
                ]),
            ]);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: $resCd !== '' ? $resCd : 'PROVIDER_ERROR',
                failureReason: (string) ($input['res_msg'] ?? ''),
            );
        }

        $name = trim((string) ($input['user_name'] ?? ''));
        $birthday = trim((string) ($input['birth_day'] ?? ''));
        $phone = trim((string) ($input['phone_no'] ?? ''));
        $ci = trim((string) ($input['ci'] ?? ''));
        $di = trim((string) ($input['di'] ?? ''));

        // [신원 핵심값 가드] 결과조회가 성공(res_cd=0000)했더라도 신원 핵심값이 비어 있으면
        // 정상 응답이 아니므로 인증을 인정하지 않는다. 법규상 정상 본인확인이면 이름·생년월일·
        // 휴대폰·CI·DI 는 항상 제공되며, 식별번호가 없는 자는 본인확인 자체가 성립하지 않는다
        // → 누락 = 비정상/위조 응답. 빈 식별값 record 가 생기면 중복가입 차단도 조용히 무력화된다.
        //
        // 생년월일만은 테스트 모드에서 예외로 둔다. KCP 규격상 `birth_day` 는 조건 없이 항상
        // 내려오는 표준 필드지만(공식 "본인확인 결과 복호화" 표), KCP 테스트 서버는 실제 통신사
        // 조회를 수행하지 않아 생년월일·성별·내외국인을 빈 값으로 응답한다. 운영과 동일하게
        // 거절하면 테스트 환경에서는 어떤 방법으로도 인증을 완주할 수 없어 검수가 불가능해진다.
        // 운영 모드의 판정은 그대로 엄격하게 유지한다.
        $birthdayRequired = ! $this->isTestMode();

        if ($name === '' || ($birthdayRequired && $birthday === '') || $phone === '' || $ci === '' || $di === '') {
            // 어느 항목이 비었는지 값 없이 항목명만 남긴다 — 운영자가 인증기관 계약(CI/DI 제공 여부 등)
            // 문제인지 응답 이상인지 구분할 수 있어야 하며, PII 는 로그/기록에 남기지 않는다.
            $missing = array_keys(array_filter([
                'user_name' => $name === '',
                'birth_day' => $birthdayRequired && $birthday === '',
                'phone_no' => $phone === '',
                'ci' => $ci === '',
                'di' => $di === '',
            ]));

            Log::warning('KCP 본인확인: 결과조회 응답에 신원 핵심값 누락', [
                'challenge_id' => $challengeId,
                'missing_fields' => $missing,
                'returned_fields' => array_keys(array_filter(
                    $input,
                    static fn ($value, $key) => $key !== 'res_msg' && $value !== '' && $value !== null,
                    ARRAY_FILTER_USE_BOTH,
                )),
            ]);

            $this->logRepository->updateById((string) $log->id, [
                'status' => 'failed',
                'metadata' => array_merge((array) $log->metadata, [
                    'failure_code' => 'INCOMPLETE_IDENTITY',
                    'missing_fields' => $missing,
                ]),
            ]);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'INCOMPLETE_IDENTITY',
                failureReason: 'sirsoft-verification_nhnkcp::exceptions.incomplete_identity',
            );
        }

        // 테스트 모드에서 생년월일이 비어 통과한 경우 — 성인 여부를 계산할 수 없으므로 성인인증
        // 목적은 아래 가드에서 계속 실패한다. 운영자가 "왜 성인 확인이 안 되는가" 를 추적할 수
        // 있도록 사실만 남긴다 (값 없이).
        if ($birthday === '') {
            Log::warning('KCP 본인확인: 테스트 응답에 생년월일 없음 — 성인 여부 확인 불가', [
                'challenge_id' => $challengeId,
                'purpose' => $log->purpose,
            ]);
        }

        $duplicateField = $this->resolveDuplicateField();
        $diHash = KcpIdentityHasher::hash($di);
        $ciHash = KcpIdentityHasher::hash($ci);
        $identityHash = $duplicateField === KcpDuplicateField::Di ? $diHash : $ciHash;
        $isAdult = $this->isAdult($birthday);

        // [본인 계정 일치 가드] 로그인 사용자가 자기 본인확인을 수행하는 흐름에서, 이미 다른
        // 명의로 확정된 계정이면 verify 를 실패 처리한다. record 가 없으면(도입 이전 가입자 등)
        // 통과시킨다 — permissive.
        if ($log->user_id !== null) {
            $existingRecord = $this->recordRepository->findByUserId((int) $log->user_id);
            if ($existingRecord !== null) {
                $storedHash = $duplicateField === KcpDuplicateField::Di
                    ? $existingRecord->di_hash
                    : $existingRecord->ci_hash;

                if ($storedHash !== null && $storedHash !== $identityHash) {
                    $this->markFailed($log, 'IDENTITY_BINDING_MISMATCH');

                    return VerificationResult::failure(
                        challengeId: $challengeId,
                        providerId: self::PROVIDER_ID,
                        failureCode: 'IDENTITY_BINDING_MISMATCH',
                        failureReason: 'sirsoft-verification_nhnkcp::exceptions.binding_mismatch',
                    );
                }
            }
        }

        // [성인인증 가드] 성인인증 purpose 의 challenge 는 만 19세 이상만 통과시킨다.
        // 미성년자(생년월일 형식 오류 포함)는 본인확인 자체가 성공했더라도 이 목적에서는
        // 충족으로 인정하지 않는다 → status=failed 로 두어 토큰 미발급.
        if ($log->purpose === self::ADULT_PURPOSE && ! $isAdult) {
            $this->markFailed($log, 'NOT_ADULT');

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'NOT_ADULT',
                failureReason: 'sirsoft-verification_nhnkcp::exceptions.not_adult',
            );
        }

        // [중복 가입 조기 차단] 가입 목적의 비로그인 challenge 는 이 시점에 동일인 여부를 판정한다.
        // 실제 가입 차단은 AssertNoDuplicateKcpIdentity listener 가 담당하며(권위 게이트),
        // 판정 로직은 KcpDuplicateIdentityChecker 한 곳을 공유해 두 지점이 어긋나지 않는다.
        if ($this->shouldBlockDuplicate($log)) {
            $conflictColumn = $this->duplicateChecker->findConflictColumn(
                $duplicateField,
                $identityHash,
                $log->user_id !== null ? (int) $log->user_id : null,
            );

            if ($conflictColumn !== null) {
                $this->markFailed($log, 'DUPLICATE', ['matched_field' => $conflictColumn]);

                return VerificationResult::failure(
                    challengeId: $challengeId,
                    providerId: self::PROVIDER_ID,
                    failureCode: 'DUPLICATE',
                    failureReason: 'sirsoft-verification_nhnkcp::exceptions.duplicate_register',
                );
            }
        }

        $verificationToken = bin2hex(random_bytes(32));
        $verifiedAt = Carbon::now();
        $piiPayload = $this->buildPiiPayload($input, $diHash, $ciHash, $isAdult);

        // [저장 원자성] "인증 성공(토큰 발급)" 도장과 PII 저장은 한 단위로 묶는다.
        // 토큰을 먼저 발급한 뒤 PII 저장이 실패하면 "인증은 성공인데 신원 record 가 없는" 모순이
        // 남는다 (로그인=record 없는 verified, 비로그인=Cache 부재로 가입 backfill 누락).
        try {
            DB::transaction(function () use (
                $log,
                $verificationToken,
                $verifiedAt,
                $diHash,
                $ciHash,
                $duplicateField,
                $isAdult,
                $piiPayload,
                $challengeId,
            ): void {
                $this->logRepository->updateById((string) $log->id, [
                    'status' => 'verified',
                    'verification_token' => $verificationToken,
                    'verified_at' => $verifiedAt,
                    'metadata' => array_merge((array) $log->metadata, [
                        'di_hash' => $diHash,
                        'ci_hash' => $ciHash,
                        'duplicate_field_used' => $duplicateField->value,
                        'is_adult' => $isAdult,
                    ]),
                ]);

                if ($log->user_id !== null) {
                    // 로그인 사용자: record upsert 는 같은 트랜잭션 — 실패 시 log 갱신과 함께 롤백.
                    $this->recordRepository->upsertForUser((int) $log->user_id, array_merge($piiPayload, [
                        'latest_log_id' => $challengeId,
                        'verified_at' => $verifiedAt,
                        're_verified_at' => $verifiedAt,
                    ]));

                    return;
                }

                // 비로그인 사용자: Cache stash 는 DB 트랜잭션 밖이라 롤백 대상이 아니지만,
                // put() 이 던지면 트랜잭션 콜백이 예외로 종료되어 log 갱신이 롤백된다.
                $ttl = $log->expires_at
                    ? max(1, (int) Carbon::now()->diffInSeconds($log->expires_at, false))
                    : 15 * 60;
                $this->cache->put(self::PENDING_RECORD_CACHE_PREFIX.$challengeId, $piiPayload, $ttl);
            });
        } catch (\Throwable $e) {
            // 식별값(challenge_id)만 로깅 — PII 비노출.
            Log::error('KCP 본인확인: 인증 성공 후 PII 저장 실패 — 토큰 발급 롤백', [
                'challenge_id' => $challengeId,
                'is_login' => $log->user_id !== null,
                'error' => $e->getMessage(),
            ]);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'STORAGE_FAILED',
                failureReason: 'sirsoft-verification_nhnkcp::exceptions.storage_failed',
            );
        }

        return VerificationResult::success(
            challengeId: $challengeId,
            providerId: self::PROVIDER_ID,
            verifiedAt: $verifiedAt,
            identityHash: $identityHash,
            claims: ['verification_token' => $verificationToken],
        );
    }

    /**
     * Challenge 취소.
     *
     * @param  string  $challengeId  IdentityVerificationLog UUID
     * @return bool 취소 성공 여부
     */
    public function cancel(string $challengeId): bool
    {
        $log = $this->logRepository->findById($challengeId);

        if (! $log || $log->isVerified() || $log->consumed_at !== null) {
            return false;
        }

        $this->logRepository->updateById((string) $log->id, ['status' => 'cancelled']);

        $transaction = $this->transactionRepository->findByChallengeId($challengeId);
        if ($transaction !== null) {
            $this->transactionRepository->markConsumed((int) $transaction->id);
        }

        return true;
    }

    /**
     * 관리자 [환경설정 > 본인인증 > 프로바이더 상세] 가 자동 렌더할 settings 스키마.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            'is_test_mode' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.test_mode'),
                'type' => 'boolean',
                'default' => true,
            ],
            'test_site_cd' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.test_site_cd'),
                'type' => 'text',
                'default' => self::TEST_SITE_CD,
            ],
            'test_enc_key' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.test_enc_key'),
                'type' => 'password',
                'default' => self::TEST_ENC_KEY,
            ],
            'live_site_cd' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.live_site_cd'),
                'type' => 'text',
                'default' => '',
            ],
            'live_enc_key' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.live_enc_key'),
                'type' => 'password',
                'default' => '',
            ],
            'web_siteid' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.web_siteid'),
                'type' => 'text',
                'default' => '',
            ],
            'duplicate_field' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.duplicate_field'),
                'type' => 'radio',
                'options' => ['di', 'ci'],
                'default' => 'di',
            ],
            'duplicate_block_enabled' => [
                'label' => __('sirsoft-verification_nhnkcp::messages.settings.duplicate_block_enabled.label'),
                'description' => __('sirsoft-verification_nhnkcp::messages.settings.duplicate_block_enabled.description'),
                'type' => 'toggle',
                'default' => true,
            ],
        ];
    }

    /**
     * 설정값을 주입한 새 인스턴스 반환 (불변 복제 패턴).
     *
     * @param  array<string, mixed>  $config  주입할 설정값
     * @return static 설정이 주입된 새 인스턴스
     */
    public function withConfig(array $config): static
    {
        return new static(
            $this->client,
            $this->logRepository,
            $this->transactionRepository,
            $this->recordRepository,
            $this->duplicateChecker,
            $this->cache,
            array_merge($this->config, $config),
        );
    }

    /**
     * 현재 설정으로 자격증명이 주입된 KCP 게이트웨이를 반환한다.
     *
     * @return KcpCertClientInterface 자격증명이 주입된 게이트웨이
     */
    public function client(): KcpCertClientInterface
    {
        return $this->client->withCredentials(
            $this->resolveSiteCd(),
            $this->resolveEncKey(),
            $this->isTestMode(),
        );
    }

    /**
     * 현재 모드의 사이트코드를 반환한다.
     *
     * @return string 현재 모드의 사이트코드
     */
    public function resolveSiteCd(): string
    {
        return $this->isTestMode()
            ? $this->testSiteCd()
            : $this->buildLiveSiteCd((string) ($this->config['live_site_cd'] ?? ''));
    }

    /**
     * 현재 모드의 암호화 키를 반환한다.
     *
     * @return string 현재 모드의 암호화 키
     */
    public function resolveEncKey(): string
    {
        return $this->isTestMode()
            ? $this->testEncKey()
            : (string) ($this->config['live_enc_key'] ?? '');
    }

    /**
     * 라이브 사이트코드에 프리픽스(self::LIVE_SITE_CD_PREFIX)를 보장한다.
     *
     * 운영자가 설정 UI 에서 프리픽스 분리형 input 으로 입력하므로 일반적으로 프리픽스 미포함
     * 값이 들어오지만, 직접 프리픽스를 포함해 입력한 경우에도 중복 부착하지 않는다.
     *
     * 프리픽스 판정은 대소문자를 구분하지 않고, 결과는 KCP 정책값(대문자)으로 정규화한다.
     * 사이트코드는 대문자로 발급되지만 프리픽스를 손으로 옮겨 적는 과정에서 소문자가 섞일 수
     * 있는데, 대소문자를 구분해 판정하면 `SMsmA1B2C` 가 만들어져 모든 인증 요청이 잘못된
     * 사이트코드로 나간다.
     *
     * @param  string  $value  운영자 입력 값
     * @return string 프리픽스가 보장된 라이브 사이트코드
     */
    public function buildLiveSiteCd(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $prefixLength = strlen(self::LIVE_SITE_CD_PREFIX);

        if (strncasecmp($value, self::LIVE_SITE_CD_PREFIX, $prefixLength) === 0) {
            return self::LIVE_SITE_CD_PREFIX.substr($value, $prefixLength);
        }

        return self::LIVE_SITE_CD_PREFIX.$value;
    }

    /**
     * 생년월일 (YYYYMMDD) 기준 만 19세 이상 여부를 반환한다 (생일 당일 포함).
     *
     * @param  string  $birthday  YYYYMMDD
     * @return bool 만 19세 이상 여부
     */
    public function isAdult(string $birthday): bool
    {
        if (! preg_match('/^\d{8}$/', $birthday)) {
            return false;
        }

        try {
            $birth = Carbon::createFromFormat('Ymd', $birthday)->startOfDay();

            return $birth->age >= self::ADULT_AGE;
        } catch (\Throwable $e) {
            Log::warning('KCP 본인확인: 생년월일 파싱 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 테스트 모드 여부.
     *
     * @return bool 테스트 모드면 true (설정이 없으면 안전하게 테스트로 간주)
     */
    protected function isTestMode(): bool
    {
        return (bool) ($this->config['is_test_mode'] ?? true);
    }

    /**
     * 테스트 사이트코드 (미설정 시 KCP 공개 테스트 값).
     *
     * @return string 테스트 환경 사이트코드
     */
    protected function testSiteCd(): string
    {
        return (string) ($this->config['test_site_cd'] ?? '');
    }

    /**
     * 테스트 암호화 키 (미설정 시 KCP 공개 테스트 값).
     *
     * @return string 테스트 환경 암호화 키
     */
    protected function testEncKey(): string
    {
        return (string) ($this->config['test_enc_key'] ?? '');
    }

    /**
     * 운영자가 선택한 동일인 판정 기준.
     *
     * @return KcpDuplicateField 중복 가입 차단에 사용할 식별값 (DI 또는 CI)
     */
    protected function resolveDuplicateField(): KcpDuplicateField
    {
        return KcpDuplicateField::tryFrom((string) ($this->config['duplicate_field'] ?? 'di'))
            ?? KcpDuplicateField::Di;
    }

    /**
     * 인증을 실패로 마감하면서 그 사유를 기록에 남긴다.
     *
     * 상태만 실패로 바꾸면 관리자 인증 이력 화면에서 "왜 실패했는지" 를 알 수 없다. 사유 코드와
     * 부가 정보(어느 식별 기준으로 막혔는지 등)를 함께 남겨 운영자가 문의에 답할 수 있게 한다.
     * 개인정보는 남기지 않는다 — 코드와 항목명만 기록한다.
     *
     * @param  IdentityVerificationLog  $log  대상 로그
     * @param  string  $failureCode  실패 사유 코드
     * @param  array<string, mixed>  $extra  사유별 부가 정보 (PII 제외)
     */
    protected function markFailed(IdentityVerificationLog $log, string $failureCode, array $extra = []): void
    {
        $this->logRepository->updateById((string) $log->id, [
            'status' => 'failed',
            'metadata' => array_merge((array) $log->metadata, ['failure_code' => $failureCode], $extra),
        ]);
    }

    /**
     * 이 challenge 가 중복 가입 차단 대상인지 판정한다.
     *
     * 가입(signup) 목적 + 비로그인 challenge 에만 적용한다 — 로그인 사용자의 재인증이나
     * 비밀번호 재설정은 동일인이 존재하는 것이 정상이다.
     *
     * @param  IdentityVerificationLog  $log  대상 로그
     * @return bool 차단 판정 수행 여부
     */
    protected function shouldBlockDuplicate(IdentityVerificationLog $log): bool
    {
        return (bool) ($this->config['duplicate_block_enabled'] ?? true)
            && $log->purpose === 'signup'
            && $log->user_id === null;
    }

    /**
     * KCP 콜백을 수신할 절대 URL 을 반환한다.
     *
     * @return string 거래등록 시 Ret_URL 로 전달할 콜백 절대 URL
     */
    protected function callbackUrl(): string
    {
        return url('/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback');
    }

    /**
     * 가맹점 주문번호(ordr_idxx)를 생성한다 — 유니크, 50자 이내.
     *
     * @return string 생성된 가맹점 주문번호
     */
    protected function generateOrdrIdxx(): string
    {
        return 'G7'.Carbon::now()->format('YmdHis').strtoupper(Str::random(12));
    }

    /**
     * 결과조회 응답에서 record UPSERT 용 PII 페이로드를 구성한다.
     * 평문 PII 는 Crypt 로 암호화하여 저장한다.
     *
     * @param  array<string, mixed>  $input  콜백 + 결과조회 합산 페이로드
     * @param  string  $diHash  DI keyed-hash
     * @param  string  $ciHash  CI keyed-hash
     * @param  bool  $isAdult  성인 여부
     * @return array<string, mixed> Repository::upsertForUser 에 전달할 컬럼 값
     */
    protected function buildPiiPayload(array $input, string $diHash, string $ciHash, bool $isAdult): array
    {
        $name = trim((string) ($input['user_name'] ?? ''));
        $phone = trim((string) ($input['phone_no'] ?? ''));
        $birthday = trim((string) ($input['birth_day'] ?? ''));
        $di = trim((string) ($input['di'] ?? ''));
        $ci = trim((string) ($input['ci'] ?? ''));
        $commId = trim((string) ($input['comm_id'] ?? ''));
        $sexCode = trim((string) ($input['sex_code'] ?? ''));
        $localCode = trim((string) ($input['local_code'] ?? ''));

        return [
            'comm_id' => $commId !== '' ? $commId : null,
            // 누락 필드는 "암호화된 빈 문자열" 찌꺼기 대신 null 로 저장한다. 정상 경로에서는
            // verify() 의 신원 핵심값 가드가 누락을 이미 차단하므로 아래 null 분기는 방어적 처리다.
            'name_encrypted' => $name !== '' ? Crypt::encryptString($name) : null,
            'phone_encrypted' => $phone !== '' ? Crypt::encryptString($phone) : null,
            'birthday_encrypted' => $birthday !== '' ? Crypt::encryptString($birthday) : null,
            'di_encrypted' => $di !== '' ? Crypt::encryptString($di) : null,
            'di_hash' => $di !== '' ? $diHash : null,
            'ci_encrypted' => $ci !== '' ? Crypt::encryptString($ci) : null,
            'ci_hash' => $ci !== '' ? $ciHash : null,
            'gender' => $sexCode === '01' ? 'M' : ($sexCode === '02' ? 'F' : null),
            'is_foreigner' => $localCode === '02',
            'is_adult' => $isAdult,
        ];
    }
}
