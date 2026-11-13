<?php

namespace App\DisplayName;

use App\Models\FieldDefinition;

/**
 * 표시 이름 영역 — 회원 이름이 보이는 자리 한 곳(사이트 기본, 관리자 화면, 알림 채널, 게시판 글 등).
 *
 * 영역마다 방식을 정한다.
 *
 * | 방식 | 뜻 |
 * | --- | --- |
 * | inherit | 부모 영역을 따른다 |
 * | fixed | 종류 하나로 고정한다 |
 * | member | 회원이 고른 종류로 보인다(고르지 않았으면 회원 기본 종류) |
 *
 * 영역 키의 구분자는 `:` 다(점은 설정 경로 구분자와 겹친다). 확장 영역은 `{확장 식별자}:{이름}` 형식이다.
 *
 * @since 7.2.0
 */
final class DisplayNameArea
{
    /** 방식: 부모 영역을 따른다 */
    public const MODE_INHERIT = 'inherit';

    /** 방식: 종류 하나로 고정 */
    public const MODE_FIXED = 'fixed';

    /** 방식: 회원이 고른 종류 */
    public const MODE_MEMBER = 'member';

    /** 방식 목록 */
    public const MODES = [self::MODE_INHERIT, self::MODE_FIXED, self::MODE_MEMBER];

    /** 보는 사람: 사용자 화면 */
    public const AUDIENCE_USER = 'user';

    /** 보는 사람: 관리자 화면 */
    public const AUDIENCE_ADMIN = 'admin';

    /** 보는 사람: 알림 수신자 */
    public const AUDIENCE_NOTIFICATION = 'notification';

