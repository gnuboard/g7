<?php

namespace App\Services;

use App\Exceptions\SettingsConsoleException;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Extension\Support\ComputedSettingKeys;
use App\Http\Requests\Admin\UpdatePluginSettingsRequest;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Http\Requests\Settings\UpdateSettingRequest;
use App\Support\EnvPriority;
use App\Support\FormRequestRunner;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * 터미널 설정 명령(`settings:get`·`settings:set`)의 조회·저장 — 관리자 화면 저장과 같은 FormRequest·같은 서비스.
 *
 * - 코어: 범주가 화면 탭에 속하면 그 탭의 현재 값 + 변경 1건을 `SaveSettingsRequest` 로 검증해
 *   `SettingsService::saveSettings()`(화면 「저장」과 같은 경로), 탭이 없으면 `UpdateSettingRequest` →
 *   `SettingsService::setSetting()`(단건 경로).
 * - 플러그인: 현재 값 + 변경을 `UpdatePluginSettingsRequest` 로 검증해 바뀐 키만 `PluginSettingsService::save()`.
 * - 모듈: 모듈 설정 서비스의 선택 메서드 `settingsSaveRequestClass()` 가 돌려주는 FormRequest 로 검증해
 *   그 서비스의 `saveSettings()` 와 코어 훅 `core.module_settings.after_save`(화면 저장과 같은 훅). 메서드가 없는
 *   모듈은 거부한다. 기본값 파일에 없는 키(DB 로 관리하는 목록 등 화면 전용 저장)는 「관리자 화면 전용」이다.
 *
 * 권한 검사(`authorize()`·권한 미들웨어)는 없다 — 셸 접근이 곧 권한이다.
 */
class SettingsConsoleService
{
    /** 민감 값 표시 */
    public const MASK = '********';

    /**
     * @param  SettingsService  $settingsService  코어 설정 저장
     * @param  SettingsCliKeyResolver  $resolver  코어 키 해석
     * @param  PluginManager  $pluginManager  플러그인 조회
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 저장
     * @param  ModuleSettingsService  $moduleSettings  모듈 설정 서비스 조회
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SettingsCliKeyResolver $resolver,
        private readonly PluginManager $pluginManager,
        private readonly PluginSettingsService $pluginSettings,
        private readonly ModuleSettingsService $moduleSettings,
    ) {}

    /**
     * 키가 민감 값인지 (값 입력 채널·출력 가림 판정용).
     *
     * @param  string  $key  설정 키
     * @param  string|null  $plugin  플러그인 식별자
     * @param  string|null  $module  모듈 식별자
     * @return bool 민감 값이면 true
     */
    public function isSensitive(string $key, ?string $plugin = null, ?string $module = null): bool
    {
        $segments = explode('.', $key);

        if ($plugin !== null) {
            $schema = $this->pluginManager->getPlugin($plugin)?->getSettingsSchema() ?? [];

            return ! empty($schema[$segments[0]]['sensitive']) || $this->sensitiveByName(end($segments));
        }

        if ($module !== null || count($segments) < 2) {
            return $this->sensitiveByName(end($segments));
        }

        return $this->resolver->isSensitive($segments[0], $segments[1]);
    }

    /**
     * 현재 값 (변경 전 값의 형태로 입력 문자열을 바꾸는 데 쓴다).
     *
     * @param  string  $key  설정 키
     * @param  string|null  $plugin  플러그인 식별자
     * @param  string|null  $module  모듈 식별자
     * @return mixed 현재 값 (없으면 null)
     */
    public function currentValue(string $key, ?string $plugin = null, ?string $module = null): mixed
    {
        if ($plugin !== null) {
            return $this->lookup((array) $this->pluginSettings->get($plugin), $key);
        }

        if ($module !== null) {
            return $this->lookup((array) $this->moduleSettings->get($module), $key);
        }

        return $this->settingsService->getSetting($key);
    }

