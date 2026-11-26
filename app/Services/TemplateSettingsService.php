<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Template\TemplateMenu;
use App\Extension\Template\TemplateSettingsSchema;
use App\Helpers\InlineMediaPolicy;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Support\AssetUrl;
use App\Support\ExtensionSettingsMirror;
use App\Support\Favicon\FaviconPolicy;
use App\Support\LayoutMarkup\EmptyMap;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use App\Support\LocalizedValue;
use App\Support\SensitiveSettingMask;
use App\Support\SettingsVersion;
use App\Support\Template\TemplateSettingCollections;
use App\Traits\FiltersFrontendSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 환경설정 서비스
 *
 * 모듈 설정과 같은 모양이다 — 섹션(`_meta.categories`)별 기본값 위에 섹션별 저장 파일을 병합해 읽고, 저장은
 * 기존 값 위 병합이다. 다른 점은 템플릿에 PHP 인스턴스가 없어 선언(기본값·칸·노출)을 {@see TemplateSettingsSchema}
 * 가 파일에서 읽는다는 것이다.
 *
 * 저장 위치: `templates` 디스크의 `{identifier}/settings/{섹션}.json`(템플릿 디렉토리 밖이라 업데이트가 지우지 않는다).
 * 파일은 설치 때 만들지 않고 첫 저장 때 생긴다 — 그 전에는 기본값만 읽힌다.
 *
 * - 비밀값(`sensitive`) 칸은 파일에 암호화해 두고, 관리자 응답은 마스크, 프론트·미러에는 싣지 않는다(모듈·플러그인과 같음).
 * - 칸 이름을 바꾼 템플릿은 선언 `renamed_from` 으로 옛 값을 읽어 오고 다음 저장 때 새 자리에 쓴다(템플릿엔 업그레이드 스텝이 없다).
 * - 첨부 칸(attachments·image)은 첨부 ID 배열로 저장하고 그 칸 컬렉션(`template_settings.{템플릿}.{섹션}.{키}`)에 실재하는
 *   ID 만 받는다. 화면에서 뺀 첨부는 저장이 성공한 **뒤에** 파일까지 파기한다(코어 사이트 로고와 같은 규율).
 * - 계산 키: 파비콘 칸의 `branding.favicon_url`, 이미지 칸의 `{섹션}.{키}_url`.
 */
class TemplateSettingsService
{
    use FiltersFrontendSchema;

    /**
     * 저장 디스크
     */
    private const DISK = 'templates';

    /**
     * 요청 내 설정 캐시 (identifier => settings, 비밀값 복호화 상태)
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $settingsCache = [];

    /**
     * 요청 내 스키마 캐시 (identifier => schema|false)
     *
     * @var array<string, TemplateSettingsSchema|false>
     */
    private array $schemaCache = [];

    /**
     * 요청 내 라벨 언어 데이터 캐시 ("identifier|locale" => data)
     *
     * @var array<string, array<string, mixed>>
     */
    private array $languageCache = [];

    /**
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소
     * @param  AttachmentRepositoryInterface  $attachmentRepository  첨부 저장소
     * @param  LayoutService  $layoutService  레이아웃 서비스 (sanitize 재사용)
     */
    public function __construct(
        private TemplateRepositoryInterface $templateRepository,
        private AttachmentRepositoryInterface $attachmentRepository,
        private LayoutService $layoutService,
    ) {}

    /**
     * 템플릿의 설정 선언을 읽습니다 — 선언이 없거나 깨졌으면 null.
     *
     * 깨진 선언은 설치·업데이트 검증이 막지만, 설치 뒤 손으로 고친 파일이 깨졌을 때 화면 전체가 500 이 되지 않게
     * 여기서는 로그만 남기고 null 을 돌려준다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return TemplateSettingsSchema|null 선언
     */
    public function schema(string $identifier): ?TemplateSettingsSchema
    {
        if (! array_key_exists($identifier, $this->schemaCache)) {
            try {
                $this->schemaCache[$identifier] = TemplateSettingsSchema::load($identifier) ?? false;
            } catch (\InvalidArgumentException $e) {
                Log::error('템플릿 설정 선언 해석 실패', ['template' => $identifier, 'error' => $e->getMessage()]);
                $this->schemaCache[$identifier] = false;
            }
        }

        return $this->schemaCache[$identifier] ?: null;
    }

    /**
     * 설치된 템플릿 행을 찾습니다 — 미설치면 null.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return Template|null 템플릿 행
     */
    public function findTemplate(string $identifier): ?Template
    {
        return $this->templateRepository->findByIdentifier($identifier);
    }

