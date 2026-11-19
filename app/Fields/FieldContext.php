<?php

namespace App\Fields;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 항목 규칙·저장이 돌아가는 문맥.
 *
 * @since 7.2.0
 */
final class FieldContext
{
    /** 표면: 가입 */
    public const SURFACE_SIGNUP = 'signup';

    /** 표면: 관리자 회원 추가 */
    public const SURFACE_ADMIN_CREATE = 'admin_create';

    /** 표면: 관리자 회원 수정 */
    public const SURFACE_ADMIN_UPDATE = 'admin_update';

    /** 표면: 내 정보 수정 */
    public const SURFACE_SELF_UPDATE = 'self_update';

    /** 표면: 추가 정보 입력 */
    public const SURFACE_REQUIRED_INFO = 'required_info';

    /** 회원 본인이 값을 넣는 표면 */
    public const SELF_SURFACES = [self::SURFACE_SIGNUP, self::SURFACE_SELF_UPDATE, self::SURFACE_REQUIRED_INFO];

    /** 부가 문맥 키: 회원 항목을 모르는 옛 화면(7.1.x 템플릿)에서 온 요청 — {@see self::onLegacyScreen()} */
    public const EXTRA_LEGACY_SCREEN = 'legacy_screen';

    /**
     * @param  string  $surface  표면 (SURFACE_* 또는 확장이 정한 값)
     * @param  Model|null  $owner  값의 소유자 (수정 표면에서 대상 회원 등. 생성 표면은 null)
     * @param  User|null  $actor  요청한 사용자
     * @param  string|null  $scopeKey  집합 안의 범위 키 (게시판 식별자 등)
     * @param  array<int, string>|null  $onlyKeys  이 키의 항목만 대상으로 삼는다 (추가 정보 입력 화면)
     * @param  array<string, mixed>  $extra  확장이 쓰는 부가 문맥
     */
    public function __construct(
        public readonly string $surface,
        public readonly ?Model $owner = null,
        public readonly ?User $actor = null,
        public readonly ?string $scopeKey = null,
        public readonly ?array $onlyKeys = null,
        public readonly array $extra = [],
    ) {}

    /**
     * 표면만 정한 문맥을 만듭니다.
     *
     * @param  string  $surface  표면
     * @param  Model|null  $owner  값의 소유자
     * @param  User|null  $actor  요청한 사용자
     * @return self 문맥
     */
    public static function for(string $surface, ?Model $owner = null, ?User $actor = null): self
    {
        return new self($surface, $owner, $actor);
    }

    /**
     * 회원 본인이 값을 넣는 표면인지 반환합니다.
     *
     * @return bool 본인 표면이면 true
     */
    public function isSelfSurface(): bool
    {
        return in_array($this->surface, self::SELF_SURFACES, true);
    }

    /**
     * 회원 항목을 모르는 옛 화면에서 온 요청의 문맥을 만듭니다 (나머지는 그대로).
     *
     * 7.1.x 템플릿의 화면에는 회원 항목 조각이 없어 운영자 추가 항목과 확장 선언 항목의 입력칸이 없다. 이 문맥으로 만든
     * 규칙은 그 항목의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다({@see FieldRuleFactory::rules()}). 형식 규칙과 코어 기본 항목
     * 규칙은 그대로다. 부가 문맥(`extra.legacy_screen`)으로 실리므로 규칙 필터 훅도 이 사실을 받는다.
     *
     * @return self 옛 화면 표시를 단 문맥
     *
     * @since 7.2.0
     */
    public function onLegacyScreen(): self
    {
        return new self($this->surface, $this->owner, $this->actor, $this->scopeKey, $this->onlyKeys, [
            ...$this->extra,
            self::EXTRA_LEGACY_SCREEN => true,
        ]);
    }

    /**
     * 회원 항목을 모르는 옛 화면에서 온 요청의 문맥인지 반환합니다.
     *
     * @return bool 옛 화면 표시가 있으면 true
     *
     * @since 7.2.0
     */
    public function isLegacyScreen(): bool
    {
        return ($this->extra[self::EXTRA_LEGACY_SCREEN] ?? false) === true;
    }

    /**
     * 훅에 넘길 배열로 바꿉니다.
     *
     * @return array<string, mixed> 문맥 배열
     */
    public function toArray(): array
    {
        return [
            'surface' => $this->surface,
            'owner' => $this->owner,
            'actor' => $this->actor,
            'scope_key' => $this->scopeKey,
            'only_keys' => $this->onlyKeys,
            'extra' => $this->extra,
        ];
    }
}
