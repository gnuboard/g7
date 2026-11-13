<?php

namespace App\Services;

use App\Contracts\Extension\ProvidesVerifiedClaimsInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 본인인증 값(이름, 휴대폰, 생년월일, 성별)을 회원 항목에 잇는 유스케이스.
 *
 * 코어는 본인인증 수단이 무엇인지 모른다. 수단이 선택 계약({@see ProvidesVerifiedClaimsInterface})을 구현했으면 인증 값을 받고,
 * 항목 정의의 「본인인증 연결」(`idv_claim`)로 어느 회원 항목에 넣을지 정한다. 「인증 값으로 채우고 잠금」(`idv_lock`)을 켠
 * 항목은 서버가 인증 값으로 덮어쓰고 회원이 고치지 못하게 잠근다.
 *
 * | 자리 | 하는 일 |
 * | --- | --- |
 * | 가입 화면 항목 조회 | 인증 값으로 초기값을 채우고 잠금 항목을 읽기 전용으로 표시 |
 * | 가입 요청 | 잠금 항목의 입력을 인증 값으로 덮어씀 (입력과 달라도 오류가 아니라 인증 값이 원본) |
 * | 내 정보 수정 | 인증 값으로 채워진 잠금 항목을 「현재 값과 같아야 함」으로 잠금 |
 * | 로그인 회원의 재인증 | 잠금 항목을 새 인증 값으로 다시 맞춤 |
 *
 * 인증 값은 회원 항목 값으로만 쓰고 다른 곳에 저장하지 않는다.
 *
 * @since 7.2.0
 */
class IdentityClaimsService
{
    /** 잠금 사유 — 화면이 안내 문구를 고르는 값 */
    public const LOCK_REASON = 'identity';

    /** 토큰 상태: 인증 값을 받았다 */
    public const STATUS_APPLIED = 'applied';

    /** 토큰 상태: 토큰은 유효하지만 이 수단은 인증 값을 주지 않는다 */
    public const STATUS_NO_CLAIMS = 'no_claims';

    /** 토큰 상태: 확인은 됐으나 만료됐거나 이미 쓴 토큰이다 */
    public const STATUS_EXPIRED = 'expired';

    /** 토큰 상태: 알 수 없는 토큰이다 */
    public const STATUS_INVALID = 'invalid';

    /**
     * @param  IdentityVerificationManager  $manager  본인인증 프로바이더 등록소
     * @param  IdentityVerificationLogRepositoryInterface  $logs  본인인증 요청 행 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueService  $values  항목 값 Service
     * @param  FieldRuleFactory  $rules  항목 규칙 팩토리 (인증 값이 항목 유형에 맞는지 판정)
     */
    public function __construct(
        protected IdentityVerificationManager $manager,
        protected IdentityVerificationLogRepositoryInterface $logs,
        protected UserRepositoryInterface $users,
        protected FieldRegistry $registry,
        protected FieldValueService $values,
        protected FieldRuleFactory $rules,
    ) {}

    /**
     * 유효한 본인인증 토큰이 가리키는 인증 값을 반환합니다.
     *
     * 확인을 마쳤고 만료되지 않았으며 아직 쓰지 않은 토큰만 받는다.
     *
     * @param  string  $token  본인인증 토큰 (`verification_token`)
     * @param  string  $purpose  본인인증 목적 (가입은 `signup`)
     * @param  User|null  $user  값을 받을 회원 (가입 전이면 null)
     * @return VerifiedClaims|null 인증 값 (토큰이 유효하지 않거나 수단이 값을 주지 않으면 null)
     */
    public function forToken(string $token, string $purpose = 'signup', ?User $user = null): ?VerifiedClaims
    {
        if ($token === '') {
            return null;
        }

        $log = $this->logs->findVerifiedForToken($token, $purpose);

        return $log !== null ? $this->forLog($log, $user) : null;
    }