    /**
     * 템플릿이 설정을 갖는지 판정합니다 — 칸이 하나 이상 선언돼 있으면 설정이 있다
     * (화면은 템플릿이 따로 공급한다 — {@see screenLayoutName()}).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return bool 설정이 있으면 true
     */
    public function hasSettings(string $identifier): bool
    {
        return $this->schema($identifier)?->hasFields() ?? false;
    }

    /**
     * 설정 화면 레이아웃 이름 — 템플릿이 공급한 화면(`settings/template_settings.*`)이 있으면 `{id}.template_settings`
     * (켜진 관리자 템플릿마다 교차 등록한 행), 화면이나 설정이 없으면 null.
     *
     * 공용 화면은 없다 — 화면을 그리는 부품이 관리자 템플릿 것이면 다른 관리자 템플릿에서 깨지므로,
     * 템플릿이 자기 화면과 부품 묶음(`dist/settings/*`)을 함께 공급한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string|null 레이아웃 이름
     */
    public function screenLayoutName(string $identifier): ?string
    {
        if (! $this->hasSettings($identifier)) {
            return null;
        }

        return TemplateSettingsSchema::layoutPath($identifier) !== null
            ? TemplateLayout::templateSettingsLayoutName($identifier)
            : null;
    }

    /**
     * 설정 화면 경로 — 화면을 그릴 수 없으면 null(목록 「설정」 이 비활성).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string|null 경로
     */
    public function settingsRoute(string $identifier): ?string
    {
        return $this->screenLayoutName($identifier) === null ? null : '/admin/templates/'.$identifier.'/settings';
    }

    /**
     * 관리자 화면이 실을 설정 화면 부품 묶음 주소 — 설치된 템플릿(꺼진 것 포함) 중 묶음을 빌드해 둔 것.
     *
     * 플러그인 묶음처럼 관리자 화면을 열 때 함께 싣는다. 꺼진 템플릿 파일은 이 두 규약 경로만 내준다
     * ({@see TemplateService::getAssetFilePath()}).
     *
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return array<int, array{identifier: string, js?: string, css?: string}> 템플릿별 묶음 주소
     */
    public function settingsAssetUrls(int|string|null $version): array
    {
        $bundles = [];

        foreach ($this->templateRepository->getAllKeyedByIdentifier() as $identifier => $template) {
            if (! in_array($template->status, [ExtensionStatus::Active->value, ExtensionStatus::Inactive->value], true)) {
                continue;
            }

            $assets = TemplateSettingsSchema::settingsAssets((string) $identifier);

            // 켜진 관리자 템플릿은 자기 화면 CSS 가 이미 실려 있다 — 같은 규칙을 두 번 싣지 않는다
            if ($template->type === 'admin' && $template->status === ExtensionStatus::Active->value) {
                unset($assets['css']);
            }

            if ($assets === []) {
                continue;
            }

            $bundle = ['identifier' => (string) $identifier];

            foreach ($assets as $kind => $relative) {
                $bundle[$kind] = AssetUrl::templateAsset((string) $identifier, $relative, $version);
            }

            $bundles[] = $bundle;
        }

        return $bundles;
    }

    /**
     * 템플릿 설정을 조회합니다 — 섹션별 기본값 위에 저장값을 병합하고 계산 키를 더한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $key  `섹션` 또는 `섹션.키` (null 이면 전체)
     * @param  mixed  $default  기본값
     * @return mixed 설정 값 (선언 없는 템플릿의 전체 조회는 빈 배열)
     */
    public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
    {
        if (! isset($this->settingsCache[$identifier])) {
            $schema = $this->schema($identifier);

            if ($schema === null) {
                return $key === null ? [] : $default;
            }

            $settings = $this->mergeWithDefaults($schema, $this->loadStoredSettings($schema));

            foreach ($schema->attachmentPaths() as $path) {
                Arr::set($settings, $path, $this->extractAttachmentIds(Arr::get($settings, $path, [])));
            }

            if ($schema->declaresFavicon()) {
                Arr::set($settings, 'branding.favicon_url', $this->firstFaviconUrl(Arr::get($settings, FaviconPolicy::TEMPLATE_KEY, [])));
            }

            foreach ($schema->imagePaths() as $path) {
                Arr::set($settings, $path.'_url', $this->firstImageUrl(Arr::get($settings, $path, []), $schema->collection($path)));
            }

            $this->settingsCache[$identifier] = $settings;
        }

        $settings = $this->settingsCache[$identifier];

        return $key === null ? $settings : Arr::get($settings, $key, $default);
    }

