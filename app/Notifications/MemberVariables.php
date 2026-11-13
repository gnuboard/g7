<?php

namespace App\Notifications;

use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 알림 템플릿의 회원 항목 변수(받는 회원의 값).
 *
 * | 변수 | 값 |
 * | --- | --- |
 * | `{member.display_name}` | 받는 사람의 그 채널 표시 이름 (`{recipient_name}` 과 같은 값) |
 * | `{member.<키>}` | 코어와 확장이 선언한 회원 항목의 값 (예: `{member.nickname}`) |
 * | `{member.custom.<키>}` | 운영자가 추가한 회원 항목의 값 |
 *
 * 규칙:
 *
 * - 템플릿의 제목, 본문, 이동 주소가 실제로 참조하는 변수만 채운다. 참조가 없으면 아무것도 읽지 않는다.
 * - 값은 수신자 묶음마다 한 번에 읽는다(수신자 수만큼 쿼리하지 않는다).
 * - 「관리자만 열람」 항목과 파일 항목은 변수가 되지 않는다. 받는 사람에게 보이면 안 되는 값이다.
 * - 회원이 아닌 수신자는 `{member.display_name}` 에 그 수신자의 이름이 들어가고 나머지는 빈 글자다.
 * - 꺼졌거나 지워진 항목의 변수는 빈 글자로 채운다(자리표시자가 글자 그대로 나가지 않는다).
 *
 * @since 7.2.0
 */
class MemberVariables
{
    /** 회원 변수 접두 */
    public const PREFIX = 'member.';

    /** 운영자 추가 항목 변수 접두 */
    public const CUSTOM_PREFIX = 'member.custom.';

    /** 표시 이름 변수 */
    public const DISPLAY_NAME = 'member.display_name';

    /** 템플릿에서 회원 변수를 찾는 패턴 (`{member.…}`) */
    protected const PATTERN = '/\{(member\.[A-Za-z0-9_.]+)\}/';

    /**
     * 읽어 둔 값 — 회원 ID => (항목 키 => 원본 값).
     *
     * @var array<int|string, array<string, mixed>>
     */
    protected array $loaded = [];