    /**
     * 확인을 마친 요청 행의 인증 값을 반환합니다.
     *
     * 수단이 선택 계약을 구현했으면 그 값을 받고, 필터 `core.identity.filter_verified_claims` 를 태운다 —
     * 확장이 값을 고치거나, 계약을 구현하지 않은 수단의 값을 대신 줄 수 있다.
     *
     * @param  IdentityVerificationLog  $log  확인을 마친 본인인증 요청 행
     * @param  User|null  $user  값을 받을 회원 (가입 전이면 null)
     * @return VerifiedClaims|null 인증 값 (값이 하나도 없으면 null)
     */
    public function forLog(IdentityVerificationLog $log, ?User $user = null): ?VerifiedClaims
    {
        if (! $log->isVerified()) {
            return null;
        }

        $provider = $this->claimsProvider((string) $log->provider_id);
        $claims = $provider?->verifiedClaims($log, $user);

        $filtered = HookManager::applyFilters('core.identity.filter_verified_claims', $claims, $log, $user);
        $claims = $filtered instanceof VerifiedClaims ? $filtered : null;

        return $claims !== null && ! $claims->isEmpty() ? $claims : null;
    }

    /**
     * 인증 값을 회원 항목 값으로 바꿉니다 (항목의 「본인인증 연결」로 매핑).
     *
     * 항목 유형의 규칙에 맞지 않는 값(선택지에 없는 성별 값 등)은 넣지 않는다.
     *
     * @param  VerifiedClaims  $claims  인증 값
     * @param  bool  $lockedOnly  「인증 값으로 채우고 잠금」을 켠 항목만 돌려줄지
     * @param  FieldContext|null  $context  요청 문맥 (생략하면 사용 중인 회원 항목 전부가 대상)
     * @return array<string, mixed> 항목 키 => 정규화한 값
     */
    public function fieldValues(VerifiedClaims $claims, bool $lockedOnly = false, ?FieldContext $context = null): array
    {
        $values = [];
        foreach ($this->mappedDefinitions($context) as $definition) {
            if ($lockedOnly && ! $definition->idv_lock) {
                continue;
            }

            $value = $this->valueFor($definition, $claims);
            if ($value !== null) {
                $values[$definition->key] = $value;
            }
        }

        return $values;
    }

    /**
     * 가입 요청에서 인증 값으로 덮어쓸 입력을 반환합니다 (잠금 항목만).
     *
     * @param  string  $token  본인인증 토큰
     * @return array<string, mixed> 요청 속성 경로 => 인증 값 (덮어쓸 것이 없으면 빈 배열)
     */
    public function signupOverrides(string $token): array
    {
        $claims = $this->forToken($token, 'signup');
        if ($claims === null) {
            return [];
        }

        $context = FieldContext::for(FieldContext::SURFACE_SIGNUP);
        $locked = $this->fieldValues($claims, lockedOnly: true, context: $context);

        $overrides = [];
        foreach ($this->mappedDefinitions($context) as $definition) {
            if (array_key_exists($definition->key, $locked)) {
                $overrides[$this->registry->attributeFor($definition)] = $locked[$definition->key];
            }
        }

        return $overrides;
    }

    /**
     * 토큰의 인증 값과 그 값으로 채울 회원 항목을 화면용으로 반환합니다.
     *
     * @param  string  $token  본인인증 토큰
     * @param  string  $purpose  본인인증 목적
     * @param  User|null  $user  요청한 회원 (비로그인이면 null)
     * @param  FieldContext|null  $context  채울 항목을 고를 문맥 (생략하면 사용 중인 회원 항목 전부)
     * @return array{status: string, provider_id: string|null, claims: array<string, string>, fields: array<string, mixed>, locked: array<int, string>} 토큰 상태, 수단, 인증 값, 항목 키별 채움값, 잠글 항목 키
     */
    public function describeForToken(string $token, string $purpose = 'signup', ?User $user = null, ?FieldContext $context = null): array
    {
        $empty = ['status' => self::STATUS_INVALID, 'provider_id' => null, 'claims' => [], 'fields' => [], 'locked' => []];

        $log = $token !== '' ? $this->logs->findVerifiedForToken($token, $purpose) : null;
        if ($log === null) {
            $known = $token !== '' && $this->logs->findVerifiedByToken($token, $purpose) !== null;

            return [...$empty, 'status' => $known ? self::STATUS_EXPIRED : self::STATUS_INVALID];
        }

        // 회원에게 묶인 요청 행은 그 회원만 읽는다 — 남의 토큰으로 인증 값을 꺼내지 못하게 한다.
        if ($log->user_id !== null && (int) $log->user_id !== (int) $user?->getKey()) {
            return $empty;
        }

        $claims = $this->forLog($log, $user);
        if ($claims === null) {
            return [...$empty, 'status' => self::STATUS_NO_CLAIMS, 'provider_id' => (string) $log->provider_id];
        }

        return [
            'status' => self::STATUS_APPLIED,
            'provider_id' => (string) $log->provider_id,
            'claims' => $claims->toArray(),
            'fields' => $this->fieldValues($claims, context: $context),
            'locked' => array_keys($this->fieldValues($claims, lockedOnly: true, context: $context)),
        ];
    }

