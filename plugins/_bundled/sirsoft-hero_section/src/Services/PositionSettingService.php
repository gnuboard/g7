<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;
use Plugins\Sirsoft\HeroSection\Enums\SlideWidth;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\PositionSettingRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\TargetSettingRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\TargetSettingRepository;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionDefinition;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;

/**
 * 위치별 슬라이더 설정 서비스
 *
 * 위치 목록은 위치 레지스트리(`PositionRegistry`, 위치 키 = 확장점 이름)가 정하고, 저장된 행이 없으면 기본값을 쓴다. 동적 위치(게시판마다·
 * 상품마다 등)는 위치 기본 설정 위에 대상이 명시한 항목만 재정의할 수 있다 — 적용 순서는 플러그인 기본값 → 위치 기본 설정 → 대상 재정의이고,
 * 재정의에 키가 없으면 상속, `false`·`null` 은 명시값이다({@see resolvedFor()}). 첫 로드 힌트(`slot_*`)는 정적 위치만 낸다 — 대상마다 값을
 * 줄 수 없다(D8). 동적 위치의 받는 동안 모양은 브라우저가 위치 + 대상 단위로 기억한다.
 */
class PositionSettingService
{
    /**
     * 대상 고르기 목록 최대 건수 — 고르기 칸은 검색으로 좁힌다.
     */
    private const TARGET_LIMIT = 20;

    /**
     * 대상 목록 한 쪽의 대상 수
     */
    public const PLACEMENTS_PER_PAGE = 20;

    /**
     * PositionSettingService 생성자
     *
     * @param  PositionSettingRepositoryInterface  $settings  위치 설정 Repository
     * @param  BannerRepositoryInterface  $banners  배너 저장소(위치별 배너 유무 힌트의 원본)
     * @param  PreviewFrameResolver  $frames  관리자 미리보기 구좌 틀 해석기
     * @param  TargetSettingRepositoryInterface|null  $targets  대상별 재정의 저장소 — 업데이트 명령이 옛 서비스 제공자(이 바인딩이 없는 판)로 부팅된
     *                                                          채 새 코드를 부르는 동안에는 비어 들어온다. 쓰는 시점에 다시 찾는다({@see targetSettings()})
     */
    public function __construct(
        private PositionSettingRepositoryInterface $settings,
        private BannerRepositoryInterface $banners,
        private PreviewFrameResolver $frames,
        private ?TargetSettingRepositoryInterface $targets = null,
    ) {}

    /**
     * 대상별 재정의 저장소를 꺼냅니다 — 생성 때 받지 못했으면(업데이트 도중 옛 제공자) 바인딩을, 그것도 없으면 구현을 컨테이너에서 받는다.
     *
     * @return TargetSettingRepositoryInterface 대상별 재정의 저장소
     */
    private function targetSettings(): TargetSettingRepositoryInterface
    {
        return $this->targets ??= app()->bound(TargetSettingRepositoryInterface::class)
            ? app(TargetSettingRepositoryInterface::class)
            : app(TargetSettingRepository::class);
    }

