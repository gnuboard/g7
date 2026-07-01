<?php

namespace Plugins\Sirsoft\VerificationKginicis\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Enums\InicisDuplicateField;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;

/**
 * KG이니시스 본인확인 (reqSvcCd=03) IDV Provider.
 *
 * 코어 IdentityVerificationManager 의 표준 12 메서드 인터페이스를 구현하여
 * 회원가입·비번찾기·민감작업 등 모든 강제 지점에서 메일 인증 대신 이니시스 팝업이 동작한다.
 *
 * 비로그인 사용자의 PII 는 verify 시점에 Cache 에 stash 되고, after_register 훅의 listener 가
 * 가입 후 record 로 흡수한다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityProvider implements IdentityVerificationInterface
{
    /** Provider 식별자 (코어 표준) */
    public const PROVIDER_ID = 'inicis';

    /**
     * 성인인증 purpose 키 (Plugin::getIdentityPurposes 선언값과 동일 — 단일 출처).
     *
     * 이 purpose 로 발행된 challenge 는 verify 시 만 19세 이상만 통과시킨다.
     */
    public const ADULT_PURPOSE = 'inicis.adult_verification';

    /** 코드 내부 고정값 — 본인확인 서비스 */
    private const REQ_SVC_CD = '03';

    /** 코드 내부 고정값 — 사용자 직접 입력 모드 */
    private const FLG_FIXED_USER = 'N';

    /** 코드 내부 고정값 — token 회수 강제 (SEED 키 발급) */
    private const IS_USE_TOKEN = 'Y';

    /** Cache key prefix — 비로그인 verify 시 PII stash. Listener 가 동일 키로 회수 (public 노출) */
    public const PENDING_RECORD_CACHE_PREFIX = 'inicis:pending_record:';

    /**
     * @param  InicisGatewayInterface  $gateway  외부 통신 + SEED 복호화 게이트웨이
     * @param  InicisChallengeMappingRepositoryInterface  $mappingRepository  mTxId 매핑 Repository
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  PII record Repository
     * @param  CacheInterface  $cache  비로그인 verify PII stash 용 캐시 (PluginCacheDriver 자동 prefix)
     * @param  array<string, mixed>  $config  settings 주입값 (PluginSettingsService::get 결과)
     */
    public function __construct(
        protected readonly InicisGatewayInterface $gateway,
        protected readonly InicisChallengeMappingRepositoryInterface $mappingRepository,
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly CacheInterface $cache,
        protected readonly array $config = [],
    ) {}

    /**
     * 프로바이더 식별자.
     *
     * @return string
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * 관리자 UI 표시 라벨.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('sirsoft-verification_kginicis::messages.title');
    }

    /**
     * 지원 채널.
     *
     * @return array<int, string>
     */
    public function getChannels(): array
    {
        return ['ipin'];
    }

    /**
     * 채널 키 → 다국어 표시 라벨 맵.
     *
     * @return array<string, string> 채널 키 → 라벨 맵
     */
    public function getChannelLabels(): array
    {
        return [
            'ipin' => __('sirsoft-verification_kginicis::messages.channels.ipin'),
        ];
    }

    /**
     * 프론트가 challenge 를 렌더하는 방법 힌트.
     */
    /**
     * 프론트엔드 모달 UI 분기 힌트.
     *
     * 'text_code' 반환 — 코어 IDV 모달의 `identity_provider_ui:text_code` Extension Point
     * 슬롯에 본 plugin 의 extension JSON (`mode: 'replace'`) 으로 default 6자리 OTP UI 를
     * 이니시스 인증 시작 버튼으로 교체. 사용자가 모달 안 버튼을 클릭해야 popup 이 열려
     * Chrome popup blocker (자동 호출 시 차단) 를 회피한다.
     *
     * @return string
     */
    public function getRenderHint(): string
    {
        return 'text_code';
    }

    /**
     * 모든 purpose 지원 — 코어 4종 (signup/password_reset/self_update/sensitive_action) +
     * 본 plugin 이 등록한 inicis.adult_verification + 다른 모듈/플러그인이 등록한 커스텀 purpose.
     *
     * @param  string  $purpose  IDV purpose
     * @return bool 항상 true
     */
    public function supportsPurpose(string $purpose): bool
    {
        return true;
    }

    /**
     * 운영 가능 여부 — 모드별 MID/API key + 라이브 모드의 SRA 프리픽스 검증.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if ((bool) ($this->config['is_test_mode'] ?? true)) {
            return ! empty($this->config['test_mid']);
        }

        $liveMid = $this->buildLiveMid((string) ($this->config['live_mid'] ?? ''));

        return $liveMid !== ''
            && ! empty($this->config['live_api_key'])
            && str_starts_with($liveMid, 'SRA');
    }

    /**
     * Challenge 발행.
     *
     * 코어 service 가 IdentityVerificationLog 행을 사전 생성한 뒤 본 메서드를 호출하지 않고,
     * 본 메서드가 직접 log 행 생성 + inicis_challenge_mappings 행 INSERT 까지 처리한다.
     *
     * @param  User|array  $target  로그인 사용자 또는 가입 전 ['email' => string, ...]
     * @param  array<string, mixed>  $context  origin_* / purpose / ip / user_agent
     * @return VerificationChallenge
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
    {
        $purpose = (string) ($context['purpose'] ?? 'signup');
        $email = $target instanceof User ? (string) $target->email : (string) ($target['email'] ?? '');
        $userId = $target instanceof User ? (int) $target->id : null;
        $targetHash = hash('sha256', strtolower($email));

        $mtxid = $this->gateway->generateMTxId();
        $challengeId = (string) Str::uuid();
        $ttlMinutes = (int) config('settings.identity.challenge_ttl_minutes', 15);
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        IdentityVerificationLog::create([
            'id' => $challengeId,
            'provider_id' => self::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => 'ipin',
            'user_id' => $userId,
            'target_hash' => $targetHash,
            'status' => 'sent',
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'origin_type' => $context['origin_type'] ?? null,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $context['origin_policy_key'] ?? null,
            'metadata' => [
                'mid' => $this->resolveMid(),
                'reqSvcCd' => self::REQ_SVC_CD,
                'is_use_token' => self::IS_USE_TOKEN,
                'flgFixedUser' => self::FLG_FIXED_USER,
                'mtxid_hash' => hash('sha256', $mtxid),
            ],
            'expires_at' => $expiresAt,
        ]);

        $this->mappingRepository->create($mtxid, $challengeId);

        $mid = $this->resolveMid();

        return new VerificationChallenge(
            id: $challengeId,
            providerId: self::PROVIDER_ID,
            purpose: $purpose,
            channel: 'ipin',
            targetHash: $targetHash,
            expiresAt: $expiresAt,
            renderHint: 'text_code',
            redirectUrl: null,
            publicPayload: [
                'mid' => $mid,
                'mtxid' => $mtxid,
                'reqSvcCd' => self::REQ_SVC_CD,
                'flgFixedUser' => self::FLG_FIXED_USER,
                'reservedMsg' => 'isUseToken='.self::IS_USE_TOKEN,
                // 이니시스 등록가맹점 검증 hash — apiKey 는 frontend 에 노출 금지이므로
                // backend 가 매 challenge 마다 계산해서 publicPayload 로 전달.
                'authHash' => hash('sha256', $mid.$mtxid.$this->resolveApiKey()),
            ],
            metadata: ['mtxid' => $mtxid],
        );
    }

    /**
     * Challenge 검증 — STEP3 응답을 받아 record upsert + verification_token 발급.
     *
     * `$input` 은 코어 callback 라우트 또는 자체 callback 컨트롤러가 STEP2 callback body
     * + STEP3 응답 (gateway::verifyResult 결과) 를 모두 합쳐 전달.
     *
     * @param  string  $challengeId  IdentityVerificationLog UUID
     * @param  array<string, mixed>  $input  STEP2 + STEP3 합산 페이로드
     * @param  array<string, mixed>  $context  ip_address / user_agent
     * @return VerificationResult
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult
    {
        $log = IdentityVerificationLog::query()->whereKey($challengeId)->first();

        if (! $log) {
            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'NOT_FOUND',
                failureReason: 'sirsoft-verification_kginicis::exceptions.not_found',
            );
        }

        if ($log->isVerified() || $log->consumed_at !== null) {
            throw new AlreadyConsumedException($challengeId, (string) ($input['mTxId'] ?? ''));
        }

        if ($log->expires_at !== null && Carbon::now()->greaterThan($log->expires_at)) {
            $log->update(['status' => 'expired']);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'EXPIRED',
                failureReason: 'sirsoft-verification_kginicis::exceptions.not_found',
            );
        }

        $resultCode = (string) ($input['resultCode'] ?? '');

        if ($resultCode !== '0000') {
            $log->update(['status' => 'failed']);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: $resultCode ?: 'PROVIDER_ERROR',
                failureReason: (string) ($input['resultMsg'] ?? ''),
            );
        }

        $duplicateField = InicisDuplicateField::tryFrom($this->config['duplicate_field'] ?? 'di') ?? InicisDuplicateField::Di;
        $identifierValue = $duplicateField === InicisDuplicateField::Di
            ? (string) ($input['userDi'] ?? '')
            : (string) ($input['userCi'] ?? '');
        // 동일인 검색용 해시는 저장/비교 지점과 동일하게 prefix 없는 APP_KEY HMAC 으로 통일한다.
        $identityHash = $identifierValue !== ''
            ? InicisIdentityHasher::hash($identifierValue)
            : null;

        // [신원 핵심값 가드] 본인확인이 성공(resultCode=0000)했더라도 신원 핵심값이 비어 있으면
        // 정상 응답이 아니므로 인증을 인정하지 않는다.
        // - 이름·생년월일·휴대폰: 아이핀(휴대폰 본인확인) 채널이 항상 제공하는 기본 신원 정보
        //   (Chrome MCP 실측 2026-06-23: 이름·휴대폰·생년월일·CI·DI 모두 응답에 채워짐 확인).
        // - 설정 기준 식별값(duplicate_field=di→userDi, ci→userCi): 중복가입 차단의 유일한 판정 키.
        //   비면 빈 식별값 record 가 생겨 중복차단이 조용히 무력화되므로 반드시 존재해야 한다.
        // 법규상 정상 본인확인이면 이 값들은 항상 제공되며(주민/외국인등록번호 기반 CI/DI),
        // 식별번호조차 없는 자는 본인확인 자체가 성립하지 않는다 → 누락 = 비정상/위조 응답.
        $name = (string) ($input['userName'] ?? '');
        $birthday = (string) ($input['userBirthday'] ?? '');
        $phone = (string) ($input['userPhone'] ?? '');
        if ($name === '' || $birthday === '' || $phone === '' || $identifierValue === '') {
            $log->update(['status' => 'failed']);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'INCOMPLETE_IDENTITY',
                failureReason: 'sirsoft-verification_kginicis::exceptions.incomplete_identity',
            );
        }

        $userDiHashCandidate = isset($input['userDi']) && $input['userDi'] !== ''
            ? InicisIdentityHasher::hash((string) $input['userDi'])
            : null;
        $userCiHashCandidate = isset($input['userCi']) && $input['userCi'] !== ''
            ? InicisIdentityHasher::hash((string) $input['userCi'])
            : null;

        // [D′-1] Binding 검증 — self IDV 흐름 (로그인 사용자가 자기 본인인증) 에서
        // verify 한 사람이 대상 user 와 동일인이 아니면 verify 실패 처리.
        // - log.user_id 가 null 이면 게스트 가입 흐름 — 가입 차단 listener 가 처리하므로 skip
        // - record 가 없으면 IDV 미가입자 (본 plugin 도입 이전 가입자 / 외국인) — permissive 통과
        // - hash 후보가 null 이면 외국인 등 — permissive 통과
        if ($log->user_id !== null) {
            $existingRecord = $this->recordRepository->findByUserId((int) $log->user_id);
            if ($existingRecord !== null) {
                $mismatch = $duplicateField === InicisDuplicateField::Di
                    ? ($existingRecord->di_hash !== null
                        && $userDiHashCandidate !== null
                        && $existingRecord->di_hash !== $userDiHashCandidate)
                    : ($existingRecord->ci_hash !== null
                        && $userCiHashCandidate !== null
                        && $existingRecord->ci_hash !== $userCiHashCandidate
                        && $existingRecord->ci2_hash !== $userCiHashCandidate);

                if ($mismatch) {
                    $log->update(['status' => 'failed']);

                    return VerificationResult::failure(
                        challengeId: $challengeId,
                        providerId: self::PROVIDER_ID,
                        failureCode: 'IDENTITY_BINDING_MISMATCH',
                        failureReason: 'sirsoft-verification_kginicis::exceptions.binding_mismatch',
                    );
                }
            }
        }

        $isAdult = $this->isAdult((string) ($input['userBirthday'] ?? ''));

        // [성인인증 가드] 성인인증(adult_verification) purpose 의 challenge 는 만 19세 이상만
        // 통과시킨다. 미성년자(생년월일 누락·형식오류 포함)는 본인 확인 자체는 성공했더라도
        // 이 목적에서는 충족으로 인정하지 않는다 → status=failed 로 두어 토큰 미발급.
        // 정책 게이트(IdentityPolicyService::enforce)는 verified 로그가 없으면 통과시키지 않으므로
        // 코어 추가 검사 없이 미성년자의 19금 작업이 자동 차단된다.
        if ($log->purpose === self::ADULT_PURPOSE && ! $isAdult) {
            $log->update(['status' => 'failed']);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'NOT_ADULT',
                failureReason: 'sirsoft-verification_kginicis::exceptions.not_adult',
            );
        }

        $verificationToken = bin2hex(random_bytes(32));
        $verifiedAt = Carbon::now();
        $piiPayload = $this->buildPiiPayload($input, $duplicateField);

        // [저장 원자성] "인증 성공(토큰 발급)" 도장과 PII 저장은 한 단위로 묶는다.
        // 토큰을 먼저 발급한 뒤 PII 저장이 실패하면 "인증은 성공인데 신원 record 가 없는" 모순이
        // 남는다 (로그인=record 없는 verified, 비로그인=Cache 부재로 가입 backfill 누락).
        // log 갱신을 DB 트랜잭션으로 감싸고, 로그인 record upsert / 비로그인 Cache stash 가 던지면
        // 토큰 발급까지 롤백하여 인증 자체를 STORAGE_FAILED 로 실패시킨다 → "인증 성공 = PII 저장됨" 보장.
        try {
            DB::transaction(function () use (
                $log,
                $verificationToken,
                $verifiedAt,
                $input,
                $userDiHashCandidate,
                $userCiHashCandidate,
                $duplicateField,
                $isAdult,
                $piiPayload,
                $challengeId,
            ): void {
                $log->update([
                    'status' => 'verified',
                    'verification_token' => $verificationToken,
                    'verified_at' => $verifiedAt,
                    'metadata' => array_merge((array) $log->metadata, [
                        'sdk_tx_id' => $input['txId'] ?? null,
                        'svcCd' => $input['svcCd'] ?? null,
                        'providerDevCd' => $input['providerDevCd'] ?? null,
                        'di_hash' => $userDiHashCandidate,
                        'ci_hash' => $userCiHashCandidate,
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
                $this->cache->put(
                    self::PENDING_RECORD_CACHE_PREFIX.$challengeId,
                    $piiPayload,
                    $ttl,
                );
            });
        } catch (\Throwable $e) {
            // 식별값(challenge_id)만 로깅 — PII 비노출.
            Log::error('이니시스 본인확인: 인증 성공 후 PII 저장 실패 — 토큰 발급 롤백', [
                'challenge_id' => $challengeId,
                'is_login' => $log->user_id !== null,
                'error' => $e->getMessage(),
            ]);

            return VerificationResult::failure(
                challengeId: $challengeId,
                providerId: self::PROVIDER_ID,
                failureCode: 'STORAGE_FAILED',
                failureReason: 'sirsoft-verification_kginicis::exceptions.storage_failed',
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
        $log = IdentityVerificationLog::query()->whereKey($challengeId)->first();

        if (! $log || $log->isVerified() || $log->consumed_at !== null) {
            return false;
        }

        $log->update(['status' => 'cancelled']);

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
                'label' => __('sirsoft-verification_kginicis::messages.settings.test_mode'),
                'type' => 'boolean',
                'default' => true,
            ],
            'test_mid' => [
                'label' => 'Test MID',
                'type' => 'text',
                'default' => 'INIiasTest',
            ],
            'test_api_key' => [
                'label' => 'Test API Key',
                'type' => 'password',
                'default' => 'TGdxb2l3enJDWFRTbTgvREU3MGYwUT09',
            ],
            'live_mid' => [
                'label' => 'Live MID (SRA prefix)',
                'type' => 'text',
                'default' => '',
            ],
            'live_api_key' => [
                'label' => 'Live API Key',
                'type' => 'password',
                'default' => '',
            ],
            'duplicate_field' => [
                'label' => 'Duplicate Field',
                'type' => 'radio',
                'options' => ['di', 'ci'],
                'default' => 'di',
            ],
            'duplicate_block_enabled' => [
                'label' => __('sirsoft-verification_kginicis::messages.settings.duplicate_block_enabled.label'),
                'description' => __('sirsoft-verification_kginicis::messages.settings.duplicate_block_enabled.description'),
                'type' => 'toggle',
                'default' => true,
            ],
        ];
    }

    /**
     * 설정값을 주입한 새 인스턴스 반환 (불변 복제 패턴).
     *
     * @param  array<string, mixed>  $config
     * @return static
     */
    public function withConfig(array $config): static
    {
        return new static(
            $this->gateway,
            $this->mappingRepository,
            $this->recordRepository,
            $this->cache,
            array_merge($this->config, $config),
        );
    }

    /**
     * 현재 모드의 가맹점 MID 를 반환한다.
     *
     * @return string
     */
    protected function resolveMid(): string
    {
        if ((bool) ($this->config['is_test_mode'] ?? true)) {
            return (string) ($this->config['test_mid'] ?? '');
        }

        return $this->buildLiveMid((string) ($this->config['live_mid'] ?? ''));
    }

    /**
     * 현재 모드의 API key 를 반환한다 (authHash 계산용).
     *
     * @return string
     */
    protected function resolveApiKey(): string
    {
        return (bool) ($this->config['is_test_mode'] ?? true)
            ? (string) ($this->config['test_api_key'] ?? '')
            : (string) ($this->config['live_api_key'] ?? '');
    }

    /**
     * 라이브 MID 에 SRA 프리픽스를 보장한다.
     *
     * 운영자가 settings UI 에서 SRA 분리형 input 으로 입력하므로 일반적으로 SRA 미포함 값이
     * 들어오지만, 직접 SRA 포함하여 입력한 경우에도 안전하게 처리한다.
     *
     * @param  string  $value  운영자 입력 값
     * @return string SRA 가 보장된 라이브 MID
     */
    protected function buildLiveMid(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return str_starts_with($value, 'SRA') ? $value : 'SRA'.$value;
    }

    /**
     * 생년월일 (YYYYMMDD) 로 만 나이 계산 후 19세 이상 여부를 반환한다.
     *
     * @param  string  $birthday  YYYYMMDD
     * @return bool
     */
    protected function isAdult(string $birthday): bool
    {
        if (! preg_match('/^\d{8}$/', $birthday)) {
            return false;
        }

        try {
            $birth = Carbon::createFromFormat('Ymd', $birthday)->startOfDay();

            return $birth->age >= 19;
        } catch (\Throwable $e) {
            Log::warning('이니시스 본인확인: 생년월일 파싱 실패', ['birthday' => $birthday, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * STEP3 응답에서 inicis_identity_records UPSERT 용 PII 페이로드를 구성한다.
     * 평문 PII 는 Crypt::encrypt 로 암호화하여 저장한다.
     *
     * @param  array<string, mixed>  $input  STEP2 + STEP3 합산 페이로드
     * @param  InicisDuplicateField  $duplicateField  운영자가 선택한 식별 기준
     * @return array<string, mixed> Repository::upsertForUser 에 전달할 컬럼 값
     */
    protected function buildPiiPayload(array $input, InicisDuplicateField $duplicateField): array
    {
        $userName = (string) ($input['userName'] ?? '');
        $userPhone = (string) ($input['userPhone'] ?? '');
        $userBirthday = (string) ($input['userBirthday'] ?? '');
        $userDi = (string) ($input['userDi'] ?? '');
        $userCi = (string) ($input['userCi'] ?? '');
        $userCi2 = (string) ($input['userCi2'] ?? '');

        return [
            'provider_dev_cd' => $input['providerDevCd'] ?? null,
            // 누락 필드는 "암호화된 빈 문자열" 찌꺼기 대신 null 로 저장한다.
            // name/phone/birthday 는 신원 핵심값이라 verify() 가드(INCOMPLETE_IDENTITY)가
            // 누락을 이미 차단하므로 정상 경로에선 항상 채워진다. 아래 null 분기는 가드를 우회한
            // 비정상 입력에 대비한 방어적 처리(빈 문자열 암호화 찌꺼기 방지)일 뿐이다.
            'name_encrypted' => $userName !== '' ? Crypt::encryptString($userName) : null,
            'phone_encrypted' => $userPhone !== '' ? Crypt::encryptString($userPhone) : null,
            'birthday_encrypted' => $userBirthday !== '' ? Crypt::encryptString($userBirthday) : null,
            'di_encrypted' => $userDi !== '' ? Crypt::encryptString($userDi) : null,
            'di_hash' => $userDi !== '' ? InicisIdentityHasher::hash($userDi) : null,
            'ci_encrypted' => $userCi !== '' ? Crypt::encryptString($userCi) : null,
            'ci_hash' => $userCi !== '' ? InicisIdentityHasher::hash($userCi) : null,
            'ci2_encrypted' => $userCi2 !== '' ? Crypt::encryptString($userCi2) : null,
            'ci2_hash' => $userCi2 !== '' ? InicisIdentityHasher::hash($userCi2) : null,
            'gender' => isset($input['userGender']) ? substr((string) $input['userGender'], 0, 1) : null,
            'is_foreigner' => ($input['isForeign'] ?? '0') === '1',
            'is_adult' => $this->isAdult((string) ($input['userBirthday'] ?? '')),
        ];
    }
}