    /**
     * 회원에게 본인인증으로 잠긴 항목 키를 반환합니다.
     *
     * 인증 값을 주는 수단으로 본인인증을 마친 회원의, 「인증 값으로 채우고 잠금」을 켠 항목 가운데 **지금 값이 그 회원의 최근 인증
     * 값과 같은 것**이다. 인증 값이 유형에 맞지 않아 채워지지 않은 칸에 회원이 직접 쓴 값, 운영자가 회원 인증
     * 뒤에 잠금을 켠 경우 회원이 먼저 넣은 값은 인증 값이 아니므로 잠그지 않는다. 최근 인증 기록을 찾지 못하거나 그 기록에서
     * 인증 값을 얻지 못하면(수단이 값을 보관하지 않는 경우 등) 아무것도 잠그지 않는다. 비교는 인증 값을 항목 유형으로 정규화한
     * 값(`fieldValues()` 와 같은 규칙)과 저장된 값 사이에서 한다.
     *
     * @param  User  $user  회원
     * @return array<int, string> 잠긴 항목 키
     */
    public function lockedKeysFor(User $user): array
    {
        $definitions = $this->mappedDefinitions()->filter(fn (FieldDefinition $definition) => (bool) $definition->idv_lock)->values();
        if ($definitions->isEmpty() || ! $user->exists) {
            return [];
        }

        $providerId = (string) ($user->identity_verified_provider ?? '');
        $provider = $providerId !== '' ? $this->claimsProvider($providerId) : null;
        if ($provider === null) {
            return [];
        }

        $supported = $provider->supportedClaims();
        $definitions = $definitions->filter(fn (FieldDefinition $definition) => in_array($definition->idv_claim, $supported, true))->values();
        if ($definitions->isEmpty()) {
            return [];
        }

        // 지금 값과 견줄 인증 값 — 이 회원이 그 수단으로 마친 가장 최근의 본인인증 기록에서 얻는다
        $log = $this->logs->findLatestVerifiedForUser((int) $user->id, $providerId);
        $claims = $log !== null ? $this->forLog($log, $user) : null;
        if ($claims === null) {
            return [];
        }

        $current = $this->values->read($user, FieldSet::CORE_USER, $definitions);
        $encode = fn (mixed $value) => $value === null || $value === '' || $value === [] ? null : json_encode($value);

        return $definitions
            ->filter(function (FieldDefinition $definition) use ($claims, $current, $encode) {
                $now = $current[$definition->key] ?? null;
                if ($this->values->isEmpty($definition, $now)) {
                    return false;
                }
                $verified = $this->valueFor($definition, $claims);

                return $verified !== null && $encode($verified) === $encode($now);
            })
            ->map(fn (FieldDefinition $definition) => $definition->key)
            ->values()
            ->all();
    }

    /**
     * 가입을 마친 회원에게 가입 때 쓴 본인인증을 기록합니다.
     *
     * 가입 전 본인인증은 회원이 없는 상태에서 끝나므로 회원 행에 인증 수단이 남지 않는다. 인증 값을 주는 수단으로 인증하고
     * 가입했으면 여기서 남긴다 — 내 정보 수정의 잠금이 이 기록을 읽는다.
     *
     * @param  User  $user  가입한 회원
     * @param  string  $token  가입 요청에 실린 본인인증 토큰
     * @return bool 기록했으면 true
     */
    public function recordSignupVerification(User $user, string $token): bool
    {
        if ($token === '' || ! $user->exists) {
            return false;
        }

        // 가입 처리 중에 토큰이 이미 소비됐을 수 있어 소비 여부와 무관하게 찾는다.
        $log = $this->logs->findVerifiedByToken($token, 'signup');
        if ($log === null || $this->claimsProvider((string) $log->provider_id) === null) {
            return false;
        }

        // 가입 전 본인인증 행에는 회원이 없다 — 가입한 회원에 묶어 두어야 잠금 판정이 이 회원의 최근 인증 값을 찾는다
        if ($log->user_id === null) {
            $this->logs->updateById((string) $log->id, ['user_id' => $user->id]);
        }

        return $this->users->update($user, [
            'identity_verified_at' => $log->verified_at ?? now(),
            'identity_verified_provider' => (string) $log->provider_id,
            'identity_verified_purpose_last' => (string) $log->purpose,
        ]);
    }