    /**
     * 위치 목록과 위치별 설정, 업로드 한도를 반환합니다(관리자 화면용).
     *
     * 위치는 탭 순서(등록 순서)대로 내고, 배너가 남은 등록되지 않은 위치(위치를 더한 확장을 껐을 때)를 `registered: false` 로 뒤에 붙인다(D7).
     * 이름은 문구 키(`label_key`, 화면이 번역) 또는 확장이 언어별로 준 이름(`label`, 현재 언어로 고른 값) 중 하나다.
     *
     * @return array{positions: array<int, array<string, mixed>>, upload: array<string, int|float>} 위치·설정·업로드 한도
     */
    public function overview(): array
    {
        $saved = $this->settings->allKeyed();
        $settingsOf = fn (string $key) => isset($saved[$key]) ? $saved[$key]->toSettings() : PositionSetting::DEFAULTS;

        $positions = array_values(array_map(fn (PositionDefinition $definition) => [
            'key' => $definition->key,
            'extension_point' => $definition->key,
            'label_key' => $definition->labelKey(),
            'label' => $definition->label(),
            'registered' => true,
            'dynamic' => $definition->isDynamic(),
            'target' => $definition->isDynamic() ? [
                'type' => $definition->targetType,
                'label_key' => $definition->targetLabelKey(),
                'label' => $definition->targetLabel(),
                'route_param' => $definition->routeParam,
            ] : null,
            // 템플릿에 같은 이름의 확장점만 있으면 히어로가 그 자리를 채우는가 — 동적 위치는 대상 값 경로가 있어야 한다
            'auto_output' => ! $definition->isDynamic() || $definition->routeParam !== null,
            'settings' => $settingsOf($definition->key),
        ], $this->registry()->all()));

        foreach (array_diff($this->banners->positionKeys(), $this->registry()->keys()) as $key) {
            $positions[] = [
                'key' => $key,
                'extension_point' => $key,
                'label_key' => null,
                'label' => $key,
                'registered' => false,
                'dynamic' => false,
                'target' => null,
                'auto_output' => false,
                'settings' => $settingsOf($key),
            ];
        }

        return [
            'positions' => $positions,
            'upload' => UploadLimit::describe(),
            'editor' => [
                'timezone' => TimezoneHelper::getSiteTimezone(),
                'server_now' => now()->toIso8601String(),
                'default_locale' => config('app.site_locale', config('app.locale')),
                'locales' => array_values(config('app.supported_locales', [])),
                'focus_limits' => ['min' => Banner::FOCUS_MIN, 'max' => Banner::FOCUS_MAX],
                // 문구 상자 폭·등장 지연 입력 범위 — 저장 규칙(BannerFieldRules)과 같은 상수라 화면이 허용한 값이 저장에서 422 가 되지 않는다
                'text_width_limits' => ['min' => Banner::TEXT_WIDTH_MIN, 'max' => Banner::TEXT_WIDTH_MAX],
                'animation_delay_limits' => ['min' => 0, 'max' => Banner::ANIMATION_DELAY_MAX],
                // 기기별 최대 높이(px) 입력 범위 — 저장 규칙(UpdatePositionSettingRequest)과 같은 상수
                // overlay_hint: 메뉴 겹치기와 함께 쓸 때 문구 자리가 좁아질 수 있다고 알리는 높이(안내만, 저장은 막지 않는다)
                'max_height_limits' => ['min' => PositionSetting::MAX_HEIGHT_MIN, 'max' => PositionSetting::MAX_HEIGHT_MAX, 'overlay_hint' => PositionSetting::MAX_HEIGHT_OVERLAY_HINT],
                'preview_frame' => $this->frames->resolve(),
            ],
        ];
    }

    /**
     * 정적 위치별 표시 폭을 반환합니다(행이 없으면 content) — 사용자 화면이 첫 로드 때 받는 플러그인 설정 힌트(`slot_widths`)의 원본.
     *
     * @return array<string, string> 위치 키 => content|full
     */
    public function slotWidths(): array
    {
        $saved = $this->settings->allKeyed();
        $widths = [];
        foreach ($this->staticKeys() as $key) {
            $widths[$key] = isset($saved[$key]) ? $saved[$key]->toSettings()['width'] : PositionSetting::DEFAULTS['width'];
        }

        return $widths;
    }

    /**
     * 정적 위치별로 사이트 상단 메뉴를 배너 위에 겹치는지 반환합니다 — 메뉴 겹치기를 켰고 화면 전체 폭일 때만 true. 사용자 화면이
     * 첫 로드 때 받는 플러그인 설정 힌트(`slot_overlays`)의 원본이다(받는 동안의 스켈레톤도 메뉴 밑으로 들어가 배너가 와도 밀리지 않게).
     *
     * @return array<string, bool> 위치 키 => 겹침 여부
     */
    public function slotOverlays(): array
    {
        $saved = $this->settings->allKeyed();
        $overlays = [];
        foreach ($this->staticKeys() as $key) {
            $settings = isset($saved[$key]) ? $saved[$key]->toSettings() : PositionSetting::DEFAULTS;
            $overlays[$key] = $settings['header_overlay'] && $settings['width'] === SlideWidth::Full->value;
        }

        return $overlays;
    }

