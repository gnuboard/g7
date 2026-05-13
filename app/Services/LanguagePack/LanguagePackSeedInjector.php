<?php

namespace App\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Models\LanguagePack;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * HookManager 필터 리스너 — 시드 단계에서 다국어 키를 병합 주입.
 *
 * 시더가 helper 위임 직전에 applyFilters('core.permissions.config' 등)를 호출하면 본 리스너가
 * 활성 코어 언어팩의 seed/*.json 을 읽어 locale 키를 추가한 형태로 반환합니다.
 * 실제 user_overrides 보존 판정은 SyncDatabaseTranslations 리스너에서 수행하며,
 * 본 리스너는 시드 데이터 자체에 새 locale 키를 더하는 역할만 담당합니다.
 */
class LanguagePackSeedInjector
{
    /**
     * @param  LanguagePackRegistry  $registry  활성 언어팩 레지스트리
     */
    public function __construct(
        private readonly LanguagePackRegistry $registry,
    ) {}

    /**
     * core.permissions.config 필터 — config('core.permissions') 에 locale 키 병합.
     *
     * @param  array<string, mixed>  $config  permission 설정
     * @return array<string, mixed> locale 키가 보강된 설정
     */
    public function injectCorePermissions(array $config): array
    {
        foreach ($this->extraCoreLocales() as $locale) {
            $seed = $this->loadCoreSeed($locale, 'permissions');
            if (! $seed) {
                continue;
            }
            $config = $this->mergeIntoPermissionConfig($config, $seed, $locale);
        }

        return $config;
    }