    /**
     * 변수 이름 => 항목 정의 (null 이면 아직 만들지 않음).
     *
     * @var array<string, FieldDefinition>|null
     */
    protected ?array $definitions = null;

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueService  $values  항목 값 서비스
     * @param  RecipientDisplayName  $names  받는 사람 표시 이름
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldValueService $values,
        protected RecipientDisplayName $names,
    ) {}

    /**
     * 글에서 참조한 회원 변수 이름을 뽑습니다.
     *
     * @param  mixed  ...$texts  제목, 본문, 이동 주소 (문자열 또는 로케일별 문자열 배열)
     * @return array<int, string> 변수 이름 목록 (접두 포함, 중복 없음, 예: `member.custom.job`)
     */
    public function extract(mixed ...$texts): array
    {
        $found = [];
        foreach ($texts as $text) {
            foreach (is_array($text) ? $text : [$text] as $piece) {
                if (! is_string($piece) || ! str_contains($piece, '{'.self::PREFIX)) {
                    continue;
                }
                if (preg_match_all(self::PATTERN, $piece, $matches) > 0) {
                    foreach ($matches[1] as $name) {
                        $found[$name] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * 템플릿을 보낼 때 채워야 할 회원 변수 이름을 반환합니다.
     *
     * 코어는 템플릿의 제목, 본문, 이동 주소에서 뽑는다. 자기 템플릿을 코어가 읽지 못하는 확장 채널은
     * 필터 `core.notification.filter_member_variable_keys` 로 이름을 보탠다.
     *
     * @param  NotificationTemplate|null  $template  보낼 템플릿 (없으면 필터가 보탠 것만)
     * @param  string  $type  알림 종류
     * @param  string  $channel  채널 식별자
     * @return array<int, string> 변수 이름 목록
     */
    public function referencedKeys(?NotificationTemplate $template, string $type, string $channel): array
    {
        $keys = $template === null
            ? []
            : $this->extract($template->subject, $template->body, $template->click_url);

        $filtered = HookManager::applyFilters('core.notification.filter_member_variable_keys', $keys, $type, $channel);
        if (! is_array($filtered)) {
            return $keys;
        }

        return array_values(array_unique(array_filter(
            $filtered,
            static fn ($key) => is_string($key) && str_starts_with($key, self::PREFIX),
        )));
    }

    /**
     * 수신자 여럿의 회원 항목 값을 한 번에 읽어 둡니다.
     *
     * @param  iterable<int, object>  $recipients  수신자 목록 (회원이 아닌 수신자는 건너뛴다)
     * @param  array<int, string>  $names  채울 변수 이름 목록
     * @return void 반환값 없음
     */
    public function preload(iterable $recipients, array $names): void
    {
        $definitions = $this->definitionsFor($names);
        if ($definitions->isEmpty()) {
            return;
        }

        $users = new Collection;
        foreach ($recipients as $recipient) {
            if ($recipient instanceof User && $recipient->exists) {
                $users->put($recipient->getKey(), $recipient);
            }
        }
        if ($users->isEmpty()) {
            return;
        }

        try {
            foreach ($this->values->preload($users->values(), FieldSet::CORE_USER, $definitions) as $id => $row) {
                $this->loaded[$id] = array_merge($this->loaded[$id] ?? [], $row);
            }
        } catch (\Throwable $e) {
            // 변수 값 때문에 알림 자체가 막히면 안 된다 — 값 없이 보낸다.
            Log::warning('알림 회원 변수 값을 읽지 못했습니다', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 수신자 한 명의 회원 변수 값을 반환합니다.
     *
     * @param  object  $notifiable  수신자
     * @param  array<int, string>  $names  채울 변수 이름 목록
     * @param  string|null  $channel  채널 식별자 (표시 이름 영역 판정)
     * @return array<string, string> 변수 이름 => 값 (값이 없으면 빈 글자)
     */
    public function valuesFor(object $notifiable, array $names, ?string $channel = null): array
    {
        if ($names === []) {
            return [];
        }

        $definitions = $this->definitionMap();
        $isMember = $notifiable instanceof User && $notifiable->exists;
        $locale = BaseNotification::resolveNotifiableLocale($notifiable);

        $result = [];
        foreach ($names as $name) {
            if ($name === self::DISPLAY_NAME) {
                $result[$name] = (string) ($this->names->forChannel($notifiable, $channel) ?? '');

                continue;
            }

            $definition = $definitions[$name] ?? null;
            if ($definition === null || ! $isMember) {
                $result[$name] = '';

                continue;
            }

            $id = $notifiable->getKey();
            if (! array_key_exists($definition->key, $this->loaded[$id] ?? [])) {
                $this->preload([$notifiable], [$name]);
            }

            $value = $this->loaded[$id][$definition->key] ?? null;
            $result[$name] = $this->values->isEmpty($definition, $value)
                ? ''
                : (string) ($this->values->display($definition, $value, $locale) ?? '');
        }

        return $result;
    }

    /**
     * 템플릿 편집 화면에 보일 회원 공통 변수 목록을 반환합니다.
     *
     * @param  string|null  $locale  설명 문구의 로케일 (null 이면 현재 로케일)
     * @return array<int, array{key: string, description: string, group: string}> 변수 목록
     */
    public function catalog(?string $locale = null): array
    {
        $variables = [[
            'key' => self::DISPLAY_NAME,
            'description' => __('notification.member_variables.display_name', [], $locale),
            'group' => 'member',
        ]];

        foreach ($this->definitionMap() as $name => $definition) {
            $variables[] = [
                'key' => $name,
                'description' => __('notification.member_variables.field', [
                    'name' => $definition->localized('name', $locale) ?? $definition->key,
                ], $locale),
                'group' => 'member',
            ];
        }

        return $variables;
    }

    /**
     * 변수 이름 가운데 지금 쓸 수 없는 것을 반환합니다 (지워졌거나 꺼진 항목, 관리자만 열람 항목, 오타).
     *
     * 확장이 필터 `core.notification.filter_template_variables` 로 더한 변수는 쓸 수 있는 것으로 본다.
     *
     * @param  array<int, string>  $names  변수 이름 목록
     * @param  array<int, string>  $extraKnown  이 밖에 쓸 수 있는 변수 이름 (확장이 더한 것)
     * @return array<int, string> 쓸 수 없는 변수 이름 목록
     */
    public function unknown(array $names, array $extraKnown = []): array
    {
        $known = array_flip([self::DISPLAY_NAME, ...array_keys($this->definitionMap()), ...$extraKnown]);

        return array_values(array_filter($names, static fn (string $name) => ! isset($known[$name])));
    }

    /**
     * 읽어 둔 값과 항목 목록을 버립니다 (항목 설정이나 회원 값이 바뀐 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->loaded = [];
        $this->definitions = null;
    }

    /**
     * 변수 이름 => 항목 정의 맵을 반환합니다.
     *
     * 지금 쓰이는 회원 항목 가운데 「관리자만 열람」과 파일 항목을 뺀다.
     *
     * @return array<string, FieldDefinition> 변수 이름 => 항목 정의
     */
    protected function definitionMap(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $map = [];
        try {
            foreach ($this->registry->active(FieldSet::CORE_USER) as $definition) {
                if ($definition->edit_scope === FieldDefinition::SCOPE_ADMIN_ONLY || $this->values->isFileField($definition)) {
                    continue;
                }
                $map[($definition->isCustom() ? self::CUSTOM_PREFIX : self::PREFIX).$definition->key] = $definition;
            }
        } catch (\Throwable $e) {
            // 설치 전, 마이그레이션 전 구간에서는 항목 테이블이 없을 수 있다.
            Log::debug('알림 회원 변수: 회원 항목을 읽지 못했습니다', ['error' => $e->getMessage()]);

            return [];
        }

        return $this->definitions = $map;
    }

    /**
     * 변수 이름 목록에 해당하는 항목 정의를 반환합니다.
     *
     * @param  array<int, string>  $names  변수 이름 목록
     * @return Collection<int, FieldDefinition> 항목 정의 목록
     */
    protected function definitionsFor(array $names): Collection
    {
        $map = $this->definitionMap();
        $definitions = new Collection;
        foreach ($names as $name) {
            if (isset($map[$name])) {
                $definitions->put($name, $map[$name]);
            }
        }

        return $definitions->values();
    }
}