    /**
     * 정적 위치별 기기별 최대 높이(px, 비우면 null)를 반환합니다 — 사용자 화면이 첫 로드 때 받는 플러그인 설정 힌트(`slot_max_heights`)의 원본이다
     * (받는 동안의 스켈레톤도 같은 높이에서 멈춰 배너가 와도 아래 본문이 밀리지 않게).
     *
     * @return array<string, array{pc: int|null, mobile: int|null}> 위치 키 => 기기별 최대 높이
     */
    public function slotMaxHeights(): array
    {
        $saved = $this->settings->allKeyed();
        $heights = [];
        foreach ($this->staticKeys() as $key) {
            $settings = isset($saved[$key]) ? $saved[$key]->toSettings() : PositionSetting::DEFAULTS;
            $heights[$key] = ['pc' => $settings['max_height_pc'], 'mobile' => $settings['max_height_mobile']];
        }

        return $heights;
    }

    /**
     * 정적 위치별로 노출 후보 배너(노출 중·예약)가 있는지 반환합니다 — 첫 로드 때 받는 플러그인 설정 힌트(`slot_banners`)의 원본이다.
     * 첫 접속은 브라우저에 직전 모양 기억이 없으므로, 이 힌트가 없으면 배너가 없는 위치도 응답 전까지 스켈레톤을 그린다.
     * 기간이 끝나 저절로 빠진 배너는 다음 저장 때 반영된다 — 그 사이 저장소를 쓸 수 있는 재방문자는 브라우저 기억이 스켈레톤을 막고, 첫 방문자·저장소가 막힌 방문자에게는 잠깐 보인다.
     *
     * @return array<string, bool> 위치 키 => 후보 유무
     */
    public function slotBanners(): array
    {
        $now = now();
        $banners = [];
        foreach ($this->staticKeys() as $key) {
            $banners[$key] = $this->banners->hasExposureCandidates(Placement::of($key), $now);
        }

        return $banners;
    }

    /**
     * 동적 위치의 대상 고르기 목록과 이미 고른 대상의 이름·사이트 주소를 반환합니다.
     *
     * 고른 대상이 지워졌으면(「없는 대상」) `exists: false` 로 낸다(D7 — 배너는 남는다).
     *
     * @param  string  $position  위치 키
     * @param  string  $keyword  검색어 (비면 앞에서부터)
     * @param  string|null  $key  이미 고른 대상 키
     * @return array{position: string, items: array<int, array{key: string, label: string, hint: string}>, selected: array{key: string, label: string|null, exists: bool, site_url: string|null}|null}|null 대상 목록 (동적 위치가 아니면 null)
     */
    public function targets(string $position, string $keyword, ?string $key): ?array
    {
        $resolver = $this->registry()->resolver($position);
        if ($resolver === null) {
            return null;
        }

        $label = $key === null ? null : ($resolver->labels([$key])[$key] ?? null);

        return [
            'position' => $position,
            'items' => $resolver->search($keyword, self::TARGET_LIMIT),
            'selected' => $key === null ? null : [
                'key' => $key,
                'label' => $label,
                'exists' => $label !== null,
                'site_url' => $label !== null ? $resolver->siteUrl($key) : null,
            ],
        ];
    }

    /**
     * 위치가 지금 등록돼 있는지 반환합니다 — 등록되지 않은 위치(확장을 껐을 때)의 설정은 저장하지 않는다.
     *
     * @param  string  $position  위치 키
     * @return bool 등록돼 있으면 true
     */
    public function isRegistered(string $position): bool
    {
        return $this->registry()->isRegistered($position);
    }

