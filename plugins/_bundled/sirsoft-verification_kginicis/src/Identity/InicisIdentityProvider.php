<?php

namespace Plugins\Sirsoft\VerificationKginicis\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\ProvidesVerifiedClaimsInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Enums\InicisDuplicateField;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
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
 * 코어의 선택 계약({@see ProvidesVerifiedClaimsInterface})도 구현한다. 확인을 마친 요청 행의 이름, 휴대폰, 생년월일, 성별을
 * 코어가 쓰는 한 가지 모양으로 돌려주어, 운영자가 「본인인증 연결」을 건 회원 항목이 인증 값으로 채워지고 잠기게 한다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityProvider implements IdentityVerificationInterface, ProvidesVerifiedClaimsInterface
{
    /** Provider 식별자 (코어 표준) */
    public const PROVIDER_ID = 'inicis';

    /**
     * 라이브 가맹점 MID 프리픽스 (이니시스 정책값).
     *
     * 라이브 MID = 이 프리픽스 + 운영자 입력값 (예: SRB1234567).
     * 이니시스 프리픽스 정책 변경 시 이 상수 1곳만 갱신하면 런타임 로직 전체에 반영된다.
     * (UI Span·언어팩 힌트 같은 정적 표시 문자열은 상수 참조가 불가하므로 값만 함께 교체)
     */
    public const LIVE_MID_PREFIX = 'SRB';

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
     * @param  IdentityVerificationLogRepositoryInterface|null  $logRepository  코어 본인인증 이력 Repository (1.1.4, 미주입이면 컨테이너에서 해석)
     * @param  InicisIdentityClaimRepositoryInterface|null  $claimRepository  동일인 선점표 (1.1.4, 미주입이면 컨테이너에서 해석)
     */
    public function __construct(
        protected readonly InicisGatewayInterface $gateway,
        protected readonly InicisChallengeMappingRepositoryInterface $mappingRepository,
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly CacheInterface $cache,
        protected readonly array $config = [],
        protected readonly ?IdentityVerificationLogRepositoryInterface $logRepository = null,
        protected readonly ?InicisIdentityClaimRepositoryInterface $claimRepository = null,
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
     * 운영 가능 여부 — 모드별 MID/API key + 라이브 모드의 프리픽스 검증.
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
            && str_starts_with($liveMid, self::LIVE_MID_PREFIX);
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
                // 보안: 이니시스로 보내는 결과 수신 주소(successUrl·failUrl)와 결과 창 출처 검증 기준.
                // 브라우저의 현재 주소(window.location.origin)가 아니라 사이트 주소로 만든다.
                'callbackUrl' => SiteUrl::absolute('/plugins/sirsoft-verification_kginicis/plugin/inicis/callback'),
                'siteOrigin' => SiteUrl::origin(),
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
        // 코어 Repository 로 읽는다(1.1.4) — 아래 상태 검사는 조기 안내일 뿐이고, 동시 콜백의 최종 판정은
        // 트랜잭션 안 조건부 전이(markVerifiedIfPending)가 한다.
        $log = $this->logs()->findById($challengeId);

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

            // [동일인 충돌] 로그인 회원의 본인인증이 **다른 회원**에 이미 묶인 DI/CI 면 실패 처리하고 기록을 만들지 않는다.
            // 가입 경로(AssertNoDuplicateInicisIdentity)와 같은 판정·같은 설정·같은 문구 — 가입만 막으면 일단 가입한 뒤
            // 마이페이지에서 남의 본인인증 결과를 자기 계정에 붙여 「본인인증 회원」이 될 수 있다.
            if ($this->isDuplicateOfOtherUser((int) $log->user_id, $duplicateField, $userDiHashCandidate, $userCiHashCandidate)) {
                $log->update(['status' => 'failed']);

                return VerificationResult::failure(
                    challengeId: $challengeId,
                    providerId: self::PROVIDER_ID,
                    failureCode: 'IDENTITY_DUPLICATE',
                    failureReason: 'sirsoft-verification_kginicis::exceptions.duplicate_register',
                );
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
                // 진행 중인 challenge 만 verified 로 바꾼다(1.1.4, KVE 동형 O7) — 같은 콜백이 동시에 두 번 들어오면
                // 둘 다 위의 상태 검사를 통과하므로, 조건부 전이에서 진 쪽은 토큰을 발급하지 않고 이미 처리됨으로 끝낸다.
                // Eloquent builder update 라 모델 캐스팅을 거치지 않는다 — metadata 는 직접 JSON 으로 넘긴다.
                $transitioned = $this->logs()->markVerifiedIfPending((string) $log->id, [
                    'status' => 'verified',
                    'verification_token' => $verificationToken,
                    'verified_at' => $verifiedAt,
                    'metadata' => json_encode(array_merge((array) $log->metadata, [
                        'sdk_tx_id' => $input['txId'] ?? null,
                        'svcCd' => $input['svcCd'] ?? null,
                        'providerDevCd' => $input['providerDevCd'] ?? null,
                        'di_hash' => $userDiHashCandidate,
                        'ci_hash' => $userCiHashCandidate,
                        'duplicate_field_used' => $duplicateField->value,
                        'is_adult' => $isAdult,
                    ]), JSON_UNESCAPED_UNICODE),
                ]);

                if (! $transitioned) {
                    throw new AlreadyConsumedException($challengeId, (string) ($input['mTxId'] ?? ''));
                }

                if ($log->user_id !== null) {
                    // 로그인 사용자: 자기 명의 식별값을 선점표에도 채워 둔다(비어 있을 때만) — 이후 같은 명의의 신규 가입이
                    // records 가 아니라 선점표에서 막힌다(1.1.4).
                    $claims = $this->claims();
                    if ($userDiHashCandidate !== null) {
                        $claims->claimSoft(InicisDuplicateField::Di->value, $userDiHashCandidate, (int) $log->user_id);
                    }
                    if ($userCiHashCandidate !== null) {
                        $claims->claimSoft(InicisDuplicateField::Ci->value, $userCiHashCandidate, (int) $log->user_id);
                    }

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
        } catch (AlreadyConsumedException $e) {
            // 다른 콜백이 먼저 처리했다 — 트랜잭션은 롤백됐고, 호출자(InicisCallbackResolver)가 ALREADY_CONSUMED 로 안내한다.
            throw $e;
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
     * 로그인 회원의 본인인증 식별값이 다른 회원의 기록과 겹치는지 판정합니다.
     *
     * 운영자가 동일인 중복 차단을 껐으면(`duplicate_block_enabled=false` — 가족 휴대폰 공유 등) 겹쳐도 통과한다.
     * 판정 키는 설정의 `duplicate_field`(DI 또는 CI)이며, 식별값이 없으면(외국인 등) 통과한다 — 가입 경로와 같다.
     *
     * @param  int  $userId  본인인증을 하는 로그인 회원
     * @param  InicisDuplicateField  $duplicateField  판정 키
     * @param  string|null  $diHash  이번 인증의 DI 해시
     * @param  string|null  $ciHash  이번 인증의 CI 해시
     * @return bool 다른 회원과 겹치면 true
     */
    private function isDuplicateOfOtherUser(int $userId, InicisDuplicateField $duplicateField, ?string $diHash, ?string $ciHash): bool
    {
        if (! (bool) g7_plugin_settings('sirsoft-verification_kginicis', 'duplicate_block_enabled', true)) {
            return false;
        }

        $hash = $duplicateField === InicisDuplicateField::Di ? $diHash : $ciHash;

        if ($hash === null) {
            return false;
        }

        $existing = $duplicateField === InicisDuplicateField::Di
            ? $this->recordRepository->findByDiHash($hash)
            : $this->recordRepository->findByCiHash($hash);

        return $existing !== null && (int) $existing->user_id !== $userId;
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
     * 이 수단이 줄 수 있는 인증 값의 키 목록을 반환합니다.
     *
     * 이니시스 본인확인은 이름, 휴대폰, 생년월일을 항상 돌려주고(셋 중 하나라도 비면 인증을 인정하지 않는다) 성별도 함께 준다.
     *
     * @return array<int, string> 인증 값 키 목록 (`name`, `mobile`, `birth_date`, `gender`)
     */
    public function supportedClaims(): array
    {
        return VerifiedClaims::CLAIMS;
    }

    /**
     * 확인을 마친 요청 행의 인증 값을 반환합니다.
     *
     * | 상황 | 값의 원천 |
     * | --- | --- |
     * | 회원이 있는 요청 행 (로그인 회원의 본인인증, 가입을 마친 뒤) | 그 회원의 기록(`inicis_identity_records`). 기록이 이 요청 행의 것(`latest_log_id`)일 때만 |
     * | 가입 전 요청 행 (회원이 아직 없음) | 인증 직후 넣어 둔 임시 저장 값(`inicis:pending_record:{요청 ID}`). 요청 만료까지만 남는다 |
     *
     * 회원 기록은 회원당 한 줄이고 다시 인증하면 덮어쓴다. 그래서 기록이 다른 요청 행의 것이면 이 요청 행의 값을 더는 알 수 없는
     * 것으로 보고 null 을 돌려준다(다른 인증의 값을 이 요청의 값인 것처럼 주지 않는다).
     *
     * @param  IdentityVerificationLog  $log  확인을 마친 본인인증 요청 행
     * @param  User|null  $user  값을 받을 회원 (가입 전이면 null)
     * @return VerifiedClaims|null 인증 값 (이 수단의 요청 행이 아니거나 값을 더는 알 수 없으면 null)
     */
    public function verifiedClaims(IdentityVerificationLog $log, ?User $user): ?VerifiedClaims
    {
        if ((string) $log->provider_id !== self::PROVIDER_ID || ! $log->isVerified()) {
            return null;
        }

        try {
            $values = $this->claimValuesFromRecord($log, $user) ?? $this->claimValuesFromPendingCache($log);
        } catch (DecryptException $e) {
            // 암호화 키가 바뀌어 저장 값을 풀 수 없는 경우다. 값 없이 요청 ID 만 남긴다.
            Log::warning('이니시스 본인확인: 인증 값 복호화 실패', ['challenge_id' => (string) $log->id]);

            return null;
        }

        if ($values === null) {
            return null;
        }

        $claims = new VerifiedClaims(
            name: self::normalizeClaimText($values['name']),
            mobile: self::normalizeClaimMobile($values['phone']),
            birthDate: self::normalizeClaimBirthDate($values['birthday']),
            gender: self::normalizeClaimGender($values['gender']),
        );

        return $claims->isEmpty() ? null : $claims;
    }

    /**
     * 회원 기록에서 이 요청 행의 인증 값을 꺼냅니다.
     *
     * 회원은 요청 행의 `user_id` 로 찾고, 없으면 넘겨받은 회원으로 찾는다. 요청 행이 다른 회원의 것이면 주지 않는다.
     *
     * @param  IdentityVerificationLog  $log  확인을 마친 본인인증 요청 행
     * @param  User|null  $user  값을 받을 회원
     * @return array{name: mixed, phone: mixed, birthday: mixed, gender: mixed}|null 원본 값 (기록이 없거나 다른 요청 행의 기록이면 null)
     *
     * @throws DecryptException 저장 값을 복호화할 수 없을 때
     */
    protected function claimValuesFromRecord(IdentityVerificationLog $log, ?User $user): ?array
    {
        $logUserId = $log->user_id !== null ? (int) $log->user_id : null;
        $givenUserId = $user !== null && $user->exists ? (int) $user->getKey() : null;

        if ($logUserId !== null && $givenUserId !== null && $logUserId !== $givenUserId) {
            return null;
        }

        $userId = $logUserId ?? $givenUserId;
        if ($userId === null) {
            return null;
        }

        $record = $this->recordRepository->findByUserId($userId);
        if ($record === null || (string) $record->latest_log_id !== (string) $log->id) {
            return null;
        }

        return [
            'name' => $record->name,
            'phone' => $record->phone,
            'birthday' => $record->birthday,
            'gender' => $record->gender,
        ];
    }

    /**
     * 가입 전 요청 행의 인증 값을 임시 저장 값에서 꺼냅니다.
     *
     * 임시 저장 키는 요청 ID 로 만들어지므로 다른 요청의 값이 섞이지 않는다. 회원이 있는 요청 행은 임시 저장을 쓰지 않는다.
     *
     * @param  IdentityVerificationLog  $log  확인을 마친 본인인증 요청 행
     * @return array{name: mixed, phone: mixed, birthday: mixed, gender: mixed}|null 원본 값 (임시 저장 값이 없으면 null)
     *
     * @throws DecryptException 저장 값을 복호화할 수 없을 때
     */
    protected function claimValuesFromPendingCache(IdentityVerificationLog $log): ?array
    {
        if ($log->user_id !== null) {
            return null;
        }

        $payload = $this->cache->get(self::PENDING_RECORD_CACHE_PREFIX.$log->id);
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $decrypt = static fn (mixed $value): ?string => is_string($value) && $value !== ''
            ? Crypt::decryptString($value)
            : null;

        return [
            'name' => $decrypt($payload['name_encrypted'] ?? null),
            'phone' => $decrypt($payload['phone_encrypted'] ?? null),
            'birthday' => $decrypt($payload['birthday_encrypted'] ?? null),
            'gender' => $payload['gender'] ?? null,
        ];
    }

    /**
     * 이름 값을 다듬습니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 앞뒤 공백을 뗀 값 (비었으면 null)
     */
    private static function normalizeClaimText(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * 휴대폰 번호에서 숫자만 남깁니다.
     *
     * 통합인증 매뉴얼은 전화번호를 하이픈 없는 숫자(요청 `userPhone` 11 byte)로 다룬다. 응답 쪽은 형식 표기가 없어
     * 하이픈이 섞여 와도 같은 값이 되게 숫자만 남긴다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 숫자만 남긴 번호 (숫자가 없으면 null)
     */
    private static function normalizeClaimMobile(mixed $value): ?string
    {
        $digits = is_scalar($value) ? (string) preg_replace('/\D+/', '', (string) $value) : '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * 생년월일(`YYYYMMDD`)을 `YYYY-MM-DD` 로 바꿉니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null `YYYY-MM-DD` (여덟 자리 숫자가 아니거나 없는 날짜면 null)
     */
    private static function normalizeClaimBirthDate(mixed $value): ?string
    {
        $digits = is_scalar($value) ? (string) preg_replace('/\D+/', '', (string) $value) : '';
        if (strlen($digits) !== 8) {
            return null;
        }

        [$year, $month, $day] = [(int) substr($digits, 0, 4), (int) substr($digits, 4, 2), (int) substr($digits, 6, 2)];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * 기록의 성별 값(`M`, `F`)을 코어 값(`male`, `female`)으로 바꿉니다.
     *
     * 기록에는 이니시스 응답 `userGender` 의 첫 글자가 들어 있다. 통합인증 매뉴얼(인증결과 응답)의 값은 `"M"`: 남성, `"F"`: 여성(1 byte)이고,
     * 본인확인 서비스에서만 오며 전자서명 등에서는 오지 않는다. 그 밖의 값과 빈 값은 주지 않는다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null `male` 또는 `female` (알 수 없으면 null)
     */
    private static function normalizeClaimGender(mixed $value): ?string
    {
        return match (is_scalar($value) ? strtoupper(substr(trim((string) $value), 0, 1)) : '') {
            'M' => VerifiedClaims::GENDER_MALE,
            'F' => VerifiedClaims::GENDER_FEMALE,
            default => null,
        };
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
                'label' => 'Live MID (SRB prefix)',
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
            $this->logRepository,
            $this->claimRepository,
        );
    }

    /**
     * 코어 본인인증 이력 Repository 를 돌려준다 (미주입이면 컨테이너에서 해석 — 기존 생성 호출부 호환).
     *
     * @return IdentityVerificationLogRepositoryInterface 본인인증 이력 Repository
     */
    protected function logs(): IdentityVerificationLogRepositoryInterface
    {
        return $this->logRepository ?? app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * 동일인 선점 Repository 를 돌려준다 (미주입이면 컨테이너에서 해석 — 기존 생성 호출부 호환).
     *
     * @return InicisIdentityClaimRepositoryInterface 동일인 선점 Repository
     */
    protected function claims(): InicisIdentityClaimRepositoryInterface
    {
        return $this->claimRepository ?? app(InicisIdentityClaimRepositoryInterface::class);
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
     * 라이브 MID 에 프리픽스(self::LIVE_MID_PREFIX)를 보장한다.
     *
     * 운영자가 settings UI 에서 프리픽스 분리형 input 으로 입력하므로 일반적으로 프리픽스
     * 미포함 값이 들어오지만, 직접 프리픽스를 포함하여 입력한 경우에도 안전하게 처리한다.
     *
     * @param  string  $value  운영자 입력 값
     * @return string 프리픽스가 보장된 라이브 MID
     */
    protected function buildLiveMid(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return str_starts_with($value, self::LIVE_MID_PREFIX) ? $value : self::LIVE_MID_PREFIX.$value;
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
            // 한국 법상 나이 — 생일과 오늘을 모두 한국 시간 날짜로 센다(서버 UTC 날짜면 한국 0~9시 생일자가 하루 늦게 성인이 된다).
            // audit:allow datetime-display-user-timezone reason: 한국 법상 나이 — Asia/Seoul 고정(D10)
            $birth = Carbon::createFromFormat('Ymd', $birthday, 'Asia/Seoul')->startOfDay();
            // audit:allow datetime-display-user-timezone reason: 한국 법상 나이 — Asia/Seoul 고정(D10)
            $age = (int) floor($birth->diffInYears(Carbon::now('Asia/Seoul')->startOfDay()));

            return $age >= 19;
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
