<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Fields\FieldContext;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Rules\ForbiddenWords;
use App\Rules\NicknamePolicy;
use App\Support\NicknameNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 닉네임 정책 (환경설정 > 회원 > 닉네임 정책).
 *
 * 중복 금지, 금지어, 변경 주기를 한 곳에서 판정한다. 요청 규칙({@see NicknamePolicy})과
 * 요청을 거치지 않는 서비스 경로(외부 인증 가입, 속성 동기화)가 같은 판정을 쓴다.
 *
 * 판정은 닉네임을 **바꿀 때만** 한다. 정책을 켜기 전에 이미 겹치던 닉네임이나 나중에 금지어가 된
 * 닉네임을 가진 회원도 다른 칸은 그대로 고칠 수 있다.
 *
 * @since 7.2.0
 */
class NicknamePolicyService
{
    /** 위반 사유: 금지어 포함 */
    public const REASON_FORBIDDEN_WORD = 'forbidden_word';

    /** 위반 사유: 다른 회원이 쓰는 닉네임 */
    public const REASON_DUPLICATE = 'duplicate';

    /** 위반 사유: 변경 주기가 지나지 않음 */
    public const REASON_CHANGE_INTERVAL = 'change_interval';

    /** 닉네임 중복 키의 unique 인덱스 이름 */
    public const UNIQUE_INDEX = 'uq_users_nickname_unique_key';

    /**
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        private UserRepositoryInterface $users,
    ) {}

    /**
     * 중복 금지가 켜져 있는지 반환합니다.
     *
     * @return bool 켜져 있으면 true
     */
    public function uniqueEnabled(): bool
    {
        return (bool) g7_core_settings('member.nickname_unique', false);
    }

    /**
     * 금지어 목록을 반환합니다.
     *
     * @return array<int, string> 금지어
     */
    public function bannedWords(): array
    {
        $words = g7_core_settings('member.nickname_banned_words', []);

        return is_array($words)
            ? array_values(array_filter($words, fn ($word) => is_string($word) && trim($word) !== ''))
            : [];
    }

    /**
     * 표시 이름으로 보일 수 있는 값에 금지어가 들어 있으면 그 금지어를 반환합니다.
     *
     * 닉네임과 같은 금지어 목록과 같은 조건을 쓴다. 전각, 대소문자, 공백 차이는 무시하고,
     * 관리자가 관리자 화면에서 정하는 값은 면제다. 표시 이름 종류로 쓰는 회원 추가 항목이 이 판정을 쓴다.
     *
     * @param  string|null  $value  검사할 값
     * @param  string  $surface  표면 ({@see FieldContext}::SURFACE_*)
     * @param  User|null  $actor  요청한 사람 (없으면 시스템 경로)
     * @return string|null 걸린 금지어 (없거나 면제면 null)
     */
    public function forbiddenWordIn(?string $value, string $surface, ?User $actor = null): ?string
    {
        if ($value === null || $value === '' || $this->adminExempt($surface, $actor)) {
            return null;
        }

        return ForbiddenWords::firstMatch($value, $this->bannedWords(), true);
    }

    /**
     * 변경 주기(일)를 반환합니다.
     *
     * @return int 일 수 (0 이면 제한 없음)
     */
    public function intervalDays(): int
    {
        return max(0, (int) g7_core_settings('member.nickname_change_interval_days', 0));
    }

    /**
     * 본인이 닉네임을 다시 바꿀 수 있는 시각을 반환합니다.
     *
     * @param  User  $user  회원
     * @return CarbonInterface|null 아직 못 바꾸면 그 시각, 바꿀 수 있으면 null
     */
    public function nextChangeableAt(User $user): ?CarbonInterface
    {
        $days = $this->intervalDays();
        if ($days === 0 || $user->nickname_changed_at === null) {
            return null;
        }

        $next = $user->nickname_changed_at->copy()->addDays($days);

        return $next->isFuture() ? $next : null;
    }

