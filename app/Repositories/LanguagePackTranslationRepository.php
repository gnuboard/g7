<?php

namespace App\Repositories;

use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\Module;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Template;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 언어팩 활성/비활성 시 DB JSON 다국어 컬럼을 일괄 동기화하는 집계 Repository.
 *
 * 10 개 모델 (Permission/Role/Menu/Module/Plugin/Template/NotificationDefinition/
 * NotificationTemplate/IdentityMessageDefinition/IdentityMessageTemplate) 에 대한 영속
 * 책임을 단일 진입점으로 캡슐화. Listener/Service 는 본 Repository 만 호출.
 *
 * 동작 정책은 인터페이스 PHPDoc 참조.
 *
 * @since 7.0.0-beta.4
 */
class LanguagePackTranslationRepository implements LanguagePackTranslationRepositoryInterface
{
    /**
     * 언어팩의 seed/*.json 을 DB JSON 컬럼에 병합합니다.
     *
     * @param  LanguagePack  $pack  활성화된 언어팩
     * @param  array<string, array<string, mixed>>  $seedBundle  엔티티별 seed 데이터
     * @return array<int, array<string, mixed>> 감사 로그 항목
     */
    public function applySeedFromPack(LanguagePack $pack, array $seedBundle): array
    {
        // 팩 주입은 시딩 컨텍스트다 — 바인딩 없이 저장하면 UserOverridesAwareBuilder 가
        // 주입 자체를 "사용자 수정"으로 오인해 user_overrides 를 오염시키고, 그 선언이
        // 이후 시더 재실행의 컬럼 갱신을 영구히 얼린다 (#597 에서 실측·교정).
        app()->instance('user_overrides.seeding', true);
        try {
            $audit = [];
            $locale = $pack->locale;

            if (! empty($seedBundle['permissions'])) {
                $this->applyByIdentifier(Permission::class, $pack, $seedBundle['permissions'], $locale, ['name', 'description'], 'identifier', $audit);
            }
            if (! empty($seedBundle['roles'])) {
                $this->applyByIdentifier(Role::class, $pack, $seedBundle['roles'], $locale, ['name', 'description'], 'identifier', $audit);
            }
            if (! empty($seedBundle['menus']) && $pack->scope !== LanguagePackScope::Plugin->value) {
                $this->applyByIdentifier(Menu::class, $pack, $seedBundle['menus'], $locale, ['name'], 'slug', $audit);
            }
            if (! empty($seedBundle['notifications'])) {
                $this->applyNotifications($pack, $seedBundle['notifications'], $locale, $audit);
            }
            if (! empty($seedBundle['identity_messages'])) {
                $this->applyIdentityMessages($pack, $seedBundle['identity_messages'], $locale, $audit);
            }
            if (! empty($seedBundle['manifest'])) {
                $this->applyManifest($pack, $seedBundle['manifest'], $locale, $audit);
            }

            return $audit;
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    /**
     * 언어팩의 locale 키를 DB JSON 컬럼에서 제거합니다 (user_overrides 컬럼은 보존).
     *
     * @param  LanguagePack  $pack  비활성화된 언어팩
     * @return array<int, array<string, mixed>> 감사 로그 항목
     */
    public function stripLocaleFromPack(LanguagePack $pack): array
    {
        // applySeedFromPack 과 동일 — 제거도 시딩 컨텍스트 (user_overrides 오염 차단)
        app()->instance('user_overrides.seeding', true);
        try {
            return $this->stripLocaleFromPackBody($pack);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }
    }

    /**
     * stripLocaleFromPack 의 본문 (시딩 바인딩 하에서 실행).
     *
     * @param  LanguagePack  $pack  비활성화된 언어팩
     * @return array<int, array<string, mixed>> 감사 로그 항목
     */
    private function stripLocaleFromPackBody(LanguagePack $pack): array
    {
        $audit = [];
        $locale = $pack->locale;

        $modelMaps = [
            LanguagePackScope::Core->value => [
                Permission::class => ['name', 'description'],
                Role::class => ['name', 'description'],
                Menu::class => ['name'],
                NotificationDefinition::class => ['name', 'description'],
                NotificationTemplate::class => ['subject', 'body'],
            ],
            LanguagePackScope::Module->value => [Module::class => ['name', 'description']],
            LanguagePackScope::Plugin->value => [Plugin::class => ['name', 'description']],
            LanguagePackScope::Template->value => [Template::class => ['name', 'description']],
        ];

        $models = $modelMaps[$pack->scope] ?? [];

        foreach ($models as $modelClass => $columns) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $query = $modelClass::query();
            if (in_array($pack->scope, [
                LanguagePackScope::Module->value,
                LanguagePackScope::Plugin->value,
                LanguagePackScope::Template->value,
            ], true) && $pack->target_identifier) {
                $query->where('identifier', $pack->target_identifier);
            }

            $query->get()->each(function (Model $row) use ($columns, $locale, &$audit) {
                $this->stripLocaleColumns($row, $locale, $columns, $audit);
            });
        }

        $this->stripIdentityMessages($pack, $locale, $audit);

        return $audit;
    }

    /**
     * 식별자 컬럼 (identifier/slug) 기반 단순 매칭 모델의 JSON 컬럼에 locale 키를 병합합니다.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, array<string, mixed>>  $seed
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function applyByIdentifier(
        string $modelClass,
        LanguagePack $pack,
        array $seed,
        string $locale,
        array $columns,
        string $matchColumn,
        array &$audit,
    ): void {
        $query = $modelClass::query()->whereIn($matchColumn, array_keys($seed));
        $this->scopeOwnership($query, $pack);

        $query->get()->each(function (Model $row) use ($seed, $locale, $columns, $matchColumn, &$audit) {
            $key = $row->{$matchColumn};
            $entry = $seed[$key] ?? null;
            if (! $entry) {
                return;
            }
            $this->mergeLocaleColumns($row, $entry, $locale, $columns, $audit);
        });
    }

    /**
     * 알림 Definition × Template 의 다국어 컬럼을 병합합니다.
     *
     * @param  array<string, array<string, mixed>>  $seed
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function applyNotifications(LanguagePack $pack, array $seed, string $locale, array &$audit): void
    {
        if (! in_array($pack->scope, [
            LanguagePackScope::Core->value,
            LanguagePackScope::Module->value,
            LanguagePackScope::Plugin->value,
        ], true)) {
            return;
        }

        $defQuery = NotificationDefinition::query()->whereIn('type', array_keys($seed));
        if ($pack->target_identifier) {
            $extType = match ($pack->scope) {
                LanguagePackScope::Module->value => ExtensionOwnerType::Module->value,
                LanguagePackScope::Plugin->value => ExtensionOwnerType::Plugin->value,
                default => null,
            };
            if ($extType) {
                $defQuery->where('extension_type', $extType)
                    ->where('extension_identifier', $pack->target_identifier);
            }
        }

        $defQuery->get()->each(function (NotificationDefinition $def) use ($seed, $locale, &$audit) {
            $entry = $seed[$def->type] ?? null;
            if (! $entry) {
                return;
            }

            if (isset($entry['definition'])) {
                $this->mergeLocaleColumns($def, $entry['definition'], $locale, ['name', 'description'], $audit);
            }

            $templates = $entry['templates'] ?? [];
            if (empty($templates)) {
                return;
            }

            NotificationTemplate::query()
                ->where('definition_id', $def->id)
                ->whereIn('channel', array_keys($templates))
                ->get()
                ->each(function (NotificationTemplate $tpl) use ($templates, $locale, &$audit) {
                    $tplSeed = $templates[$tpl->channel] ?? null;
                    if (! $tplSeed) {
                        return;
                    }
                    $this->mergeLocaleColumns($tpl, $tplSeed, $locale, ['subject', 'body'], $audit);
                });
        });
    }

    /**
     * IDV 메시지 Definition × Template 의 다국어 컬럼을 병합합니다.
     *
     * @param  array<string, array<string, mixed>>  $seed
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function applyIdentityMessages(LanguagePack $pack, array $seed, string $locale, array &$audit): void
    {
        if (! in_array($pack->scope, [
            LanguagePackScope::Core->value,
            LanguagePackScope::Module->value,
            LanguagePackScope::Plugin->value,
        ], true)) {
            return;
        }

        $defQuery = IdentityMessageDefinition::query();
        if ($pack->scope === LanguagePackScope::Core->value) {
            $defQuery->where('extension_type', 'core')->where('extension_identifier', 'core');
        } else {
            $extType = $pack->scope === LanguagePackScope::Module->value ? 'module' : 'plugin';
            $defQuery->where('extension_type', $extType)
                ->where('extension_identifier', $pack->target_identifier);
        }

        $defQuery->get()->each(function (IdentityMessageDefinition $def) use ($seed, $locale, &$audit) {
            $compositeKey = $this->identityMessageCompositeKey($def);
            $entry = ($compositeKey && isset($seed[$compositeKey])) ? $seed[$compositeKey] : null;
            if (! $entry) {
                return;
            }

            if (isset($entry['definition'])) {
                $this->mergeLocaleColumns($def, $entry['definition'], $locale, ['name', 'description'], $audit);
            }

            $templates = $entry['templates'] ?? [];
            if (empty($templates)) {
                return;
            }

            IdentityMessageTemplate::query()
                ->where('definition_id', $def->id)
                ->whereIn('channel', array_keys($templates))
                ->get()
                ->each(function (IdentityMessageTemplate $tpl) use ($templates, $locale, &$audit) {
                    $tplSeed = $templates[$tpl->channel] ?? null;
                    if (! $tplSeed) {
                        return;
                    }
                    $this->mergeLocaleColumns($tpl, $tplSeed, $locale, ['subject', 'body'], $audit);
                });
        });
    }

    /**
     * 확장 manifest 의 name/description JSON 컬럼에 locale 키를 병합합니다.
     *
     * @param  array<string, mixed>  $seed
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function applyManifest(LanguagePack $pack, array $seed, string $locale, array &$audit): void
    {
        if (! in_array($pack->scope, [
            LanguagePackScope::Module->value,
            LanguagePackScope::Plugin->value,
            LanguagePackScope::Template->value,
        ], true) || ! $pack->target_identifier) {
            return;
        }

        $modelClass = match ($pack->scope) {
            LanguagePackScope::Module->value => Module::class,
            LanguagePackScope::Plugin->value => Plugin::class,
            LanguagePackScope::Template->value => Template::class,
            default => null,
        };

        if (! $modelClass || ! class_exists($modelClass)) {
            return;
        }

        $row = $modelClass::query()->where('identifier', $pack->target_identifier)->first();
        if (! $row) {
            return;
        }

        $this->mergeLocaleColumns($row, $seed, $locale, ['name', 'description'], $audit);
    }

    /**
     * 비활성화 시 IDV 메시지 Definition × Template 의 locale 키를 제거합니다.
     *
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function stripIdentityMessages(LanguagePack $pack, string $locale, array &$audit): void
    {
        if (! in_array($pack->scope, [
            LanguagePackScope::Core->value,
            LanguagePackScope::Module->value,
            LanguagePackScope::Plugin->value,
        ], true)) {
            return;
        }

        $defQuery = IdentityMessageDefinition::query();
        if ($pack->scope === LanguagePackScope::Core->value) {
            $defQuery->where('extension_type', 'core')->where('extension_identifier', 'core');
        } else {
            $extType = $pack->scope === LanguagePackScope::Module->value ? 'module' : 'plugin';
            $defQuery->where('extension_type', $extType)
                ->where('extension_identifier', $pack->target_identifier);
        }

        $defQuery->get()->each(function (IdentityMessageDefinition $def) use ($locale, &$audit) {
            $this->stripLocaleColumns($def, $locale, ['name', 'description'], $audit);

            IdentityMessageTemplate::query()
                ->where('definition_id', $def->id)
                ->get()
                ->each(function (IdentityMessageTemplate $tpl) use ($locale, &$audit) {
                    $this->stripLocaleColumns($tpl, $locale, ['subject', 'body'], $audit);
                });
        });
    }

    /**
     * 쿼리 빌더에 언어팩 scope 별 소유권 필터를 적용합니다.
     */
    protected function scopeOwnership(Builder $query, LanguagePack $pack): void
    {
        if ($pack->scope === LanguagePackScope::Core->value) {
            $query->where(function ($q) {
                $q->where('extension_type', ExtensionOwnerType::Core->value)
                    ->orWhereNull('extension_type');
            });

            return;
        }

        $type = match ($pack->scope) {
            LanguagePackScope::Module->value => ExtensionOwnerType::Module->value,
            LanguagePackScope::Plugin->value => ExtensionOwnerType::Plugin->value,
            default => null,
        };

        if ($type === null || empty($pack->target_identifier)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('extension_type', $type)
            ->where('extension_identifier', $pack->target_identifier);
    }

    /**
     * IdentityMessageDefinition 의 provider_id+scope_type+scope_value 를 seed 키 형식으로 합성합니다.
     *
     * LanguagePackSeedInjector::identityMessageCompositeKey 와 동일 규칙.
     */
    protected function identityMessageCompositeKey(IdentityMessageDefinition $def): ?string
    {
        $provider = $def->provider_id ?? null;
        $scopeType = is_object($def->scope_type) ? $def->scope_type->value : $def->scope_type;
        $scopeValue = $def->scope_value ?? '';
        if (! $provider || ! $scopeType) {
            return null;
        }
        $channel = str_contains($provider, '.mail') ? 'mail' : 'sms';
        $suffix = $scopeValue !== '' ? "{$scopeType}.{$scopeValue}" : $scopeType;

        return "{$channel}.{$suffix}";
    }

    /**
     * 단일 row 의 다국어 컬럼들에 locale 키를 병합하고 audit 항목을 누적합니다.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function mergeLocaleColumns(Model $row, array $entry, string $locale, array $columns, array &$audit): void
    {
        $overrides = (array) ($row->user_overrides ?? []);
        $changed = false;

        foreach ($columns as $column) {
            if (! array_key_exists($column, $entry)) {
                continue;
            }

            $current = $row->{$column};
            if (! is_array($current)) {
                $current = [];
            }

            $hasLocaleKey = array_key_exists($locale, $current);
            $isOverridden = $this->isColumnLocaleOverridden($overrides, $column, $locale);

            if ($hasLocaleKey && $isOverridden) {
                $audit[] = [
                    'action' => 'skipped',
                    'table' => $row->getTable(),
                    'id' => $row->getKey(),
                    'column' => $column,
                    'locale' => $locale,
                    'reason' => 'user_overrides',
                ];

                continue;
            }

            $current[$locale] = $entry[$column];
            $row->{$column} = $current;
            $changed = true;
        }

        if ($changed) {
            $row->saveQuietly();
        }
    }

    /**
     * 단일 row 의 다국어 컬럼들에서 locale 키를 제거하고 audit 항목을 누적합니다.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $audit
     */
    protected function stripLocaleColumns(Model $row, string $locale, array $columns, array &$audit): void
    {
        $overrides = (array) ($row->user_overrides ?? []);
        $changed = false;

        foreach ($columns as $column) {
            $current = $row->{$column};
            if (! is_array($current) || ! array_key_exists($locale, $current)) {
                continue;
            }

            if ($this->isColumnLocaleOverridden($overrides, $column, $locale)) {
                $audit[] = [
                    'action' => 'preserved',
                    'table' => $row->getTable(),
                    'id' => $row->getKey(),
                    'column' => $column,
                    'locale' => $locale,
                    'reason' => 'user_overrides',
                ];

                continue;
            }

            unset($current[$locale]);
            $row->{$column} = $current;
            $changed = true;
        }

        if ($changed) {
            $row->saveQuietly();
        }
    }

    /**
     * 해당 컬럼·로케일이 운영자 수정(user_overrides)으로 보존 대상인지 판정합니다.
     *
     * 운영자 수정의 실제 기록 형식은 dot-path 리스트(`"{column}.{locale}"` —
     * HasUserOverrides 의 updating 이벤트가 기록, beta.4 가 레거시 항목을 dot-path 로
     * 전환)이므로 그 형식만 본다. 종전의 `isset($overrides[$column])` 판정은 어떤
     * 생산자도 쓰지 않는 연관 형식을 전제해 항상 false 였고, 그 결과 활성화/비활성화가
     * 운영자 수정 번역을 오류 없이 덮어쓰거나 제거했다 (#597 에서 발견·교정).
     *
     * 컬럼 전체 항목(`"subject"` 등)은 의도적으로 보존 사유로 보지 않는다 — 그 형식은
     * 시더 재실행(syncFromUpgrade)의 컬럼 보호 선언이며, 팩 병합까지 차단하면 팩
     * 업데이트가 자기 로케일 번역을 갱신하지 못하게 된다 (종전에도 차단된 적 없음).
     *
     * @param  array<int, string>  $overrides  user_overrides 리스트
     * @param  string  $column  다국어 JSON 컬럼명
     * @param  string  $locale  대상 로케일
     * @return bool 보존 대상 여부
     */
    protected function isColumnLocaleOverridden(array $overrides, string $column, string $locale): bool
    {
        return in_array($column.'.'.$locale, $overrides, true);
    }
}