    /**
     * 관리자 화면용 설정 — 첨부 칸은 첨부 객체 목록(업로더 initialFiles), 비밀값은 마스크.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, array<string, mixed>> 설정
     */
    public function getForAdmin(string $identifier): array
    {
        $schema = $this->schema($identifier);

        if ($schema === null) {
            return [];
        }

        $settings = $this->get($identifier);

        foreach ($schema->attachmentPaths() as $path) {
            Arr::set($settings, $path, $this->attachmentList(Arr::get($settings, $path, []), $schema->collection($path)));
        }

        foreach ($schema->categories as $category) {
            $settings[$category] = SensitiveSettingMask::apply($settings[$category] ?? [], $schema->fields[$category] ?? []);
        }

        return $settings;
    }

    /**
     * 템플릿 설정을 저장합니다.
     *
     * 제출하지 않은 섹션·키는 기존 값을 유지한다(첨부 칸 포함 — 다른 칸만 바꾼 저장이 첨부를 지우지 않는다).
     * 마스크 그대로 돌아온 비밀값도 기존 값을 유지한다. 첨부 칸은 그 칸 컬렉션에 실재하는 ID 만 남기고,
     * 직전 저장값에서 빠진 ID 는 저장 성공 뒤 파기한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array<string, mixed>  $settings  저장할 설정 (섹션 => 키 => 값, 검증 통과분)
     * @param  string|null  $failureReason  실패 시 사유가 담기는 out 파라미터 (성공 시 null)
     * @return bool 저장 성공 여부
     */
    public function save(string $identifier, array $settings, ?string &$failureReason = null): bool
    {
        $failureReason = null;

        HookManager::doAction('core.template_settings.before_save', $identifier, $settings);

        $settings = HookManager::applyFilters('core.template_settings.filter_save_data', $settings, $identifier);

        $schema = $this->schema($identifier);

        if ($schema === null || $this->templateRepository->findByIdentifier($identifier) === null) {
            $failureReason = __('templates.settings.errors.not_available', ['template' => $identifier]);

            return false;
        }

        // 선언 밖 섹션·키·계산 키는 버린다 — 검증을 통과했더라도 필터 훅이 끼워 넣을 수 있다.
        $submitted = $this->pruneToDeclared($schema, $settings);
        $existing = $this->loadStoredSettings($schema);
        $removedIds = [];

        foreach ($schema->categories as $category) {
            if (! isset($submitted[$category])) {
                continue;
            }

            $submitted[$category] = SensitiveSettingMask::stripUnchanged($submitted[$category], $schema->fields[$category] ?? []);
        }

        foreach ($schema->attachmentPaths() as $path) {
            if (! Arr::has($submitted, $path)) {
                continue;
            }

            $value = Arr::get($submitted, $path);
            $kept = $this->resolveSubmittedAttachmentIds($schema->collection($path), is_array($value) ? $value : [], $schema->maxFiles($path));

            // 파기 대상은 저장 **전에** 정한다 — 저장 후에는 직전 저장값을 알 수 없다.
            $previous = $this->extractAttachmentIds(Arr::get($existing, $path, []));
            $removedIds = array_merge($removedIds, array_values(array_diff($previous, $kept)));

            Arr::set($submitted, $path, $kept);
        }

        $merged = $existing;

        foreach ($submitted as $category => $values) {
            $merged[$category] = array_merge($merged[$category] ?? [], $values);
        }

        $result = $this->writeStoredSettings($identifier, $schema, $merged);

        if (! $result) {
            $failureReason = __('templates.settings.errors.save_failed');
        }

        if ($result) {
            $this->afterChange($identifier);

            // 저장이 확정된 뒤에야 파일을 파기한다 — 실패한 저장이 파일만 지우면 설정은 사라진 첨부를 가리킨다.
            $this->purgeAttachments($removedIds, $identifier);
        }

        HookManager::doAction('core.template_settings.after_save', $identifier, $merged, $result);

        return $result;
    }

    /**
     * 템플릿 설정을 기본값으로 되돌립니다 — 섹션 하나 또는 전체. 그 범위의 저장 파일과 첨부를 지운다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $section  섹션 (null 이면 전체)
     * @param  string|null  $failureReason  실패 시 사유
     * @return bool 성공 여부
     */
    public function reset(string $identifier, ?string $section = null, ?string &$failureReason = null): bool
    {
        $failureReason = null;
        $schema = $this->schema($identifier);

        if ($schema === null || $this->templateRepository->findByIdentifier($identifier) === null
            || ($section !== null && ! in_array($section, $schema->categories, true))) {
            $failureReason = __('templates.settings.errors.not_available', ['template' => $identifier]);

            return false;
        }

        HookManager::doAction('core.template_settings.before_reset', $identifier, $section);

        $sections = $section === null ? $schema->categories : [$section];
        $existing = $this->loadStoredSettings($schema);
        $removedIds = [];

        foreach ($schema->attachmentPaths() as $path) {
            if (in_array(explode('.', $path, 2)[0], $sections, true)) {
                $removedIds = array_merge($removedIds, $this->extractAttachmentIds(Arr::get($existing, $path, [])));
            }
        }

        $result = true;

        foreach ($sections as $category) {
            if ($this->storage()->exists($identifier.'/settings', $category.'.json')) {
                $result = $this->storage()->delete($identifier.'/settings', $category.'.json') && $result;
            }
        }

        if (! $result) {
            $failureReason = __('templates.settings.errors.save_failed');
        } else {
            $this->afterChange($identifier);
            $this->purgeAttachments($removedIds, $identifier);
        }

        HookManager::doAction('core.template_settings.after_reset', $identifier, $section, $result);

        return $result;
    }