    /**
     * 닉네임이 정책에 걸리는지 판정합니다.
     *
     * | 검사 | 도는 조건 |
     * | --- | --- |
     * | 금지어 | 닉네임 글자가 지금과 다를 때. 관리자가 관리자 화면에서 정하는 닉네임은 면제 |
     * | 중복 | 중복 금지가 켜져 있고 정규화 값이 지금과 다를 때 |
     * | 변경 주기 | 본인 수정이고 정규화 값이 지금과 다를 때 |
     *
     * @param  User|null  $target  닉네임을 바꿀 회원 (새로 만드는 중이면 null)
     * @param  string|null  $nickname  새 닉네임
     * @param  string  $surface  표면 ({@see FieldContext}::SURFACE_*)
     * @param  User|null  $actor  요청한 사람 (없으면 시스템 경로)
     * @return array{reason: string, message: string}|null 위반 내용 (통과면 null)
     */
    public function violation(?User $target, ?string $nickname, string $surface, ?User $actor = null): ?array
    {
        if ($nickname === null || $nickname === '') {
            return null;
        }

        $exists = $target !== null && $target->exists;
        if ($exists && (string) $target->nickname === $nickname) {
            return null;
        }

        $normalized = NicknameNormalizer::normalize($nickname);
        if ($normalized === null) {
            return null;
        }

        if (! $this->adminExempt($surface, $actor)) {
            $word = ForbiddenWords::firstMatch($nickname, $this->bannedWords(), true);
            if ($word !== null) {
                return $this->result(self::REASON_FORBIDDEN_WORD, ['word' => $word]);
            }
        }

        $current = $exists ? ($target->nickname_normalized ?? NicknameNormalizer::normalize($target->nickname)) : null;
        if ($current === $normalized) {
            return null;
        }

        if ($this->uniqueEnabled() && $this->users->nicknameNormalizedTaken($normalized, $exists ? (int) $target->getKey() : null)) {
            return $this->result(self::REASON_DUPLICATE);
        }

        if ($exists && $surface === FieldContext::SURFACE_SELF_UPDATE) {
            $next = $this->nextChangeableAt($target);
            if ($next !== null) {
                return $this->result(self::REASON_CHANGE_INTERVAL, [
                    'days' => $this->intervalDays(),
                    'date' => $next->copy()->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d H:i'),
                ]);
            }
        }

        return null;
    }

    /**
     * 닉네임이 정책에 걸리면 사유를 반환합니다.
     *
     * @param  User|null  $target  닉네임을 바꿀 회원 (새로 만드는 중이면 null)
     * @param  string  $nickname  새 닉네임
     * @param  string  $surface  표면
     * @param  User|null  $actor  요청한 사람
     * @return string|null 위반 사유 (REASON_*), 통과면 null
     */
    public function check(?User $target, string $nickname, string $surface, ?User $actor = null): ?string
    {
        return $this->violation($target, $nickname, $surface, $actor)['reason'] ?? null;
    }

    /**
     * 닉네임과 함께 저장할 비교 컬럼을 계산합니다.
     *
     * 중복 키는 중복 금지가 켜져 있을 때만 채운다. 정규화 값이 그대로면 중복 키를 건드리지 않는다 —
     * 정책을 켜기 전부터 겹치던 닉네임은 키가 비어 있어야 unique 인덱스에 걸리지 않는다.
     *
     * @param  User|null  $target  회원 (새로 만드는 중이면 null)
     * @param  string|null  $nickname  저장할 닉네임
     * @param  string  $surface  표면
     * @return array<string, mixed> 회원 행에 합칠 컬럼 값
     */
    public function columns(?User $target, ?string $nickname, string $surface): array
    {
        $normalized = NicknameNormalizer::normalize($nickname);
        $exists = $target !== null && $target->exists;

        if ($normalized === null) {
            return ['nickname_normalized' => null, 'nickname_unique_key' => null];
        }

        if ($exists && $target->nickname_normalized === $normalized) {
            return [];
        }

        // 글자는 그대로인데 정규화 값만 비어 있던 회원(업그레이드 전 가입)이 이미 겹치는 닉네임이면 키를 비워 둔다.
        $unique = $this->uniqueEnabled()
            && ! $this->users->nicknameNormalizedTaken($normalized, $exists ? (int) $target->getKey() : null);

        $columns = [
            'nickname_normalized' => $normalized,
            'nickname_unique_key' => $unique ? $normalized : null,
        ];

        // 변경 주기는 본인이 바꾼 시각부터 센다(가입과 관리자 수정은 세지 않는다).
        if ($exists && $surface === FieldContext::SURFACE_SELF_UPDATE
            && NicknameNormalizer::normalize($target->nickname) !== $normalized) {
            $columns['nickname_changed_at'] = now();
        }

        return $columns;
    }