    /**
     * 한 위치(동적 위치면 그 대상)의 최종 설정 값을 반환합니다 — 공개 API 와 관리자 미리보기가 쓰는 같은 해석기({@see resolvedFor()}).
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키 (정적 위치·대상 없음은 null — 위치 기본 설정)
     * @return array{autoplay: bool, interval_seconds: int, effect: string, width: string, header_overlay: bool, pagination: string, max_height_pc: int|null, max_height_mobile: int|null} 설정 값
     */
    public function settingsFor(string $position, ?string $targetKey = null): array
    {
        return $this->resolvedFor($position, $targetKey)['settings'];
    }

    /**
     * 설정을 플러그인 기본값 → 위치 기본 설정 → 대상 재정의 순으로 겹쳐 최종 값과 항목별 출처를 냅니다.
     *
     * 재정의는 키가 있을 때만 쓴다(`array_key_exists`) — 자동 재생 `false`·최대 높이 `null` 같은 명시값이 상속으로 바뀌지 않는다.
     * 정적 위치·대상 없음은 재정의를 보지 않는다.
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키
     * @return array{settings: array<string, mixed>, position_settings: array<string, mixed>, overrides: array<string, mixed>, sources: array<string, string>} 최종 값·위치 값·재정의·출처(default|position|target)
     */
    public function resolvedFor(string $position, ?string $targetKey = null): array
    {
        $row = $this->settings->findByPosition($position);
        $positionSettings = $row?->toSettings() ?? PositionSetting::DEFAULTS;
        $overrides = $targetKey === null || $targetKey === '' ? [] : ($this->targetSettings()->find($position, $targetKey)?->overrideValues() ?? []);

        $settings = [];
        $sources = [];
        foreach ($positionSettings as $key => $value) {
            if (array_key_exists($key, $overrides)) {
                $settings[$key] = $this->castSetting($key, $overrides[$key]);
                $sources[$key] = 'target';
            } else {
                $settings[$key] = $value;
                $sources[$key] = $row === null ? 'default' : 'position';
            }
        }

        return ['settings' => $settings, 'position_settings' => $positionSettings, 'overrides' => $overrides, 'sources' => $sources];
    }

    /**
     * 동적 위치 한 대상의 설정 화면 자료를 반환합니다 — 대상 이름·존재, 위치 기본값, 재정의, 최종 값과 출처.
     *
     * @param  string  $position  위치 키 (동적 위치 — 컨트롤러가 확인했다)
     * @param  string  $targetKey  대상 키
     * @return array{position: string, target: array{key: string, label: string|null, exists: bool}, position_settings: array<string, mixed>, overrides: array<string, mixed>, settings: array<string, mixed>, sources: array<string, string>} 대상 설정
     */
    public function targetDetail(string $position, string $targetKey): array
    {
        $label = $this->registry()->resolver($position)?->labels([$targetKey])[$targetKey] ?? null;
        $resolved = $this->resolvedFor($position, $targetKey);

        return [
            'position' => $position,
            'target' => ['key' => $targetKey, 'label' => $label, 'exists' => $label !== null],
            'position_settings' => $resolved['position_settings'],
            'overrides' => $resolved['overrides'],
            'settings' => $resolved['settings'],
            'sources' => $resolved['sources'],
        ];
    }

    /**
     * 위치가 동적 위치(대상별 재정의를 받는 위치)인지 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return bool 등록된 동적 위치면 true
     */
    public function isDynamic(string $position): bool
    {
        return $this->registry()->isDynamic($position);
    }

    /**
     * 대상이 지금 있는지 반환합니다(해석기 판정).
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return bool 있으면 true
     */
    public function targetExists(string $position, string $targetKey): bool
    {
        return (bool) $this->registry()->resolver($position)?->exists($targetKey);
    }