    /**
     * 설정을 저장한다.
     *
     * @param  string  $key  설정 키
     * @param  mixed  $value  새 값 (형태 변환을 마친 값)
     * @param  string|null  $plugin  플러그인 식별자
     * @param  string|null  $module  모듈 식별자
     * @return array{before: mixed, after: mixed} 저장 전후 값
     *
     * @throws SettingsConsoleException 저장 전에 거부한 경우
     * @throws ValidationException 화면과 같은 검증에 실패한 경우
     */
    public function set(string $key, mixed $value, ?string $plugin = null, ?string $module = null): array
    {
        if ($plugin !== null && $module !== null) {
            throw new SettingsConsoleException(__('settings.console.cli.scope_conflict'));
        }

        $before = $this->currentValue($key, $plugin, $module);

        match (true) {
            $plugin !== null => $this->setPluginSetting($plugin, $key, $value),
            $module !== null => $this->setModuleSetting($module, $key, $value),
            default => $this->setCoreSetting($key, $value),
        };

        return ['before' => $before, 'after' => $this->freshValue($key, $plugin, $module)];
    }

    /**
     * 조회 — 키가 없으면 전체, 범주(또는 플러그인 최상위 키)만 주면 그 아래 전부.
     *
     * @param  string|null  $key  설정 키
     * @param  string|null  $plugin  플러그인 식별자
     * @param  string|null  $module  모듈 식별자
     * @param  bool  $showSecrets  민감 값을 가리지 않을지
     * @return array<string, mixed> 「키 => 표시 값」 (민감 값은 가림, 계산·.env 고정 표시 포함)
     *
     * @throws SettingsConsoleException 키·대상을 찾을 수 없는 경우
     */
    public function get(?string $key, ?string $plugin = null, ?string $module = null, bool $showSecrets = false): array
    {
        if ($plugin !== null && $module !== null) {
            throw new SettingsConsoleException(__('settings.console.cli.scope_conflict'));
        }

        if ($plugin !== null) {
            $instance = $this->pluginManager->getPlugin($plugin)
                ?? throw new SettingsConsoleException(__('settings.console.cli.plugin_not_found', ['id' => $plugin]));
            $all = (array) $this->pluginSettings->get($plugin);
            $computed = ComputedSettingKeys::of($instance);
        } elseif ($module !== null) {
            $all = (array) $this->moduleSettings->get($module);
            $computed = [];
        } else {
            $all = [];
            $presented = null;
            foreach ($this->resolver->categories() as $category) {
                $all[$category] = $this->settingsService->getSettings($category);

                // 계산 항목은 저장 파일에 없다 — 화면 응답이 계산해 싣는 값을 그대로 보인다.
                foreach ($this->resolver->computedFields($category) as $field => $frontendField) {
                    $presented ??= $this->settingsService->getAllSettings();
                    $all[$category][$field] = $presented[$category][$frontendField] ?? null;
                }
            }
            $computed = [];
        }

        $path = $key === null ? null : $this->resolvePath($all, $key);
        $selected = $key === null ? $all : ($path !== null ? [$key => $this->valueAt($all, $path)] : null);
        if ($selected === null) {
            throw new SettingsConsoleException(__('settings.console.cli.unknown_key', ['key' => $key]));
        }

        $rows = [];
        foreach ($selected as $topKey => $value) {
            $this->flatten((string) $topKey, $value, $rows, $key === null || ! is_array($value) ? 1 : 2);
        }

        $display = [];
        foreach ($rows as $rowKey => $value) {
            $segments = explode('.', $rowKey);
            $isComputed = $plugin !== null
                ? in_array($segments[0], $computed, true)
                : ($module === null && count($segments) >= 2 && $this->resolver->isComputed($segments[0], $segments[1]));

            if ($isComputed) {
                $display[$rowKey] = ['value' => $value, 'note' => __('settings.console.cli.computed_marker')];

                continue;
            }

            $sensitive = $this->isSensitive($rowKey, $plugin, $module);
            $display[$rowKey] = [
                'value' => $sensitive && ! $showSecrets && $value !== null && $value !== '' ? self::MASK : $value,
                'note' => $plugin === null && $module === null && count($segments) >= 2 && EnvPriority::isLocked($segments[0].'.'.$segments[1])
                    ? __('settings.console.cli.env_locked_marker')
                    : null,
            ];
        }

        return $display;
    }