    /**
     * 저장할 회원 데이터에 닉네임 정책을 적용합니다 (서비스 계층 공통).
     *
     * 데이터에 `nickname` 키가 없으면 아무것도 하지 않는다. 있으면 판정하고, 통과하면 비교 컬럼을 합친다.
     * 걸렸을 때는 호출 문맥으로 가른다 — `$options['nickname_policy'] === 'skip_field'`(사람이 화면을 보지 않는
     * 경로)면 닉네임만 빼고 나머지를 저장하게 하고, 아니면 검증 예외를 던진다.
     *
     * @param  User|null  $target  회원 (새로 만드는 중이면 null)
     * @param  array<string, mixed>  $data  회원 행 데이터
     * @param  string  $surface  표면
     * @param  User|null  $actor  요청한 사람
     * @param  array<string, mixed>  $options  호출 옵션 (`nickname_policy`)
     * @return array{0: array<string, mixed>, 1: array{nickname: string, reason: string}|null} [고친 데이터, 버린 닉네임 정보]
     *
     * @throws ValidationException 정책에 걸렸고 닉네임만 버리는 문맥이 아닐 때
     */
    public function apply(?User $target, array $data, string $surface, ?User $actor = null, array $options = []): array
    {
        if (! array_key_exists('nickname', $data)) {
            return [$data, null];
        }

        $nickname = is_string($data['nickname']) && trim($data['nickname']) !== '' ? $data['nickname'] : null;
        $violation = $this->violation($target, $nickname, $surface, $actor);

        if ($violation !== null) {
            if (($options['nickname_policy'] ?? null) !== 'skip_field') {
                throw $this->exception($violation);
            }

            unset($data['nickname']);

            return [$data, ['nickname' => (string) $nickname, 'reason' => $violation['reason']]];
        }

        return [array_merge($data, $this->columns($target, $nickname, $surface)), null];
    }

    /**
     * 위반 내용을 검증 예외로 만듭니다.
     *
     * @param  array{reason: string, message: string}  $violation  위반 내용
     * @return ValidationException 닉네임 칸의 검증 예외
     */
    public function exception(array $violation): ValidationException
    {
        return ValidationException::withMessages(['nickname' => [$violation['message']]]);
    }

    /**
     * 저장 중에 난 예외가 닉네임 중복 키 충돌이면 검증 예외로 바꿉니다.
     *
     * 판정과 저장 사이에 다른 회원이 같은 닉네임을 먼저 저장한 경우다.
     *
     * @param  Throwable  $e  저장 중 예외
     * @return ValidationException|null 닉네임 중복이면 검증 예외, 아니면 null
     */
    public function duplicateFrom(Throwable $e): ?ValidationException
    {
        if ($e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), self::UNIQUE_INDEX)) {
            return $this->exception($this->result(self::REASON_DUPLICATE));
        }

        return null;
    }

    /**
     * 관리자 면제 대상인지 반환합니다 (금지어).
     *
     * @param  string  $surface  표면
     * @param  User|null  $actor  요청한 사람
     * @return bool 면제면 true
     */
    private function adminExempt(string $surface, ?User $actor): bool
    {
        return $actor !== null
            && in_array($surface, [FieldContext::SURFACE_ADMIN_CREATE, FieldContext::SURFACE_ADMIN_UPDATE], true);
    }

    /**
     * 위반 내용을 만듭니다.
     *
     * @param  string  $reason  사유
     * @param  array<string, mixed>  $params  문구 치환값
     * @return array{reason: string, message: string} 위반 내용
     */
    private function result(string $reason, array $params = []): array
    {
        $message = match ($reason) {
            self::REASON_FORBIDDEN_WORD => __('user.validation.nickname_forbidden_word', $params),
            self::REASON_CHANGE_INTERVAL => __('user.validation.nickname_change_interval', $params),
            default => __('user.validation.nickname_duplicate', $params),
        };

        return ['reason' => $reason, 'message' => $message];
    }
}