    /**
     * 템플릿 설정 디렉토리와 그 템플릿 설정 첨부를 지웁니다 — 템플릿 삭제에서 「데이터도 삭제」 를 골랐을 때.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    public function deleteSettings(string $identifier): void
    {
        HookManager::doAction('core.template_settings.before_delete', $identifier);

        $this->storage()->deleteAll($identifier.'/settings');

        $ids = $this->attachmentRepository->getByCollectionPrefix(TemplateSettingCollections::templatePrefix($identifier))
            ->pluck('id')
            ->all();

        $this->purgeAttachments($ids, $identifier);

        unset($this->schemaCache[$identifier]);
        $this->afterChange($identifier);

        HookManager::doAction('core.template_settings.after_delete', $identifier);
    }

    /**
     * 템플릿 패키지가 바뀐 뒤(업데이트) 선언·설정 캐시를 새로 읽게 합니다 — 기본값·노출이 바뀌었을 수 있다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    public function refreshAfterPackageChange(string $identifier): void
    {
        unset($this->schemaCache[$identifier]);
        $this->afterChange($identifier);
    }

    /**
     * 활성 템플릿(그 화면 종류)의 프론트 노출 설정을 돌려줍니다 — `G7Config.templateSettings`.
     *
     * @param  string  $type  화면 종류 (`user`|`admin`)
     * @return array<string, mixed> 노출 설정 (섹션 => 키 => 값)
     */
    public function getActiveFrontendSettings(string $type): array
    {
        $template = $this->templateRepository->findActiveByType($type);

        if ($template === null) {
            return [];
        }

        return $this->frontendSettings((string) $template->identifier);
    }

    /**
     * 한 템플릿의 프론트 노출 설정 — 노출 스키마로 거르고 비밀값은 어떤 경우에도 뺀다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, mixed> 노출 설정
     */
    public function frontendSettings(string $identifier): array
    {
        $schema = $this->schema($identifier);

        if ($schema === null) {
            return [];
        }

        return $this->filterByFrontendSchema($this->withoutSensitive($schema, $this->get($identifier)), $schema->frontendSchema);
    }

    /**
     * 비밀값 칸을 뺀 설정 — 미러·노출용.
     *
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  array<string, mixed>  $settings  설정
     * @return array<string, mixed> 비밀값 제외 설정
     */
    public function withoutSensitive(TemplateSettingsSchema $schema, array $settings): array
    {
        foreach ($schema->sensitivePaths() as $path) {
            Arr::forget($settings, $path);
        }

        return $settings;
    }