    /**
     * 코어 설정 저장 — 탭 경로 또는 단건 경로.
     *
     * @param  string  $key  저장소 키
     * @param  mixed  $value  새 값
     * @return void 반환값 없음
     */
    private function setCoreSetting(string $key, mixed $value): void
    {
        $resolved = $this->resolver->resolve($key)
            ?? throw new SettingsConsoleException(__('settings.console.cli.key_format', ['key' => $key]));
        [$category, $field] = [$resolved['category'], $resolved['field']];

        // 계산 항목은 기본값 파일에 없으므로 「알 수 없는 키」보다 먼저 판정한다.
        if ($this->resolver->isComputed($category, $field)) {
            throw new SettingsConsoleException(__('settings.console.cli.computed_key', ['key' => $key]));
        }

        if (! $resolved['known']) {
            throw new SettingsConsoleException(__('settings.console.cli.unknown_key', ['key' => $key]));
        }

        if (EnvPriority::isLocked($category.'.'.$field)) {
            throw new SettingsConsoleException(__('settings.console.cli.env_locked', [
                'key' => $key,
                'env' => implode(', ', EnvPriority::MAP[$category.'.'.$field]['env'] ?? []),
            ]));
        }

        $tab = $resolved['tab'];
        if ($tab === null) {
            $validated = FormRequestRunner::validate(UpdateSettingRequest::class, ['value' => $value], ['key' => $key], 'PUT', '/api/admin/settings/'.$key);
            $this->assertSaved($this->settingsService->setSetting($key, $validated['value'] ?? null));

            return;
        }

        // 화면이 그 탭에서 「저장」을 누를 때와 같은 본문 — 현재 탭 값 전부 + 바뀐 1건.
        $all = $this->settingsService->getAllSettings();
        $tabValues = is_array($all[$tab] ?? null) ? $all[$tab] : [];
        Arr::set($tabValues, $resolved['frontend_field'].($resolved['rest'] !== null ? '.'.$resolved['rest'] : ''), $value);

        $request = FormRequestRunner::run(SaveSettingsRequest::class, ['_tab' => $tab, $tab => $tabValues], [], 'POST', '/api/admin/settings');
        $this->assertSaved($this->settingsService->saveSettings($request->validated()));
    }

    /**
     * 플러그인 설정 저장 — 현재 값 + 변경으로 검증하고 바뀐 최상위 키만 저장한다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $key  설정 키 (최상위 키, 배열 하위는 점 표기)
     * @param  mixed  $value  새 값
     * @return void 반환값 없음
     */
    private function setPluginSetting(string $identifier, string $key, mixed $value): void
    {
        $plugin = $this->pluginManager->getPlugin($identifier)
            ?? throw new SettingsConsoleException(__('settings.console.cli.plugin_not_found', ['id' => $identifier]));
        $field = explode('.', $key)[0];

        if (! array_key_exists($field, $plugin->getSettingsSchema())) {
            throw new SettingsConsoleException(__('settings.console.cli.plugin_unknown_key', ['id' => $identifier, 'key' => $key]));
        }

        $computed = ComputedSettingKeys::of($plugin);
        if (in_array($field, $computed, true)) {
            throw new SettingsConsoleException(__('settings.console.cli.computed_key', ['key' => $key]));
        }

        $payload = ComputedSettingKeys::strip((array) $this->pluginSettings->get($identifier), $computed);
        $this->putAt($payload, $this->resolvePath($payload, $key) ?? explode('.', $key), $value);

        $validated = FormRequestRunner::validate(UpdatePluginSettingsRequest::class, $payload, ['identifier' => $identifier], 'PUT', '/api/admin/plugins/'.$identifier.'/settings');

        $reason = null;
        if (! $this->pluginSettings->save($identifier, [$field => $validated[$field] ?? null], $reason)) {
            throw new SettingsConsoleException(__('settings.console.cli.save_failed', ['reason' => (string) $reason]));
        }
    }

