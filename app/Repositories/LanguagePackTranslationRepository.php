<?php

namespace App\Repositories;

use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
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
 * 8 개 모델 (Permission/Role/Menu/Module/Plugin/Template/NotificationDefinition/
 * NotificationTemplate) 에 대한 영속
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
            // 플러그인 팩도 메뉴를 시드한다. 종전에는 plugin 스코프만 제외돼 있었는데, 플러그인은
            // 자기 메뉴를 심을 수 있으므로(실측 2026-09-12: `sirsoft-ckeditor5-uploads`,
            // `sirsoft-gdpr-consent-log`) 그 행만 영원히 번역 경로가 없었다 — ja 로케일에서
            // 좌측 메뉴 두 칸만 원문으로 남았다. 제외에 사유 주석이 없었고, 모듈 팩은 같은
            // 경로로 이미 메뉴를 시드하고 있어 위험도 차이도 없다.
            if (! empty($seedBundle['menus'])) {
                $this->applyByIdentifier(Menu::class, $pack, $seedBundle['menus'], $locale, ['name'], 'slug', $audit);
            }
            if (! empty($seedBundle['notifications'])) {
                $this->applyNotifications($pack, $seedBundle['notifications'], $locale, $audit);
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

        // 확장에 내장된 언어팩(ko·en)은 그 확장 매니페스트 자신의 언어다. 확장을 끌 때 함께 꺼지는데,
        // 여기서 그 언어 키를 지우면 다시 켜도 채울 seed 가 없어 확장 이름·설명이 `[]` 로 남는다.
        if ($pack->source_type === LanguagePackSourceType::BundledWithExtension->value) {
            return $audit;
        }

        // 모듈·플러그인 범위 팩은 활성화 때 확장 행뿐 아니라 그 확장이 소유한 권한·역할·메뉴·알림에도
        // 로케일을 넣는다(applySeedFromPack). 종전에는 확장 행만 걷어, 팩을 끈 뒤에도 그 문구가 남았다.
        if (in_array($pack->scope, [LanguagePackScope::Module->value, LanguagePackScope::Plugin->value], true)
            && $pack->target_identifier) {
            $this->stripExtensionOwnedRows($pack, $locale, $audit);
        }

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

        return $audit;
    }

    /**
     * 모듈·플러그인 범위 팩이 그 확장 소유 권한·역할·메뉴·알림 정의·알림 템플릿에 넣은 로케일을 걷습니다.
     *
     * 소유 판정은 주입(applyByIdentifier·applyNotifications)과 같은 기준(extension_type +
     * extension_identifier)이다. 운영자 수정(user_overrides 의 `컬럼.로케일`)은 stripLocaleColumns 가 보존한다.
     *
     * @param  LanguagePack  $pack  비활성화된 모듈·플러그인 범위 언어팩
     * @param  string  $locale  제거할 로케일
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
     */
    protected function stripExtensionOwnedRows(LanguagePack $pack, string $locale, array &$audit): void
    {
        $owned = [
            Permission::class => ['name', 'description'],
            Role::class => ['name', 'description'],
            Menu::class => ['name'],
            NotificationDefinition::class => ['name', 'description'],
        ];

        foreach ($owned as $modelClass => $columns) {
            $query = $modelClass::query();
            $this->scopeOwnership($query, $pack);

            $query->get()->each(function (Model $row) use ($columns, $locale, &$audit) {
                $this->stripLocaleColumns($row, $locale, $columns, $audit);
            });
        }

        $definitionQuery = NotificationDefinition::query();
        $this->scopeOwnership($definitionQuery, $pack);
        $definitionIds = $definitionQuery->pluck('id');

        if ($definitionIds->isEmpty()) {
            return;
        }

        NotificationTemplate::query()
            ->whereIn('definition_id', $definitionIds)
            ->get()
            ->each(function (NotificationTemplate $tpl) use ($locale, &$audit) {
                $this->stripLocaleColumns($tpl, $locale, ['subject', 'body'], $audit);
            });
    }

    /**
     * 식별자 컬럼 (identifier/slug) 기반 단순 매칭 모델의 JSON 컬럼에 locale 키를 병합합니다.
     *
     * @param  class-string<Model>  $modelClass  대상 모델 클래스
     * @param  LanguagePack  $pack  적용할 언어팩
     * @param  array<string, array<string, mixed>>  $seed  식별자 → 컬럼별 번역 맵
     * @param  string  $locale  적용할 로케일
     * @param  array<int, string>  $columns  병합 대상 다국어 컬럼
     * @param  string  $matchColumn  seed 키와 대조할 컬럼 (identifier/slug)
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
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
     * @param  LanguagePack  $pack  적용할 언어팩
     * @param  array<string, array<string, mixed>>  $seed  정의 키 → 컬럼별 번역 맵
     * @param  string  $locale  적용할 로케일
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
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
     * 확장 manifest 의 name/description JSON 컬럼에 locale 키를 병합합니다.
     *
     * @param  LanguagePack  $pack  적용할 언어팩
     * @param  array<string, mixed>  $seed  name/description 평탄 번역 맵
     * @param  string  $locale  적용할 로케일
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
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
     * 쿼리 빌더에 언어팩 scope 별 소유권 필터를 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리 빌더
     * @param  LanguagePack  $pack  소유권 기준이 될 언어팩
     * @return void 반환값 없음
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
     * 단일 row 의 다국어 컬럼들에 locale 키를 병합하고 audit 항목을 누적합니다.
     *
     * @param  Model  $row  대상 레코드
     * @param  array<string, mixed>  $entry  컬럼별 번역 값
     * @param  string  $locale  적용할 로케일
     * @param  array<int, string>  $columns  병합 대상 다국어 컬럼
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
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
     * @param  Model  $row  대상 레코드
     * @param  string  $locale  제거할 로케일
     * @param  array<int, string>  $columns  제거 대상 다국어 컬럼
     * @param  array<int, array<string, mixed>>  $audit  감사 항목 누적 배열
     * @return void 반환값 없음
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