    /** 영역 키 형식 — `{소유자}:{이름}` 또는 더 깊은 단계. 점은 쓰지 못한다 */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]*(:[a-z0-9][a-z0-9_-]*)+$/';

    /**
     * @param  string  $key  영역 키 (예: `core:site`, `sirsoft-board:posts`)
     * @param  string|array<string, string>  $label  영역 이름 (번역 키 또는 다국어 배열)
     * @param  string  $ownerType  등록한 쪽의 종류 (core|module|plugin)
     * @param  string  $ownerIdentifier  등록한 쪽의 식별자
     * @param  string|null  $parent  부모 영역 키 (최상위 `core:site` 만 null)
     * @param  string  $audience  보는 사람 (AUDIENCE_*)
     * @param  string  $defaultMode  설정에 없을 때의 방식 (MODE_*)
     * @param  string|null  $defaultKind  설정에 없을 때의 고정 종류 (방식이 fixed 일 때)
     * @param  mixed  $instanceResolver  영역 안의 인스턴스별 설정을 돌려주는 호출체 — `fn (array $context): ?array{mode: string, kind?: string|null}` (없으면 null)
     * @param  mixed  $usedKindsProvider  인스턴스들이 쓰고 있는 설정을 돌려주는 호출체 — `fn (): array<int, array{mode: string, kind?: string|null, label?: string}>` (없으면 null)
     * @param  int  $sortOrder  운영자 화면의 표시 순서
     */
    public function __construct(
        public readonly string $key,
        public readonly string|array $label,
        public readonly string $ownerType = 'core',
        public readonly string $ownerIdentifier = 'core',
        public readonly ?string $parent = DisplayNameAreaRegistry::AREA_SITE,
        public readonly string $audience = self::AUDIENCE_USER,
        public readonly string $defaultMode = self::MODE_INHERIT,
        public readonly ?string $defaultKind = null,
        public readonly mixed $instanceResolver = null,
        public readonly mixed $usedKindsProvider = null,
        public readonly int $sortOrder = 0,
    ) {}

    /**
     * 확장 선언 배열에서 영역을 만듭니다.
     *
     * @param  array<string, mixed>  $data  선언 (`key`, `label`, `parent`, `audience`, `default`, `instance_resolver`, `used_kinds_provider`, `sort_order`)
     * @param  string  $ownerType  등록한 쪽의 종류
     * @param  string  $ownerIdentifier  등록한 쪽의 식별자
     * @return self|null 영역 (키 형식이 맞지 않으면 null)
     */
    public static function fromArray(array $data, string $ownerType, string $ownerIdentifier): ?self
    {
        $key = $data['key'] ?? null;
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $default = is_array($data['default'] ?? null) ? $data['default'] : [];
        $mode = in_array($default['mode'] ?? null, self::MODES, true) ? $default['mode'] : self::MODE_INHERIT;
        $audience = in_array($data['audience'] ?? null, [self::AUDIENCE_USER, self::AUDIENCE_ADMIN, self::AUDIENCE_NOTIFICATION], true)
            ? $data['audience']
            : self::AUDIENCE_USER;
        $parent = is_string($data['parent'] ?? null) && $data['parent'] !== '' ? $data['parent'] : null;

        return new self(
            key: $key,
            label: is_array($data['label'] ?? null) || is_string($data['label'] ?? null) ? $data['label'] : $key,
            ownerType: $ownerType,
            ownerIdentifier: $ownerIdentifier,
            parent: $parent ?? ($audience === self::AUDIENCE_ADMIN ? DisplayNameAreaRegistry::AREA_ADMIN : DisplayNameAreaRegistry::AREA_SITE),
            audience: $audience,
            defaultMode: $mode,
            defaultKind: $mode === self::MODE_FIXED && is_string($default['kind'] ?? null) ? $default['kind'] : null,
            instanceResolver: $data['instance_resolver'] ?? null,
            usedKindsProvider: $data['used_kinds_provider'] ?? null,
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );
    }

    /**
     * 화면에 보일 영역 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string 영역 이름
     */
    public function label(?string $locale = null): string
    {
        if (is_array($this->label)) {
            return FieldDefinition::localizeValue($this->label, $locale) ?? $this->key;
        }

        $translated = __($this->label, [], $locale);

        return is_string($translated) && $translated !== '' ? $translated : $this->key;
    }

    /**
     * 선언 기본값을 설정 모양으로 반환합니다.
     *
     * @return array{mode: string, kind: string|null} 방식과 종류
     */
    public function defaultSetting(): array
    {
        return ['mode' => $this->defaultMode, 'kind' => $this->defaultMode === self::MODE_FIXED ? $this->defaultKind : null];
    }

    /**
     * 호출 문맥이 가리키는 인스턴스의 설정을 반환합니다 (게시판마다 다른 설정 등).
     *
     * @param  array<string, mixed>  $context  호출 문맥 (확장이 정한 키. 예: `board`)
     * @return array{mode: string, kind: string|null}|null 인스턴스 설정 (없거나 따름이면 null)
     */
    public function instanceSetting(array $context): ?array
    {
        $resolver = $this->callable($this->instanceResolver);
        if ($resolver === null) {
            return null;
        }

        $setting = $resolver($context);
        if (! is_array($setting) || ! in_array($setting['mode'] ?? null, [self::MODE_FIXED, self::MODE_MEMBER], true)) {
            return null;
        }

        return ['mode' => $setting['mode'], 'kind' => is_string($setting['kind'] ?? null) ? $setting['kind'] : null];
    }

    /**
     * 이 영역의 인스턴스들이 쓰고 있는 설정을 반환합니다 (닉네임 잠금 판정과 추가 항목 사용처 안내용).
     *
     * @return array<int, array{mode: string, kind: string|null, label: string|null}> 인스턴스별 설정 (따름인 인스턴스는 싣지 않는다)
     */
    public function instanceUsages(): array
    {
        $provider = $this->callable($this->usedKindsProvider);
        if ($provider === null) {
            return [];
        }

        $usages = [];
        foreach ((array) $provider() as $usage) {
            if (! is_array($usage) || ! in_array($usage['mode'] ?? null, [self::MODE_FIXED, self::MODE_MEMBER], true)) {
                continue;
            }
            $usages[] = [
                'mode' => $usage['mode'],
                'kind' => is_string($usage['kind'] ?? null) ? $usage['kind'] : null,
                'label' => is_string($usage['label'] ?? null) ? $usage['label'] : null,
            ];
        }

        return $usages;
    }

    /**
     * 선언이 준 값을 호출체로 바꿉니다.
     *
     * @param  mixed  $candidate  클로저, `[클래스, 메서드]`, `__invoke` 를 가진 클래스명
     * @return callable|null 호출체 (쓸 수 없으면 null)
     */
    private function callable(mixed $candidate): ?callable
    {
        if (is_string($candidate) && class_exists($candidate)) {
            $candidate = app($candidate);
        }
        if (is_array($candidate) && count($candidate) === 2 && is_string($candidate[0]) && class_exists($candidate[0])) {
            $candidate = [app($candidate[0]), $candidate[1]];
        }

        return is_callable($candidate) ? $candidate : null;
    }
}