    /**
     * 모듈 설정 저장 — 모듈이 지정한 관리자 저장 FormRequest 로 검증하고 그 범주만 저장한다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $key  설정 키 (`범주.항목[.하위]`)
     * @param  mixed  $value  새 값
     * @return void 반환값 없음
     */
    private function setModuleSetting(string $identifier, string $key, mixed $value): void
    {
        $service = $this->moduleSettings->resolveModuleService($identifier);
        if ($service === null || ! method_exists($service, 'settingsSaveRequestClass')) {
            throw new SettingsConsoleException(__('settings.console.cli.module_unsupported', ['id' => $identifier]));
        }

        $segments = explode('.', $key, 2);
        if (count($segments) < 2 || $segments[0] === '' || $segments[1] === '') {
            throw new SettingsConsoleException(__('settings.console.cli.key_format', ['key' => $key]));
        }
        $category = $segments[0];

        // 기본값 파일에 선언된 키만 설정 파일이 소유한다 — 그 밖(DB 로 관리하는 목록 등)은 화면의 전용 저장 경로가 있다.
        $defaults = $this->moduleDefaults($service->getSettingsDefaultsPath());
        if (! is_array($defaults[$category] ?? null)) {
            throw new SettingsConsoleException(__('settings.console.cli.unknown_key', ['key' => $key]));
        }
        // 항목 이름에 점이 든 키(권한 `posts.read` 등)는 기본값 파일에서 찾은 실제 경로로 지정한다.
        $resolved = $this->resolvePath($defaults, $key);
        if ($resolved === null || $resolved[0] !== $category) {
            throw new SettingsConsoleException(__('settings.console.cli.admin_screen_only', ['key' => $key]));
        }

        $current = $service->getSettings($category);
        $this->putAt($current, array_slice($resolved, 1), $value);

        $request = FormRequestRunner::run($service->settingsSaveRequestClass(), [$category => $current], [], 'PUT', '/');
        $validated = method_exists($request, 'validatedSettings') ? $request->validatedSettings() : $request->validated();

        if ($this->resolvePath((array) $validated, $key) === null) {
            throw new SettingsConsoleException(__('settings.console.cli.admin_screen_only', ['key' => $key]));
        }

        $settings = [$category => array_intersect_key((array) $validated[$category], $defaults[$category])];
        $result = $service->saveSettings($settings);

        // 화면 저장과 같은 코어 훅 — 활동 로그·SEO 캐시 무효화 등이 구독한다.
        HookManager::doAction('core.module_settings.after_save', $identifier, $settings, $result);

        $this->assertSaved($result);
    }

    /**
     * 저장 직후 값을 다시 읽는다 (서비스 캐시를 거치지 않게 새 인스턴스로).
     *
     * @param  string  $key  설정 키
     * @param  string|null  $plugin  플러그인 식별자
     * @param  string|null  $module  모듈 식별자
     * @return mixed 저장된 값
     */
    private function freshValue(string $key, ?string $plugin, ?string $module): mixed
    {
        if ($plugin !== null) {
            $this->pluginSettings->clearCache($plugin);
        }
        if ($module !== null) {
            $this->moduleSettings->clearCache($module);
        }

        return $this->currentValue($key, $plugin, $module);
    }

    /**
     * 모듈 기본값 파일의 `defaults` 절.
     *
     * @param  string|null  $path  기본값 파일 경로
     * @return array<string, mixed> 범주별 기본값
     */
    private function moduleDefaults(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? (array) ($data['defaults'] ?? $data) : [];
    }

    /**
     * 배열 값을 「점 표기 키 => 값」 행으로 펼친다 (지정 깊이까지, 그 아래는 값 그대로).
     *
     * @param  string  $prefix  키 접두
     * @param  mixed  $value  값
     * @param  array<string, mixed>  $rows  결과 행
     * @param  int  $depth  더 펼칠 깊이
     * @return void 반환값 없음
     */
    private function flatten(string $prefix, mixed $value, array &$rows, int $depth): void
    {
        if ($depth > 0 && is_array($value) && $value !== [] && ! array_is_list($value)) {
            foreach ($value as $childKey => $childValue) {
                $this->flatten($prefix.'.'.$childKey, $childValue, $rows, $depth - 1);
            }

            return;
        }

        $rows[$prefix] = $value;
    }