    /**
     * core.roles.config 필터 — config('core.roles') 에 locale 키 병합.
     *
     * @param  array<int, mixed>  $roles  role 설정 배열
     * @return array<int, mixed> locale 키가 보강된 설정
     */
    public function injectCoreRoles(array $roles): array
    {
        foreach ($this->extraCoreLocales() as $locale) {
            $seed = $this->loadCoreSeed($locale, 'roles');
            if (! $seed) {
                continue;
            }
            foreach ($roles as $idx => $role) {
                $key = $role['identifier'] ?? null;
                if (! $key || ! isset($seed[$key])) {
                    continue;
                }
                foreach (['name', 'description'] as $field) {
                    if (isset($seed[$key][$field])) {
                        $roles[$idx][$field][$locale] = $seed[$key][$field];
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * core.menus.config 필터 — config('core.menus') 에 locale 키 병합.
     *
     * @param  array<int, mixed>  $menus  menu 설정
     * @return array<int, mixed> locale 키가 보강된 설정
     */
    public function injectCoreMenus(array $menus): array
    {
        foreach ($this->extraCoreLocales() as $locale) {
            $seed = $this->loadCoreSeed($locale, 'menus');
            if (! $seed) {
                continue;
            }
            foreach ($menus as $idx => $menu) {
                $slug = $menu['slug'] ?? null;
                if (! $slug || ! isset($seed[$slug]['name'])) {
                    continue;
                }
                $menus[$idx]['name'][$locale] = $seed[$slug]['name'];
            }
        }

        return $menus;
    }

    /**
     * seed.notifications.translations 필터 — Definition × Template 3-tier 병합.
     *
     * @param  array<int, mixed>  $definitions  알림 정의 배열
     * @return array<int, mixed> locale 키가 보강된 정의 배열
     */
    public function injectNotifications(array $definitions): array
    {
        foreach ($this->extraCoreLocales() as $locale) {
            $seed = $this->loadCoreSeed($locale, 'notifications');
            if (! $seed) {
                continue;
            }

            foreach ($definitions as $defIdx => $def) {
                $type = $def['type'] ?? null;
                if (! $type || ! isset($seed[$type])) {
                    continue;
                }
                $entry = $seed[$type];

                foreach (['name', 'description'] as $field) {
                    if (isset($entry['definition'][$field])) {
                        $definitions[$defIdx][$field][$locale] = $entry['definition'][$field];
                    }
                }

                $templates = $def['templates'] ?? [];
                foreach ($templates as $tplIdx => $tpl) {
                    $channel = $tpl['channel'] ?? null;
                    $tplSrc = $entry['templates'][$channel] ?? null;
                    if (! $channel || ! $tplSrc) {
                        continue;
                    }
                    foreach (['subject', 'body'] as $field) {
                        if (isset($tplSrc[$field])) {
                            $templates[$tplIdx][$field][$locale] = $tplSrc[$field];
                        }
                    }
                }
                $definitions[$defIdx]['templates'] = $templates;
            }
        }

        return $definitions;
    }

    /**
     * 확장(모듈/플러그인) 알림 시더용 주입기 — `seed.{target}.notifications.translations` 필터.
     *
     * 코어 알림과 동일한 Definition × Template 3-tier 구조를 가지지만 활성 팩 검색 범위가
     * 모듈/플러그인 + 동일 target_identifier 로 한정됩니다.
     *
     * @param  array<int, mixed>  $definitions  알림 정의 배열
     * @param  string  $targetIdentifier  대상 모듈/플러그인 식별자
     * @return array<int, mixed> locale 키가 보강된 정의 배열
     */
    public function injectExtensionNotifications(array $definitions, string $targetIdentifier): array
    {
        $packs = $this->registry->getActivePacks(LanguagePackScope::Module->value)
            ->merge($this->registry->getActivePacks(LanguagePackScope::Plugin->value))
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, 'notifications');
            if (! $seed) {
                continue;
            }
            foreach ($definitions as $defIdx => $def) {
                $type = $def['type'] ?? null;
                if (! $type || ! isset($seed[$type])) {
                    continue;
                }
                $entry = $seed[$type];

                foreach (['name', 'description'] as $field) {
                    if (isset($entry['definition'][$field])) {
                        $definitions[$defIdx][$field][$pack->locale] = $entry['definition'][$field];
                    }
                }

                $templates = $def['templates'] ?? [];
                foreach ($templates as $tplIdx => $tpl) {
                    $channel = $tpl['channel'] ?? null;
                    $tplSrc = $entry['templates'][$channel] ?? null;
                    if (! $channel || ! $tplSrc) {
                        continue;
                    }
                    foreach (['subject', 'body'] as $field) {
                        if (isset($tplSrc[$field])) {
                            $templates[$tplIdx][$field][$pack->locale] = $tplSrc[$field];
                        }
                    }
                }
                $definitions[$defIdx]['templates'] = $templates;
            }
        }

        return $definitions;
    }

    /**
     * seed.identity_messages.translations 필터 — IDV 메시지 정의의 다국어 키 병합.
     *
     * 코어 IDV 메시지(config/core.php::identity_messages)의 ko/en 다국어 데이터에
     * 활성 코어 언어팩의 seed/identity_messages.json 기반 추가 locale 키를 병합한다.
     *
     * config 의 array key (예: `mail.provider_default`, `mail.purpose.signup`)가 식별자로 사용되고,
     * seed JSON 도 동일 array key 를 사용한다. 병합 대상 필드:
     * - definition: name, description (배열)
     * - templates[].subject, templates[].body (배열)
     *
     * @param  array<int|string, mixed>  $definitions  identity_messages 정의 배열 (string-keyed 또는 numeric)
     * @return array<int|string, mixed> locale 키가 보강된 정의
     */
    public function injectIdentityMessages(array $definitions): array
    {
        foreach ($this->extraCoreLocales() as $locale) {
            $seed = $this->loadCoreSeed($locale, 'identity_messages');
            if (! $seed) {
                continue;
            }

            foreach ($definitions as $defKey => $def) {
                // config/core.php 는 string-keyed (예: 'mail.provider_default')
                // Seeder 는 그 키를 사용하므로 동일 키로 매칭.
                if (! isset($seed[$defKey])) {
                    continue;
                }
                $entry = $seed[$defKey];

                foreach (['name', 'description'] as $field) {
                    if (isset($entry['definition'][$field])) {
                        if (! isset($definitions[$defKey][$field]) || ! is_array($definitions[$defKey][$field])) {
                            $definitions[$defKey][$field] = [];
                        }
                        $definitions[$defKey][$field][$locale] = $entry['definition'][$field];
                    }
                }

                $templates = $def['templates'] ?? [];
                foreach ($templates as $tplIdx => $tpl) {
                    $channel = $tpl['channel'] ?? null;
                    $tplSrc = $entry['templates'][$channel] ?? null;
                    if (! $channel || ! $tplSrc) {
                        continue;
                    }
                    foreach (['subject', 'body'] as $field) {
                        if (isset($tplSrc[$field])) {
                            if (! isset($templates[$tplIdx][$field]) || ! is_array($templates[$tplIdx][$field])) {
                                $templates[$tplIdx][$field] = [];
                            }
                            $templates[$tplIdx][$field][$locale] = $tplSrc[$field];
                        }
                    }
                }
                $definitions[$defKey]['templates'] = $templates;
            }
        }

        return $definitions;
    }

    /**
     * 확장(모듈/플러그인) IDV 메시지 시더용 주입기 — `seed.{target}.identity_messages.translations` 필터.
     *
     * 코어 메시지와 동일한 definition × templates × channels 3-tier 구조이지만 활성 팩 검색 범위가
     * 모듈/플러그인 + 동일 target_identifier 로 한정된다. ModuleManager / PluginManager 의
     * syncModuleIdentityMessages / syncPluginIdentityMessages 가 호출.
     *
     * @param  array<int|string, mixed>  $definitions  IDV 메시지 정의 배열
     * @param  string  $targetIdentifier  대상 모듈/플러그인 식별자
     * @return array<int|string, mixed> locale 키가 보강된 정의
     */
    public function injectExtensionIdentityMessages(array $definitions, string $targetIdentifier): array
    {
        $packs = $this->registry->getActivePacks(LanguagePackScope::Module->value)
            ->merge($this->registry->getActivePacks(LanguagePackScope::Plugin->value))
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, 'identity_messages');
            if (! $seed) {
                continue;
            }
            foreach ($definitions as $defKey => $def) {
                // 모듈 측은 numeric-indexed 일 수 있으므로 provider_id+scope_type+scope_value 를 lookup 키로 합성.
                $compositeKey = $this->identityMessageCompositeKey($def);
                $matchKey = $compositeKey && isset($seed[$compositeKey])
                    ? $compositeKey
                    : (is_string($defKey) && isset($seed[$defKey]) ? $defKey : null);
                if (! $matchKey) {
                    continue;
                }
                $entry = $seed[$matchKey];

                foreach (['name', 'description'] as $field) {
                    if (isset($entry['definition'][$field])) {
                        if (! isset($definitions[$defKey][$field]) || ! is_array($definitions[$defKey][$field])) {
                            $definitions[$defKey][$field] = [];
                        }
                        $definitions[$defKey][$field][$pack->locale] = $entry['definition'][$field];
                    }
                }

                $templates = $def['templates'] ?? [];
                foreach ($templates as $tplIdx => $tpl) {
                    $channel = $tpl['channel'] ?? null;
                    $tplSrc = $entry['templates'][$channel] ?? null;
                    if (! $channel || ! $tplSrc) {
                        continue;
                    }
                    foreach (['subject', 'body'] as $field) {
                        if (isset($tplSrc[$field])) {
                            if (! isset($templates[$tplIdx][$field]) || ! is_array($templates[$tplIdx][$field])) {
                                $templates[$tplIdx][$field] = [];
                            }
                            $templates[$tplIdx][$field][$pack->locale] = $tplSrc[$field];
                        }
                    }
                }
                $definitions[$defKey]['templates'] = $templates;
            }
        }

        return $definitions;
    }

    /**
     * IDV 메시지 정의의 복합 키(provider_id × scope_type × scope_value) 를 합성한다.
     *
     * 모듈/플러그인 정의는 numeric-indexed 이므로 키 매칭 시 합성 키를 사용한다.
     * 코어 정의의 array key 패턴(`mail.provider_default`, `mail.purpose.{purpose}`)도 같은 형식.
     *
     * @param  array<string, mixed>  $def  IDV 메시지 정의
     * @return string|null 합성 키 또는 null (필수 필드 부재)
     */
    private function identityMessageCompositeKey(array $def): ?string
    {
        $provider = $def['provider_id'] ?? null;
        $scopeType = $def['scope_type'] ?? null;
        $scopeValue = $def['scope_value'] ?? '';
        if (! $provider || ! $scopeType) {
            return null;
        }
        // provider_id 의 콜론은 키에 부적합 — channel.scope_type.scope_value 형태로 합성.
        // 코어 array key 패턴 (`mail.provider_default`, `mail.purpose.signup`) 에 맞춘다.
        $channel = str_contains($provider, '.mail') ? 'mail' : 'sms';
        $suffix = $scopeValue ? "{$scopeType}.{$scopeValue}" : $scopeType;

        return "{$channel}.{$suffix}";
    }

    /**
     * 모듈 시더용 제네릭 주입기 — `seed.{vendor-module}.{entity}.translations` 필터.
     *
     * @param  array<int, mixed>  $entries  시드 데이터 배열
     * @param  string  $targetIdentifier  대상 모듈/플러그인 식별자
     * @param  string  $entity  시드 파일 이름 (확장자 제외)
     * @param  string  $matchKey  매칭 키 컬럼명 (code/slug/key)
     * @return array<int, mixed> locale 키가 보강된 시드 데이터
     */
    public function injectExtensionEntity(
        array $entries,
        string $targetIdentifier,
        string $entity,
        string $matchKey = 'code'
    ): array {
        $packs = $this->registry->getActivePacks(LanguagePackScope::Module->value)
            ->merge($this->registry->getActivePacks(LanguagePackScope::Plugin->value))
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, $entity);
            if (! $seed) {
                continue;
            }
            foreach ($entries as $idx => $entry) {
                $key = $entry[$matchKey] ?? null;
                if (! $key || ! isset($seed[$key])) {
                    continue;
                }
                foreach ($seed[$key] as $field => $value) {
                    if (is_array($entry[$field] ?? null)) {
                        $entries[$idx][$field][$pack->locale] = $value;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * `{type}.{id}.roles.translations` 필터 — 모듈/플러그인의 roles 다국어 필드에
     * 활성 언어팩의 roles seed 를 주입합니다.
     *
     * @param  array<int, mixed>  $roles  module->getRoles() / plugin->getRoles() 결과
     * @param  string  $targetIdentifier  모듈/플러그인 식별자
     * @param  string  $scope  module|plugin
     * @return array<int, mixed> locale 키가 보강된 roles 배열
     */
    public function injectExtensionRoles(array $roles, string $targetIdentifier, string $scope): array
    {
        $packs = $this->registry->getActivePacks($scope)
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, 'roles');
            if (! $seed) {
                continue;
            }
            foreach ($roles as $idx => $role) {
                $id = $role['identifier'] ?? null;
                if (! $id || ! isset($seed[$id])) {
                    continue;
                }
                foreach (['name', 'description'] as $field) {
                    if (isset($seed[$id][$field])) {
                        if (! isset($roles[$idx][$field]) || ! is_array($roles[$idx][$field])) {
                            $roles[$idx][$field] = [];
                        }
                        $roles[$idx][$field][$pack->locale] = $seed[$id][$field];
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * `{type}.{id}.permissions.translations` 필터 — 모듈/플러그인의 권한 트리에
     * 활성 언어팩의 permissions seed 를 주입합니다.
     *
     * 권한 트리는 module/categories/permissions 의 3단계 중첩 구조이며, 각 노드는
     * identifier + name/description 다국어 필드를 가집니다. seed 는 identifier ⇒ {name, description} 맵.
     *
     * @param  array<string, mixed>  $config  module->getPermissions() / plugin->getPermissions() 결과
     * @param  string  $targetIdentifier  모듈/플러그인 식별자
     * @param  string  $scope  module|plugin
     * @return array<string, mixed> locale 키가 보강된 권한 config
     */
    public function injectExtensionPermissions(array $config, string $targetIdentifier, string $scope): array
    {
        $packs = $this->registry->getActivePacks($scope)
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, 'permissions');
            if (! $seed) {
                continue;
            }
            $config = $this->mergePermissionTranslations($config, $seed, $pack->locale, $targetIdentifier);
        }

        return $config;
    }

    /**
     * 권한 config 트리를 walk 하며 seed 의 locale 번역을 name/description 에 병합.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $seed  identifier ⇒ {name, description} 맵
     * @param  string  $locale
     * @return array<string, mixed>
     */
    private function mergePermissionTranslations(array $config, array $seed, string $locale, ?string $targetIdentifier = null): array
    {
        // module 노드 (1레벨) — config 의 root name/description 이 모듈 자체 라벨.
        // seed key 는 모듈 식별자({target_identifier}) 와 동일.
        $modId = $targetIdentifier;
        if ($modId && isset($seed[$modId])) {
            foreach (['name', 'description'] as $field) {
                if (isset($seed[$modId][$field])) {
                    if (! isset($config[$field]) || ! is_array($config[$field])) {
                        $config[$field] = [];
                    }
                    $config[$field][$locale] = $seed[$modId][$field];
                }
            }
        }

        // categories (2레벨) — seed key 는 `{module-id}.{cat-id}` (DB FQ identifier)
        foreach (($config['categories'] ?? []) as $catIdx => $cat) {
            if (empty($cat['identifier']) || ! $modId) {
                continue;
            }
            $catFqi = "{$modId}.{$cat['identifier']}";
            if (isset($seed[$catFqi])) {
                foreach (['name', 'description'] as $field) {
                    if (isset($seed[$catFqi][$field])) {
                        if (! isset($config['categories'][$catIdx][$field])
                            || ! is_array($config['categories'][$catIdx][$field])) {
                            $config['categories'][$catIdx][$field] = [];
                        }
                        $config['categories'][$catIdx][$field][$locale] = $seed[$catFqi][$field];
                    }
                }
            }
            // permissions (3레벨) — seed key 는 `{module-id}.{cat-id}.{perm-id}`
            // 권한 노드의 식별자는 `action`(이커머스 등) 또는 `identifier`(명시적). 둘 중 존재 값 사용.
            foreach (($cat['permissions'] ?? []) as $pIdx => $perm) {
                $permId = $perm['action'] ?? $perm['identifier'] ?? null;
                if (! $permId) {
                    continue;
                }
                $permFqi = "{$catFqi}.{$permId}";
                if (isset($seed[$permFqi])) {
                    foreach (['name', 'description'] as $field) {
                        if (isset($seed[$permFqi][$field])) {
                            if (! isset($config['categories'][$catIdx]['permissions'][$pIdx][$field])
                                || ! is_array($config['categories'][$catIdx]['permissions'][$pIdx][$field])) {
                                $config['categories'][$catIdx]['permissions'][$pIdx][$field] = [];
                            }
                            $config['categories'][$catIdx]['permissions'][$pIdx][$field][$locale] = $seed[$permFqi][$field];
                        }
                    }
                }
            }
        }

        return $config;
    }

    /**
     * `module.{id}.admin_menus.translations` 필터 — 모듈의 admin_menus 다국어 필드에
     * 활성 모듈 언어팩의 menus seed 를 주입합니다.
     *
     * 메뉴는 children 으로 중첩되므로 재귀적으로 walk 합니다. 매칭은 `slug` 키 기준이며
     * seed 형식은 `{ slug: { name: 'ja translation' } }` 입니다.
     *
     * @param  array<int, mixed>  $menus  module->getAdminMenus() 결과
     * @param  string  $targetIdentifier  모듈 식별자
     * @return array<int, mixed> locale 키가 보강된 메뉴 배열
     */
    public function injectExtensionMenus(array $menus, string $targetIdentifier): array
    {
        $packs = $this->registry->getActivePacks(LanguagePackScope::Module->value)
            ->filter(fn (LanguagePack $pack) => $pack->target_identifier === $targetIdentifier);

        foreach ($packs as $pack) {
            $seed = $this->loadPackSeed($pack, 'menus');
            if (! $seed) {
                continue;
            }
            $menus = $this->mergeMenuTranslations($menus, $seed, $pack->locale);
        }

        return $menus;
    }

    /**
     * 메뉴 배열을 재귀적으로 walk 하며 seed 의 locale 번역을 name 필드에 병합합니다.
     *
     * @param  array<int, mixed>  $menus
     * @param  array<string, mixed>  $seed  slug ⇒ {name: ...} 맵
     * @param  string  $locale
     * @return array<int, mixed>
     */
    private function mergeMenuTranslations(array $menus, array $seed, string $locale): array
    {
        foreach ($menus as $idx => $menu) {
            $slug = $menu['slug'] ?? null;
            if ($slug && isset($seed[$slug]['name'])) {
                if (! isset($menus[$idx]['name']) || ! is_array($menus[$idx]['name'])) {
                    $menus[$idx]['name'] = [];
                }
                $menus[$idx]['name'][$locale] = $seed[$slug]['name'];
            }

            if (! empty($menu['children']) && is_array($menu['children'])) {
                $menus[$idx]['children'] = $this->mergeMenuTranslations($menu['children'], $seed, $locale);
            }
        }

        return $menus;
    }

    /**
     * 활성 코어 언어팩 중 fallback locale 외의 locale 목록.
     *
     * fallback locale 의 다국어 키는 이미 config/seed 에 포함되어 있으므로 추가 주입 대상이 아닙니다.
     * primary locale (현재 사용자 언어)은 다른 사용자가 볼 수도 있으므로 항상 주입 대상에 포함합니다.
     *
     * @return array<int, string> locale 문자열 배열
     */
    private function extraCoreLocales(): array
    {
        $fallback = config('app.fallback_locale', 'ko');

        return collect($this->registry->getActiveCoreLocales())
            ->reject(fn ($locale) => $locale === $fallback)
            ->values()
            ->all();
    }

    /**
     * 코어 언어팩 1건의 seed/{entity}.json 을 로드합니다.
     *
     * @param  string  $locale  로케일
     * @param  string  $entity  엔티티 이름 (permissions, roles, menus 등)
     * @return array<string, mixed>|null seed 데이터 또는 null
     */
    private function loadCoreSeed(string $locale, string $entity): ?array
    {
        $pack = $this->registry->getActivePackForSlot(LanguagePackScope::Core->value, null, $locale);
        if (! $pack) {
            return null;
        }

        return $this->loadPackSeed($pack, $entity);
    }

    /**
     * 언어팩 디렉토리의 seed/{entity}.json 을 읽어 배열로 반환합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  string  $entity  엔티티 이름
     * @return array<string, mixed>|null 파일 내용 또는 null
     */
    private function loadPackSeed(LanguagePack $pack, string $entity): ?array
    {
        $seedFile = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'seed'.DIRECTORY_SEPARATOR.$entity.'.json';

        if (! File::isFile($seedFile)) {
            return null;
        }

        $decoded = json_decode(File::get($seedFile), true);
        if (! is_array($decoded)) {
            Log::warning('language-pack seed JSON invalid', [
                'pack' => $pack->identifier,
                'file' => $seedFile,
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * config('core.permissions') 의 계층형 구조에 locale 키를 병합합니다.
     *
     * @param  array<string, mixed>  $config  permission 설정
     * @param  array<string, mixed>  $seed  seed JSON 데이터 (key ⇒ {name, description})
     * @param  string  $locale  로케일
     * @return array<string, mixed> 병합된 설정
     */
    private function mergeIntoPermissionConfig(array $config, array $seed, string $locale): array
    {
        if (isset($config['module']) && isset($seed[$config['module']['identifier'] ?? '_'])) {
            $entry = $seed[$config['module']['identifier']];
            foreach (['name', 'description'] as $field) {
                if (isset($entry[$field]) && is_array($config['module'][$field] ?? null)) {
                    $config['module'][$field][$locale] = $entry[$field];
                }
            }
        }

        $categories = $config['categories'] ?? [];
        foreach ($categories as $cIdx => $category) {
            $catId = $category['identifier'] ?? null;
            if ($catId && isset($seed[$catId])) {
                foreach (['name', 'description'] as $field) {
                    if (isset($seed[$catId][$field]) && is_array($category[$field] ?? null)) {
                        $categories[$cIdx][$field][$locale] = $seed[$catId][$field];
                    }
                }
            }

            $permissions = $category['permissions'] ?? [];
            foreach ($permissions as $pIdx => $perm) {
                $permId = $perm['identifier'] ?? null;
                if (! $permId || ! isset($seed[$permId])) {
                    continue;
                }
                foreach (['name', 'description'] as $field) {
                    if (isset($seed[$permId][$field]) && is_array($perm[$field] ?? null)) {
                        $permissions[$pIdx][$field][$locale] = $seed[$permId][$field];
                    }
                }
            }
            $categories[$cIdx]['permissions'] = $permissions;
        }
        $config['categories'] = $categories;

        return $config;
    }
}