    /**
     * 회원의 잠금 항목을 새 인증 값으로 다시 맞춥니다 (로그인 회원의 재인증).
     *
     * 요청 검증을 거치지 않는 서버 쪽 동기화다 — 잠금 항목은 인증 값이 원본이므로 회원이 넣었던 값을 덮어쓴다.
     *
     * @param  User  $user  회원
     * @param  VerifiedClaims  $claims  새 인증 값
     * @return array<int, string> 값이 바뀐 항목 키
     */
    public function syncToUser(User $user, VerifiedClaims $claims): array
    {
        $values = $this->fieldValues($claims, lockedOnly: true);
        if ($values === [] || ! $user->exists) {
            return [];
        }

        $context = FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user);
        $columns = [];
        $changed = [];

        foreach ($this->mappedDefinitions() as $definition) {
            if (! array_key_exists($definition->key, $values) || $definition->storage !== FieldDefinition::STORAGE_COLUMN) {
                continue;
            }

            $column = (string) $definition->storage_target;
            if ($column !== '' && (string) $user->getAttribute($column) !== (string) $values[$definition->key]) {
                $columns[$column] = $values[$definition->key];
                $changed[] = $definition->key;
            }
        }

        if ($columns !== []) {
            $this->users->update($user, $columns);
        }

        $written = $this->values->write($user, FieldSet::CORE_USER, $values, $context);

        return array_values(array_unique([...$changed, ...array_keys($written)]));
    }

    /**
     * 「본인인증 연결」이 있는 회원 항목을 반환합니다.
     *
     * @param  FieldContext|null  $context  요청 문맥 (있으면 그 표면에서 받는 항목만)
     * @return Collection<int, FieldDefinition> 항목 목록
     */
    protected function mappedDefinitions(?FieldContext $context = null): Collection
    {
        $definitions = $context !== null
            ? $this->registry->forSurface(FieldSet::CORE_USER, $context)
            : $this->registry->active(FieldSet::CORE_USER);

        return $definitions
            ->filter(fn (FieldDefinition $definition) => is_string($definition->idv_claim) && $definition->idv_claim !== '')
            ->values();
    }

    /**
     * 항목에 넣을 인증 값을 그 항목 유형의 모양으로 만듭니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  VerifiedClaims  $claims  인증 값
     * @return mixed 정규화한 값 (인증 값이 없거나 유형 규칙에 맞지 않으면 null)
     */
    protected function valueFor(FieldDefinition $definition, VerifiedClaims $claims): mixed
    {
        $raw = $claims->get((string) $definition->idv_claim);
        $type = $this->registry->typeOf($definition);
        if ($raw === null || $type === null) {
            return null;
        }

        // 유형 규칙으로 걸러 낸다 — 선택지에 없는 값이나 형식이 다른 값으로 칸을 채우고 잠그지 않는다.
        if (! $this->rules->accepts($definition, $raw)) {
            return null;
        }

        $value = $type->normalize($definition, $raw);

        return $type->isEmpty($value) ? null : $value;
    }

    /**
     * 인증 값을 주는 프로바이더를 찾습니다.
     *
     * @param  string  $providerId  프로바이더 식별자
     * @return ProvidesVerifiedClaimsInterface|null 선택 계약을 구현한 프로바이더 (등록되지 않았거나 구현하지 않았으면 null)
     */
    protected function claimsProvider(string $providerId): ?ProvidesVerifiedClaimsInterface
    {
        if ($providerId === '' || ! $this->manager->has($providerId)) {
            return null;
        }

        $provider = $this->manager->get($providerId);

        return $provider instanceof ProvidesVerifiedClaimsInterface ? $provider : null;
    }
}