    /**
     * 대상 재정의 묶음을 바꿉니다 — 빠진 항목은 상속으로 돌아가고, 빈 묶음이면 대상 전체가 위치 기본값으로 돌아간다. 위치 기본 설정과
     * 배너는 건드리지 않는다.
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @param  array<string, mixed>  $overrides  검증된 명시 항목
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return array<string, mixed> 저장 뒤 대상 설정({@see targetDetail()})
     */
    public function updateTarget(string $position, string $targetKey, array $overrides, ?int $actorId): array
    {
        $clean = [];
        foreach (array_intersect_key($overrides, PositionSetting::DEFAULTS) as $key => $value) {
            $clean[$key] = $this->castSetting($key, $value);
        }
        [, $before] = $this->targetSettings()->replace($position, $targetKey, $clean, $actorId);

        HookManager::doAction('sirsoft-hero_section.target_setting.after_update', $position, $targetKey, $clean, $before);

        return $this->targetDetail($position, $targetKey);
    }

    /**
     * 대상 재정의를 모두 지워 위치 기본값으로 되돌립니다(배너·위치 기본 설정은 그대로).
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return array<string, mixed> 되돌린 뒤 대상 설정({@see targetDetail()})
     */
    public function resetTarget(string $position, string $targetKey): array
    {
        $before = $this->targetSettings()->delete($position, $targetKey);

        HookManager::doAction('sirsoft-hero_section.target_setting.after_reset', $position, $targetKey, $before ?? []);

        return $this->targetDetail($position, $targetKey);
    }

    /**
     * 설정 항목 값을 응답 모양으로 맞춥니다(저장 규칙이 형식을 막고, 여기서는 정수·참거짓·null 만 맞춘다).
     *
     * @param  string  $key  항목
     * @param  mixed  $value  값
     * @return mixed 맞춘 값
     */
    private function castSetting(string $key, mixed $value): mixed
    {
        return match ($key) {
            'autoplay', 'header_overlay' => (bool) $value,
            'interval_seconds' => (int) $value,
            'max_height_pc', 'max_height_mobile' => $value === null ? null : (int) $value,
            default => (string) $value,
        };
    }

    /**
     * 위치 설정을 저장합니다.
     *
     * @param  string  $position  위치 키 (등록 위치 — 컨트롤러가 확인했다)
     * @param  array{autoplay: bool, interval_seconds: int, effect: string, width?: string, header_overlay?: bool, pagination?: string, max_height_pc?: int|null, max_height_mobile?: int|null}  $data  검증된 설정 값 (width·header_overlay·pagination·최대 높이를 빼면 지금 값 유지)
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return PositionSetting 저장된 설정 행
     */
    public function update(string $position, array $data, ?int $actorId): PositionSetting
    {
        // 행을 먼저 보장하고 잠가 고친다 — 같은 위치를 동시에 처음 저장하면 둘 다 「행 없음」으로 보고 만들다가 유일 키 충돌(500)이 났다.
        // 처음 저장의 「바뀌기 전」은 기본값 행이고, 겹친 저장은 앞 저장이 끝난 값을 「바뀌기 전」으로 본다
        $this->settings->ensureExists($position);
        [$setting, $snapshot] = DB::transaction(function () use ($position, $data, $actorId) {
            $before = $this->settings->findForUpdate($position);
            $snapshot = $before->toArray();

            return [$this->settings->update($before, $data + ['updated_by' => $actorId]), $snapshot];
        });

        HookManager::doAction('sirsoft-hero_section.position_setting.after_update', $setting, $snapshot);

        return $setting;
    }

    /**
     * 첫 로드 힌트를 내는 정적 위치 키를 반환합니다.
     *
     * @return array<int, string> 위치 키
     */
    private function staticKeys(): array
    {
        return array_values(array_keys(array_filter($this->registry()->all(), fn (PositionDefinition $definition) => ! $definition->isDynamic())));
    }