    /**
     * 활성 템플릿의 표준 키 값(코어가 `<html>` 속성으로 싣는다) — 선언·선택지 안의 값만.
     *
     * @param  string  $type  화면 종류 (`user`|`admin`)
     * @return array<string, string> `accent`·`color_scheme_default` 중 있는 것
     */
    public function standardAttributes(string $type): array
    {
        $template = $this->templateRepository->findActiveByType($type);
        $schema = $template === null ? null : $this->schema((string) $template->identifier);

        if ($schema === null) {
            return [];
        }

        $attributes = [];

        foreach ([TemplateSettingsSchema::KEY_ACCENT => 'accent', TemplateSettingsSchema::KEY_COLOR_SCHEME_DEFAULT => 'color_scheme_default'] as $path => $name) {
            $field = $schema->field($path);

            if ($field === null) {
                continue;
            }

            $value = $this->get((string) $template->identifier, $path);

            if (is_string($value) && in_array($value, TemplateSettingsSchema::optionValues($field), true)) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    /**
     * 템플릿이 직접 쓴 설정 화면 레이아웃을 읽습니다(XSS sanitize 포함) — 없으면 null.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, mixed>|null 레이아웃
     */
    public function getLayout(string $identifier): ?array
    {
        $path = TemplateSettingsSchema::layoutPath($identifier);

        if ($path === null) {
            return null;
        }

        $content = File::get($path);

        if (preg_match('/\.g7\.html$/i', $path) === 1) {
            try {
                $layout = (new LayoutMarkupCompiler)->compile(
                    $content,
                    $path,
                    (new MarkupCompileContextResolver)->installContextFor($path),
                )->json;
            } catch (\Throwable $e) {
                Log::error('템플릿 설정 레이아웃 컴파일 실패', ['path' => $path, 'error' => $e->getMessage()]);

                return null;
            }
        } else {
            $layout = EmptyMap::decodePreserving($content);

            if (! is_array($layout)) {
                return null;
            }
        }

        return $this->layoutService->sanitizeLayoutJson($layout);
    }

    /**
     * 템플릿 파비콘 칸의 첫 유효 첨부를 돌려줍니다 — 우선순위 해석기가 쓴다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return Attachment|null 첨부
     */
    public function faviconAttachment(string $identifier): ?Attachment
    {
        $schema = $this->schema($identifier);

        if ($schema === null || ! $schema->declaresFavicon()) {
            return null;
        }

        return $this->firstFaviconAttachment(
            $this->get($identifier, FaviconPolicy::TEMPLATE_KEY, []),
            $schema->collection(FaviconPolicy::TEMPLATE_KEY)
        );
    }

    /**
     * 설정 화면용 섹션·칸 서술 — 라벨·안내·선택지 라벨은 현재 로케일로 해석한다.
     *
     * 라벨이 `$t:키` 면 그 템플릿의 프론트 언어 데이터(활성 언어팩 오버레이 포함)에서 찾는다. 사용자 템플릿의 언어
     * 파일은 관리자 화면에 로드되지 않으므로 서버가 풀어서 내려준다. 키가 없으면 키 원문 대신 칸 키를 쓴다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<int, array<string, mixed>> 섹션 목록 (선언 순서)
     */
    public function sectionDescriptors(string $identifier): array
    {
        $schema = $this->schema($identifier);

        if ($schema === null) {
            return [];
        }

        $sections = [];

        foreach ($schema->categories as $category) {
            $fields = [];

            foreach ($schema->fields[$category] ?? [] as $key => $field) {
                $fields[] = $this->describeField($identifier, $schema, "{$category}.{$key}", (string) $key, $field);
            }

            $sections[] = [
                'key' => $category,
                'label' => $this->translate($identifier, $schema->sections[$category]['label'] ?? null) ?? $category,
                'hint' => $this->translate($identifier, $schema->sections[$category]['hint'] ?? null),
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * 칸 경로 => 해석된 라벨 — 검증 오류 문구의 속성 이름(「섹션 · 칸」).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, string> `섹션.키` => 라벨
     */
    public function fieldLabels(string $identifier): array
    {
        $labels = [];

        foreach ($this->sectionDescriptors($identifier) as $section) {
            foreach ($section['fields'] as $field) {
                $labels[$section['key'].'.'.$field['key']] = $section['label'].' · '.$field['label'];

                foreach ($field['item_fields'] ?? [] as $item) {
                    $labels[$section['key'].'.'.$field['key'].'.*.'.$item['key']] = $section['label'].' · '.$field['label'].' · '.$item['label'];
                }
            }
        }

        return $labels;
    }

    /**
     * 칸 하나의 화면 서술을 만듭니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  string  $path  칸 경로 (목록 행 칸이면 그 목록 경로)
     * @param  string  $key  칸 키
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<string, mixed> 서술
     */
    private function describeField(string $identifier, TemplateSettingsSchema $schema, string $path, string $key, array $field): array
    {
        $descriptor = [
            'key' => $key,
            'type' => $field['type'],
            'label' => $this->translate($identifier, $field['label'] ?? null) ?? $key,
            'hint' => $this->translate($identifier, $field['hint'] ?? null),
            'placeholder' => $this->translate($identifier, $field['placeholder'] ?? null),
            'required' => (bool) ($field['required'] ?? false),
            'sensitive' => (bool) ($field['sensitive'] ?? false),
        ];

        foreach (['min', 'max', 'step', 'max_length', 'max_items', 'visible_if'] as $optional) {
            if (array_key_exists($optional, $field)) {
                $descriptor[$optional] = $field[$optional];
            }
        }

        if ($field['type'] === 'enum') {
            $descriptor['options'] = array_map(function ($option) use ($identifier) {
                $value = is_array($option) ? ($option['value'] ?? null) : $option;
                $label = is_array($option) ? $this->translate($identifier, $option['label'] ?? null) : null;

                return ['value' => $value, 'label' => $label ?? (string) $value];
            }, (array) ($field['options'] ?? []));
        }

        if (in_array($field['type'], TemplateSettingsSchema::FILE_TYPES, true)) {
            $descriptor['collection'] = $schema->collection($path);
            $descriptor['max_files'] = $schema->maxFiles($path);
            $descriptor['max_size_kb'] = $path === FaviconPolicy::TEMPLATE_KEY ? FaviconPolicy::MAX_SIZE_KB : ($field['max_size_kb'] ?? null);
            $descriptor['accept'] = implode(',', array_map(fn ($ext) => '.'.$ext, $schema->acceptedExtensions($path)));
        }

        if ($field['type'] === 'menu') {
            $descriptor = array_merge($descriptor, TemplateMenu::describe($field, fn ($value) => $this->translate($identifier, $value)));
        }

        if ($field['type'] === 'list') {
            $descriptor['item_fields'] = [];

            foreach ((array) ($field['item_fields'] ?? []) as $itemKey => $itemField) {
                $descriptor['item_fields'][] = $this->describeField($identifier, $schema, $path, (string) $itemKey, $itemField);
            }
        }

        return $descriptor;
    }

    /**
     * 라벨 값(`$t:키`·다국어 객체·평문)을 현재 로케일 문자열로 풉니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  mixed  $value  라벨 값
     * @return string|null 문자열 (없으면 null)
     */
    private function translate(string $identifier, mixed $value): ?string
    {
        if (is_array($value)) {
            $value = LocalizedValue::pick($value, app()->getLocale());
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_starts_with($value, '$t:')) {
            return $value;
        }

        $key = substr($value, 3);

        // 지금 로케일에 번역이 없으면(그 로케일 언어팩 미설치) 대체 로케일 문구 — 화면에 키가 그대로 나오지 않게
        foreach (array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'ko')]) as $locale) {
            $translated = Arr::get($this->languageData($identifier, $locale), $key);

            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        }

        return null;
    }

    /**
     * 템플릿 언어 데이터 (요청 안 캐시) — 꺼진 템플릿도 읽는다(관리자가 켜기 전에 설정을 볼 수 있다).
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $locale  로케일
     * @return array<string, mixed> 언어 데이터 (읽기 실패면 빈 배열)
     */
    private function languageData(string $identifier, string $locale): array
    {
        if (! array_key_exists($identifier.'|'.$locale, $this->languageCache)) {
            $data = [];

            try {
                $result = app(TemplateService::class)->getLanguageDataWithModules($identifier, $locale, includeInactive: true);
                $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            } catch (\Throwable $e) {
                Log::warning('템플릿 설정 라벨 언어 데이터 로딩 실패', ['template' => $identifier, 'error' => $e->getMessage()]);
            }

            $this->languageCache[$identifier.'|'.$locale] = $data;
        }

        return $this->languageCache[$identifier.'|'.$locale];
    }

    /**
     * 요청 내 캐시를 비웁니다.
     *
     * @param  string|null  $identifier  템플릿 식별자 (null 이면 전체)
     * @return void 반환값 없음
     */
    public function clearCache(?string $identifier = null): void
    {
        if ($identifier === null) {
            $this->settingsCache = [];
            $this->schemaCache = [];
            $this->languageCache = [];

            return;
        }

        unset($this->settingsCache[$identifier], $this->schemaCache[$identifier]);
        $this->languageCache = array_filter(
            $this->languageCache,
            fn (string $cacheKey) => ! str_starts_with($cacheKey, $identifier.'|'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * 설정이 바뀐 뒤 공통 처리 — 요청 캐시·설정 미러·설정 버전.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function afterChange(string $identifier): void
    {
        unset($this->settingsCache[$identifier]);
        app(ExtensionSettingsMirror::class)->refreshTemplate($identifier);
        SettingsVersion::bump();
    }

    /**
     * 템플릿 설정 저장소를 돌려줍니다 — `templates` 디스크, 카테고리는 `{템플릿}/settings`.
     *
     * @return StorageInterface 저장소
     */
    private function storage(): StorageInterface
    {
        return new CoreStorageDriver(self::DISK);
    }

    /**
     * 섹션별 저장 파일을 읽어 선언에 맞춥니다 — 이름 바꾸기 적용, 선언 밖 키 제외, 비밀값 복호화.
     *
     * @param  TemplateSettingsSchema  $schema  선언
     * @return array<string, array<string, mixed>> 저장값 (섹션 => 키 => 값)
     */
    private function loadStoredSettings(TemplateSettingsSchema $schema): array
    {
        $raw = [];
        $sources = array_unique(array_merge(
            $schema->categories,
            array_map(fn (string $old) => explode('.', $old, 2)[0], array_keys($schema->renames()))
        ));

        foreach ($sources as $category) {
            $content = $this->storage()->get($schema->identifier.'/settings', $category.'.json');

            if ($content === null) {
                continue;
            }

            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                Log::error('템플릿 설정 파일 JSON 파싱 실패', ['template' => $schema->identifier, 'section' => $category, 'error' => json_last_error_msg()]);

                continue;
            }

            $raw[$category] = $decoded;
        }

        foreach ($schema->renames() as $old => $new) {
            if (Arr::has($raw, $old) && ! Arr::has($raw, $new)) {
                Arr::set($raw, $new, Arr::get($raw, $old));
            }
        }

        $stored = $this->pruneToDeclared($schema, $raw);

        foreach ($schema->sensitivePaths() as $path) {
            $value = Arr::get($stored, $path);

            if (is_string($value) && $value !== '') {
                try {
                    Arr::set($stored, $path, Crypt::decryptString($value));
                } catch (\Throwable) {
                    // 복호화 실패 시 원래 값 유지 (손으로 넣은 평문 — 다음 저장 때 암호화된다)
                }
            }
        }

        return $stored;
    }

    /**
     * 섹션별 저장 파일을 씁니다 — 비밀값은 암호화, 선언 밖 섹션 파일(이름 바꾸기로 비워진 것)은 지운다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  array<string, array<string, mixed>>  $settings  저장할 값 (복호화 상태)
     * @return bool 성공 여부
     */
    private function writeStoredSettings(string $identifier, TemplateSettingsSchema $schema, array $settings): bool
    {
        foreach ($schema->sensitivePaths() as $path) {
            $value = Arr::get($settings, $path);

            if (is_string($value) && $value !== '') {
                Arr::set($settings, $path, Crypt::encryptString($value));
            }
        }

        try {
            foreach ($schema->categories as $category) {
                if (! array_key_exists($category, $settings)) {
                    continue;
                }

                $written = $this->storage()->put(
                    $identifier.'/settings',
                    $category.'.json',
                    json_encode($settings[$category], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );

                if (! $written) {
                    return false;
                }
            }

            foreach (array_keys($schema->renames()) as $old) {
                $oldCategory = explode('.', $old, 2)[0];

                if (! in_array($oldCategory, $schema->categories, true) && $this->storage()->exists($identifier.'/settings', $oldCategory.'.json')) {
                    $this->storage()->delete($identifier.'/settings', $oldCategory.'.json');
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('템플릿 설정 파일 저장 실패', ['template' => $identifier, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 선언 안의 섹션·키만 남기고 값을 칸 종류로 정규화합니다(계산 키 제외).
     *
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  array<string, mixed>  $settings  값
     * @return array<string, array<string, mixed>> 정리된 값
     */
    private function pruneToDeclared(TemplateSettingsSchema $schema, array $settings): array
    {
        $pruned = [];

        foreach ($schema->categories as $category) {
            if (! is_array($settings[$category] ?? null)) {
                continue;
            }

            foreach ($schema->fields[$category] ?? [] as $key => $field) {
                if (array_key_exists($key, $settings[$category])) {
                    $pruned[$category][$key] = $this->normalizeValue($field, $settings[$category][$key]);
                }
            }
        }

        return $pruned;
    }

    /**
     * 값을 칸 종류에 맞게 정규화합니다 — 숫자 문자열·불리언 문자열(HTML 입력·명령줄)을 실제 타입으로.
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @param  mixed  $value  값
     * @return mixed 정규화한 값
     */
    private function normalizeValue(array $field, mixed $value): mixed
    {
        return match ($field['type'] ?? null) {
            'integer' => is_numeric($value) ? (int) $value : ($value === '' ? null : $value),
            'boolean' => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value),
            'multilingual' => is_string($value) ? [config('app.locale') => $value] : $value,
            'list' => is_array($value) ? array_values(array_map(
                fn ($row) => is_array($row) ? $this->normalizeRow((array) ($field['item_fields'] ?? []), $row) : $row,
                $value
            )) : $value,
            'menu' => TemplateMenu::normalize($value, $field),
            default => $value,
        };
    }

    /**
     * 목록 행 하나를 행 칸 선언으로 정리합니다 — 선언 밖 칸은 버린다.
     *
     * @param  array<string, array<string, mixed>>  $itemFields  행 칸 선언
     * @param  array<string, mixed>  $row  행
     * @return array<string, mixed> 정리된 행
     */
    private function normalizeRow(array $itemFields, array $row): array
    {
        $normalized = [];

        foreach ($itemFields as $key => $field) {
            if (array_key_exists($key, $row)) {
                $normalized[$key] = $this->normalizeValue($field, $row[$key]);
            }
        }

        return $normalized;
    }

    /**
     * 저장값을 선언 기본값 위에 섹션별로 병합합니다.
     *
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  array<string, array<string, mixed>>  $stored  저장값
     * @return array<string, array<string, mixed>> 병합 결과
     */
    private function mergeWithDefaults(TemplateSettingsSchema $schema, array $stored): array
    {
        $settings = [];

        foreach ($schema->categories as $category) {
            $settings[$category] = array_merge($schema->defaults[$category] ?? [], $stored[$category] ?? []);
        }

        return $settings;
    }

    /**
     * 제출값에서 그 컬렉션에 실재하는 첨부 ID 만 남깁니다 — 최대 개수까지.
     *
     * @param  string  $collection  컬렉션
     * @param  array<int, mixed>  $submitted  제출값 (첨부 객체 또는 ID)
     * @param  int  $maxFiles  최대 개수
     * @return array<int, int> 첨부 ID 목록
     */
    private function resolveSubmittedAttachmentIds(string $collection, array $submitted, int $maxFiles): array
    {
        $submittedIds = $this->extractAttachmentIds($submitted);

        if ($submittedIds === []) {
            return [];
        }

        $existingIds = $this->attachmentRepository->getByCollection($collection)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_slice(array_values(array_intersect($submittedIds, $existingIds)), 0, max(1, $maxFiles));
    }

    /**
     * 첨부 객체·ID 가 섞인 값에서 ID 만 뽑습니다.
     *
     * @param  mixed  $value  값
     * @return array<int, int> 첨부 ID 목록
     */
    private function extractAttachmentIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 첨부 ID 목록을 화면용 첨부 객체 목록으로 바꿉니다 — 그 컬렉션 소속만.
     *
     * @param  array<int, int>  $ids  첨부 ID
     * @param  string  $collection  컬렉션
     * @return array<int, array<string, mixed>> 첨부 목록
     */
    private function attachmentList(array $ids, string $collection): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->attachmentRepository->findByIds($ids)
            ->filter(fn ($attachment) => $attachment->collection === $collection)
            ->map(fn ($attachment) => (new AttachmentResource($attachment))->toListArray())
            ->values()
            ->all();
    }

    /**
     * 파비콘 첨부 ID 목록에서 첫 유효 첨부를 찾습니다 — 행이 있고 MIME 이 파비콘 목록 안.
     *
     * @param  mixed  $ids  첨부 ID 목록
     * @param  string  $collection  컬렉션
     * @return Attachment|null 첨부
     */
    private function firstFaviconAttachment(mixed $ids, string $collection): ?Attachment
    {
        $ids = $this->extractAttachmentIds($ids);

        if ($ids === []) {
            return null;
        }

        return $this->attachmentRepository->findByIds($ids)->first(
            fn ($attachment) => $attachment->collection === $collection && FaviconPolicy::responseMime($attachment->mime_type) !== null
        );
    }

    /**
     * 파비콘 계산 키 값 — 첫 유효 첨부의 파비콘 경로.
     *
     * @param  mixed  $ids  첨부 ID 목록
     * @return string|null URL
     */
    private function firstFaviconUrl(mixed $ids): ?string
    {
        $ids = $this->extractAttachmentIds($ids);

        if ($ids === []) {
            return null;
        }

        $attachment = $this->attachmentRepository->findByIds($ids)->first(
            fn ($attachment) => FaviconPolicy::isFaviconCollection($attachment->collection) && FaviconPolicy::responseMime($attachment->mime_type) !== null
        );

        return $attachment === null ? null : FaviconPolicy::url((string) $attachment->hash);
    }

    /**
     * 이미지 칸 계산 키 값 — 그 칸 컬렉션의 첫 래스터 이미지 공개 경로(코어 사이트 로고와 같은 경로).
     *
     * @param  mixed  $ids  첨부 ID 목록
     * @param  string  $collection  컬렉션
     * @return string|null URL
     */
    private function firstImageUrl(mixed $ids, string $collection): ?string
    {
        $ids = $this->extractAttachmentIds($ids);

        if ($ids === []) {
            return null;
        }

        $attachment = $this->attachmentRepository->findByIds($ids)->first(
            fn ($attachment) => $attachment->collection === $collection && InlineMediaPolicy::isInlineImage($attachment->mime_type)
        );

        return $attachment === null ? null : Attachment::urlForHash((string) $attachment->hash);
    }

    /**
     * 첨부를 파일까지 파기합니다 — 실패는 로그로만 남긴다(설정 저장은 이미 확정됐다).
     *
     * @param  array<int, int>  $ids  첨부 ID
     * @param  string  $identifier  템플릿 식별자 (로그 문맥)
     * @return void 반환값 없음
     */
    private function purgeAttachments(array $ids, string $identifier): void
    {
        if ($ids === []) {
            return;
        }

        $attachmentService = app(AttachmentService::class);

        foreach (array_unique($ids) as $id) {
            try {
                $attachmentService->delete((int) $id);
            } catch (\Throwable $e) {
                Log::warning('템플릿 설정 첨부 파기 실패 — 저장은 확정됨', [
                    'template' => $identifier,
                    'attachment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