    /**
     * 이름만으로 민감 값인지 (스키마 표시가 없는 모듈·하위 키용 — 코어 키 해석과 같은 규칙).
     *
     * @param  string  $name  항목 이름
     * @return bool 민감 값이면 true
     */
    private function sensitiveByName(string $name): bool
    {
        return SettingsCliKeyResolver::isSensitiveName($name);
    }

    /**
     * 점 표기 키를 실제 배열 경로로 푼다 — 항목 이름 자체에 점이 든 경우(게시판 기본 권한 `posts.read` 등)도
     * 그 이름 그대로 찾는다. 조각을 짧은 것부터 이어 붙여 보며, 막히면 더 긴 이름으로 되돌아가 다시 본다.
     *
     * @param  array<string, mixed>  $data  설정 값
     * @param  string  $key  점 표기 키
     * @return list<string>|null 경로 조각 (없는 키면 null)
     */
    private function resolvePath(array $data, string $key): ?array
    {
        return $this->walkPath($data, explode('.', $key));
    }

    /**
     * {@see resolvePath()} 의 재귀 본문.
     *
     * @param  mixed  $node  현재 위치의 값
     * @param  list<string>  $segments  남은 조각
     * @return list<string>|null 남은 경로 (찾지 못하면 null)
     */
    private function walkPath(mixed $node, array $segments): ?array
    {
        if ($segments === []) {
            return [];
        }
        if (! is_array($node)) {
            return null;
        }

        for ($length = 1, $count = count($segments); $length <= $count; $length++) {
            $name = implode('.', array_slice($segments, 0, $length));
            if (! array_key_exists($name, $node)) {
                continue;
            }

            $rest = $this->walkPath($node[$name], array_slice($segments, $length));
            if ($rest !== null) {
                return [$name, ...$rest];
            }
        }

        return null;
    }

    /**
     * 경로 조각으로 값을 읽는다.
     *
     * @param  array<string, mixed>  $data  설정 값
     * @param  list<string>  $path  경로 조각
     * @return mixed 값
     */
    private function valueAt(array $data, array $path): mixed
    {
        $node = $data;
        foreach ($path as $name) {
            $node = is_array($node) ? ($node[$name] ?? null) : null;
        }

        return $node;
    }

    /**
     * 경로 조각 위치에 값을 넣는다 (중간 배열이 없으면 만든다 — 조각 이름의 점은 나누지 않는다).
     *
     * @param  array<string, mixed>  $data  설정 값
     * @param  list<string>  $path  경로 조각
     * @param  mixed  $value  넣을 값
     * @return void 반환값 없음
     */
    private function putAt(array &$data, array $path, mixed $value): void
    {
        $node = &$data;
        foreach ($path as $name) {
            if (! isset($node[$name]) || ! is_array($node[$name])) {
                $node[$name] = [];
            }
            $node = &$node[$name];
        }
        $node = $value;
    }

    /**
     * 키의 값 — 이름에 점이 든 항목도 찾는다 (없으면 null).
     *
     * @param  array<string, mixed>  $data  설정 값
     * @param  string  $key  점 표기 키
     * @return mixed 값
     */
    private function lookup(array $data, string $key): mixed
    {
        $path = $this->resolvePath($data, $key);

        return $path === null ? null : $this->valueAt($data, $path);
    }

    /**
     * 저장 결과가 실패면 거부로 바꾼다.
     *
     * @param  bool  $result  저장 결과
     * @return void 반환값 없음
     */
    private function assertSaved(bool $result): void
    {
        if (! $result) {
            throw new SettingsConsoleException(__('settings.console.cli.save_failed', ['reason' => __('settings.save_failed')]));
        }
    }
}