    /**
     * 동적 위치에서 배너나 대상 설정이 있는 대상 목록을 반환합니다(관리자 대상 목록 화면) — 대상마다 이름·사이트 주소·전체·노출 중 건수·대상 설정 여부.
     *
     * 건수는 저장소 묶음 조회 두 번, 대상 설정 키는 한 번, 이름은 해석기 `labels()` 한 번, 사이트 주소는 해석기가 {@see TargetSiteUrlsInterface} 를 주면 한 번
     * (아니면 그 쪽 안의 키만 하나씩)이다. 지워진 대상도 배너가 남았으면 `exists: false` 로 남겨 관리로 들어갈 수 있게 하고, 노출 중은 0이다.
     * 배너가 없어도 대상 설정이 남은 대상은 배너가 있는 대상 뒤에 건수 0으로 잇는다 — 배너를 모두 지웠거나 대상이 지워져도 들어가 되돌릴 수 있게.
     * 머리의 배너 수·대상 수(`summary`)는 배너 기준이다.
     *
     * @param  string  $position  위치 키
     * @param  int  $page  쪽 번호(1부터)
     * @return array{position: string, data: array<int, array{key: string, label: string|null, exists: bool, site_url: string|null, total: int, active: int, has_settings: bool}>, summary: array{banners: int, targets: int}, pagination: array{current_page: int, last_page: int, total: int, per_page: int}}|null 대상 목록 (동적 위치가 아니면 null)
     */
    public function placements(string $position, int $page = 1): ?array
    {
        $resolver = $this->registry()->resolver($position);
        if ($resolver === null) {
            return null;
        }

        $summaries = $this->banners->targetSummaries($position, now());
        $bannerTargets = count($summaries);
        $settingKeys = array_flip($this->targetSettings()->targetKeys($position));
        foreach (array_keys($settingKeys) as $key) {
            $summaries[(string) $key] ??= ['total' => 0, 'active' => 0];
        }
        $total = count($summaries);
        $lastPage = max(1, (int) ceil($total / self::PLACEMENTS_PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $pageItems = array_slice($summaries, ($page - 1) * self::PLACEMENTS_PER_PAGE, self::PLACEMENTS_PER_PAGE, true);
        $keys = array_map('strval', array_keys($pageItems));

        $labels = $keys === [] ? [] : $resolver->labels($keys);
        if ($resolver instanceof TargetSiteUrlsInterface) {
            $urls = $keys === [] ? [] : $resolver->siteUrls($keys);
        } else {
            $urls = [];
            foreach ($keys as $key) {
                if (isset($labels[$key])) {
                    $urls[$key] = $resolver->siteUrl($key);
                }
            }
        }

        $data = [];
        foreach ($pageItems as $key => $counts) {
            $key = (string) $key;
            $exists = isset($labels[$key]);
            $data[] = [
                'key' => $key,
                'label' => $labels[$key] ?? null,
                'exists' => $exists,
                'site_url' => $exists ? ($urls[$key] ?? null) : null,
                'total' => $counts['total'],
                // 지워진 대상의 배너는 사이트에 나가지 않는다(공개 조회가 대상을 풀지 못한다) — 상태가 노출 중이어도 0
                'active' => $exists ? $counts['active'] : 0,
                'has_settings' => isset($settingKeys[$key]),
            ];
        }

        return [
            'position' => $position,
            'data' => $data,
            'summary' => ['banners' => array_sum(array_column($summaries, 'total')), 'targets' => $bannerTargets],
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $total, 'per_page' => self::PLACEMENTS_PER_PAGE],
        ];
    }

    /**
     * 위치 레지스트리를 꺼냅니다 — 생성자에서 쥐지 않는다. 레지스트리는 요청 단위로 공유되므로, 오래 사는 객체(라우트가 기억한 컨트롤러·
     * 작업자)가 옛 위치 목록을 붙들지 않게 쓸 때마다 컨테이너에서 받는다.
     *
     * @return PositionRegistry 위치 레지스트리
     */
    private function registry(): PositionRegistry
    {
        return app(PositionRegistry::class);
    }
}
