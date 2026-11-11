# 히어로섹션 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 24종 / 호출 지점 24곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-hero_section.banner.after_bulk_delete` | action | 일괄 삭제 직후. 인자: (array $ids, array $snapshots) | `src/Services/BannerService.php:255` |
| `sirsoft-hero_section.banner.after_bulk_status` | action | 일괄 노출·비노출 직후. 인자: (array $ids, bool $isActive, array $snapshots) | `src/Services/BannerService.php:230` |
| `sirsoft-hero_section.banner.after_create` | action | 배너 등록 직후. 인자: (Banner $banner) | `src/Services/BannerService.php:134` |
| `sirsoft-hero_section.banner.after_delete` | action | 배너 영구 삭제 직후. 인자: (int $bannerId, array $snapshot) | `src/Services/BannerService.php:208` |
| `sirsoft-hero_section.banner.after_reorder` | action | 순서 변경 직후. 인자: (string $position, array $ids, ?string $targetKey) | `src/Services/BannerService.php:275` |
| `sirsoft-hero_section.banner.after_update` | action | 배너 수정 직후. 인자: (Banner $banner, array $snapshot, string $previousPosition) | `src/Services/BannerService.php:179` |
| `sirsoft-hero_section.banner.before_bulk_delete` | action | 일괄 삭제 직전. 인자: (array $ids) | `src/Services/BannerService.php:243` |
| `sirsoft-hero_section.banner.before_bulk_status` | action | 일괄 노출·비노출 직전. 인자: (array $ids, bool $isActive) | `src/Services/BannerService.php:221` |
| `sirsoft-hero_section.banner.before_create` | action | 배너 등록 직전. 인자: (array $data) | `src/Services/BannerService.php:109` |
| `sirsoft-hero_section.banner.before_delete` | action | 배너 영구 삭제 직전. 인자: (Banner $banner) | `src/Services/BannerService.php:194` |
| `sirsoft-hero_section.banner.before_reorder` | action | 순서 변경 직전. 인자: (string $position, array $ids, ?string $targetKey) | `src/Services/BannerService.php:271` |
| `sirsoft-hero_section.banner.before_update` | action | 배너 수정 직전. 인자: (Banner $banner, array $data) | `src/Services/BannerService.php:160` |
| `sirsoft-hero_section.banner.filter_create_data` | filter | 등록 값 가공. 인자: (array $data) | `src/Services/BannerService.php:110` |
| `sirsoft-hero_section.banner.filter_list_params` | filter | 관리자 목록 조건 가공. 인자: (array $filters) | `src/Services/BannerService.php:46` |
| `sirsoft-hero_section.banner.filter_update_data` | filter | 수정 값 가공. 인자: (array $data, Banner $banner) | `src/Services/BannerService.php:161` |
| `sirsoft-hero_section.banner.store_validation_rules` | filter | 등록 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/StoreBannerRequest.php:56` |
| `sirsoft-hero_section.banner.update_validation_rules` | filter | 수정 검증 규칙 가공. 인자: (array $rules, FormRequest $request) | `src/Http/Requests/Admin/UpdateBannerRequest.php:66` |
| `sirsoft-hero_section.banner_image.after_change` | action | 배너 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Banner $banner, string $device, string $change — replaced·removed) | `src/Services/BannerService.php:173` |
| `sirsoft-hero_section.banner_image.after_delete` | action | 배너 이미지 삭제 직후. 인자: (array $snapshot) | `src/Services/BannerImageService.php:154` |
| `sirsoft-hero_section.banner_image.after_upload` | action | 배너 이미지 임시 업로드 직후(배너에는 저장 때 붙는다). 인자: (BannerImage $image) | `src/Services/BannerImageService.php:134` |
| `sirsoft-hero_section.banner_image.filter_upload_file` | filter | 올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file) | `src/Services/BannerImageService.php:85` |
| `sirsoft-hero_section.hero.filter_public_banners` | filter | 사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale) | `src/Services/PublicHeroService.php:69` |
| `sirsoft-hero_section.position_setting.after_update` | action | 위치 슬라이더 설정 저장 직후. 인자: (PositionSetting $setting, array $snapshot) | `src/Services/PositionSettingService.php:152` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
배너 서비스가 `sirsoft-hero_section.banner.{before,after}_{create,update,delete,bulk_status,bulk_delete,reorder}`·`filter_create_data`·`filter_update_data`·`filter_list_params`, 공개 노출 가공용 `sirsoft-hero_section.hero.filter_public_banners` 를 발행한다 — 다른 확장은 이 훅으로 배너 저장을 막거나 노출 목록을 바꾼다. 순서 훅(`before_reorder`·`after_reorder`)의 셋째 인자와 `filter_public_banners` 의 넷째 인자는 동적 위치의 대상 키다(정적 위치는 null). 앞 인자는 그대로라 기존 구독자는 바꾸지 않아도 된다. 배너 훅의 배너·스냅샷에는 `target_key` 가 함께 실린다.

### 위치 등록 필터 `sirsoft-hero_section.position.registered_positions`

노출 위치 목록은 이 필터로 모은다(옛 고정 목록 `HeroPosition` 은 없앴다). 히어로가 우선순위 1로 내장 위치를 맨 앞에 넣고 — 사이트 홈 `home_hero`·쇼핑몰 메인 `shop_hero`(정적), 게시판 모듈이 켜져 있으면 게시판 목록 `board_hero`, 이커머스 모듈이 켜져 있으면 상품 상세 `product_hero`·카테고리 목록 `category_hero`(동적) — 다른 모듈·플러그인은 같은 필터로 위치를 더한다. 위치 키는 사용자 템플릿 확장점 이름이다.

| 필드 | 필수 | 설명 |
|---|---|---|
| `key` | ✅ | 위치 키 = 확장점 이름. 영문 소문자로 시작하고 소문자·숫자·`_` 2~50자(`/^[a-z][a-z0-9_]{1,49}$/`) |
| `label` | ✅ | 관리자 탭 이름 — 화면 문구 키(문자열) 또는 언어별 객체(`{"ko": "…", "en": "…"}`) |
| `order` | | 탭 순서(작을수록 앞, 기본 1000). 내장은 10·20(정적)·100·110·120(동적) |
| `target` | | 있으면 동적 위치 — 배너가 대상마다 따로다. `type`(대상 종류 키) · `label`(대상 이름, 문구 키 또는 언어별 객체) · `resolver`(대상 해석기 클래스, `Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface` 구현) · `route_param`(선택 — 화면 주소에서 대상 값을 읽을 경로 매개변수 이름, 예 `code` 면 `route.code`. 영문·숫자·`_` 1~50자. 있으면 히어로가 사이트 자리를 직접 채운다 — 아래 절) |

```php
// 다른 플러그인의 리스너 — 이벤트 페이지마다 다른 배너를 두는 동적 위치
public static function getSubscribedHooks(): array
{
    return ['sirsoft-hero_section.position.registered_positions' => ['method' => 'register', 'type' => 'filter']];
}

public function register(array $positions): array
{
    $positions[] = [
        'key' => 'event_hero',
        'label' => ['ko' => '이벤트 페이지', 'en' => 'Event page'],
        'order' => 200,
        'target' => ['type' => 'event', 'label' => ['ko' => '이벤트', 'en' => 'Event'], 'resolver' => EventTargetResolver::class, 'route_param' => 'code'],
    ];

    return $positions;
}
```

대상 해석기(`TargetResolverInterface`)는 다섯 가지를 한다 — `resolve($value)`: 화면이 아는 값(slug·코드·id)을 저장 키로(내장 해석기는 slug·상품 코드를 먼저, 없을 때만 숫자 id — 화면 주소가 가리키는 대상과 어긋나지 않게 같은 순서를 권한다), `exists($key)`: 저장 때 대상이 있는지, `labels($keys)`: 관리자 목록의 대상 이름(여러 개를 한 번에), `search($keyword, $limit)`: 관리자 대상 고르기 후보(`key`·`label`·`hint`), `siteUrl($key)`: 「사이트에서 보기」 주소. 저장 키는 문자열이면 된다 — `&`·`=`·`%` 같은 기호가 있어도 관리자 화면이 주소에 실을 때 인코딩한다. 해석기는 컨테이너로 만들어지므로 생성자 주입을 쓴다. 대상 고르기 목록은 히어로 조회 권한으로 대신 받아 준다(`GET /admin/positions/{position}/targets`) — 대상 모듈의 관리 권한이 없는 히어로 관리자도 고를 수 있다.

선택 계약 `Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface`(`siteUrls(array $keys): array`)를 함께 구현하면 관리자 「대상 목록」(배너가 있는 대상을 이름·사이트 주소·전체·노출 중 건수와 함께 보이는 첫 화면)이 한 쪽(20개)의 사이트 주소를 한 번에 묻는다. 구현하지 않으면 그 쪽 안의 키만 `siteUrl()` 로 하나씩 묻는다. 대상 목록·대상별 관리·「대상 목록으로」 흐름은 내장 동적 위치와 다른 확장이 더한 동적 위치가 같다.

- 어긋난 정의(키 형식 위반·이미 있는 키·이름 없음·대상 정의 불완전·해석기 계약 위반·`route_param` 형식 위반)는 그 정의만 버리고 경고 로그를 남긴다. 화면은 깨지지 않는다. 같은 키는 먼저 온 쪽이 이긴다 — 내장 키를 가로챌 수 없다.
- 슬라이더 설정(자동 넘김·효과·표시 폭 등)은 위치 단위다 — 동적 위치의 모든 대상에 함께 적용된다.
- 위치를 더한 확장을 끄면 그 위치는 목록에서 빠지지만 배너는 지우지 않는다. 관리자 목록 끝에 「등록되지 않은 위치」 탭으로 남아 확인·수정·삭제만 되고 사이트에는 나가지 않는다. 다시 켜면 그대로 보인다. 대상이 지워져도(게시판 삭제 등) 배너는 남고 「없는 대상」으로 보인다.
- 위치 목록은 요청마다 다시 모은다(모듈을 켜고 끄면 바로 따른다). 한 요청 안에서는 한 번만 모은다.
- 공개 API 는 화면 값을 푼 결과(`resolve`)를 캐시 시간 동안 기억한다(풀지 못한 값은 기억하지 않는다). 내장 동적 위치는 게시판·상품·카테고리의 생성·수정·삭제 훅(`sirsoft-board.board.after_*`·`sirsoft-ecommerce.product.after_*`·`sirsoft-ecommerce.category.after_*`)을 받아 그 위치의 기억을 바로 버린다. 확장이 더한 동적 위치에서 대상 주소 값이 바뀌거나 대상이 지워질 때 바로 반영하려면 `app(PublicHeroService::class)->forgetTargets('위치 키')` 를 부른다 — 부르지 않으면 캐시 시간(최대 60분) 뒤에 반영된다.

### 다른 확장이 위치를 더할 때 — 사이트 화면까지

필터 등록은 **위치를 선언하는 일**이고, 사이트에서 배너가 보일 **자리는 템플릿이 연다**. 필터만 등록한다고 아무 화면에나 배너가 끼워지지 않는다. 위치를 더한 확장이 하는 일은 둘이다 — 히어로 코드를 고치거나 히어로 조각을 복사하지 않는다.

1. **위치 등록** — 위 필터로 `key`·`label`(동적이면 `target` 과 `target.route_param`)을 더한다.
2. **자리 열기** — 배너가 붙을 화면 레이아웃(자기 확장의 화면이면 그 레이아웃, 템플릿 화면이면 템플릿) 본문 맨 앞에 위치 키와 같은 이름의 확장점을 둔다.

   ```html
   <extension-point name="acme_event_main_hero" contentClass="max-w-7xl mx-auto px-4"><default/></extension-point>
   ```

   히어로가 켜져 있으면 레이아웃을 낼 때 이 자리에 내장 위치와 같은 출력 조각을 붙인다 — 공개 API 조회(로그인했으면 토큰을 실어 수정 권한까지), 받는 동안 스켈레톤(정적 위치는 첫 로드 힌트, 동적 위치는 대상별 브라우저 기억), 배너 없음·조회 실패면 아무것도 그리지 않음, 현재 언어 문구, 관리 권한 회원의 「관리자에서 수정」 단추, 위치 설정의 표시 폭·메뉴 겹침, SPA 로 다른 대상으로 옮길 때 다시 조회하고 앞 대상 응답을 가리는 처리까지 같다. 조각은 내장 조각 파일(`home_hero`·`board_hero`)이 원본이라 내장 위치를 고치면 함께 바뀐다. 확장점 속성(`contentClass`·`contentClassDesktop`)은 아래 「표시 폭」 계약대로 본문 상자 클래스로 쓴다. 동적 위치는 `route_param` 이 가리키는 주소 값(`route.code` 등)을 대상으로 넘긴다.

   예 — 이벤트 플러그인이 이벤트 메인(정적)과 이벤트 상세(동적, 주소 `/events/{code}`)에 배너를 두는 경우:

   ```php
   public function register(array $positions): array
   {
       $positions[] = ['key' => 'acme_event_main_hero', 'label' => ['ko' => '이벤트 메인', 'en' => 'Event main'], 'order' => 200];
       $positions[] = ['key' => 'acme_event_page_hero', 'label' => ['ko' => '이벤트 상세', 'en' => 'Event detail'], 'order' => 210,
           'target' => ['type' => 'event', 'label' => ['ko' => '이벤트', 'en' => 'Event'], 'resolver' => EventTargetResolver::class, 'route_param' => 'code']];

       return $positions;
   }
   ```

   ```html
   <!-- 이벤트 메인 화면 레이아웃 -->
   <extension-point name="acme_event_main_hero"><default/></extension-point>
   <!-- 이벤트 상세 화면 레이아웃 (경로 /events/:code) -->
   <extension-point name="acme_event_page_hero"><default/></extension-point>
   ```

3. **배너 관리·확인** — 관리자 「히어로섹션 관리」에 위치 탭(이벤트 메인·이벤트 상세)이 생긴다. 등록·수정·순서 변경·미리보기·위치별 슬라이더 설정·검증·권한은 내장 위치와 같다(동적 위치는 대상을 먼저 고른다). 배너를 등록하고 그 화면을 열어 보이는지 확인한다.

자리 안에 다른 위치 자리를 두거나, 표·카드 셀·펼침 행·하위 행 같은 반복 템플릿 안에 자리를 두어도(코어가 확장점을 처리하는 자리) 각 자리를 한 번씩 채운다. 자리를 채우지 않는 경우 — 이미 그 위치의 히어로 슬라이더가 든 자리(내장 위치, 확장이 자기 조각을 둔 자리. 위치를 바인딩 식으로 준 슬라이더·반복 템플릿 셀 안의 슬라이더도 그 자리 것으로 본다. 안쪽 다른 자리의 슬라이더는 세지 않는다), 레이아웃에 같은 id(`heroSection_{위치 키}`)의 다른 데이터 소스가 있는 자리(경고 로그), 레이아웃 편집기에서 연 화면(붙인 노드가 편집 저장으로 레이아웃에 섞이지 않게), 등록되지 않은 위치(확장을 껐을 때), `route_param` 없는 동적 위치. 히어로를 끄면 채우는 처리도 빠져 자리는 비고(확장점의 `default` 가 보인다), 관리자에게는 히어로가 없으니 탭도 없다.

| 관리자에서 보이는 것 | 뜻 | 할 일 |
|---|---|---|
| 위치 탭이 있음 | 위치가 등록됨(위치를 더한 확장이 켜져 있음) | — |
| 「지금 쓰는 사이트 템플릿에서 이 배너 자리를 찾지 못했습니다」 | 활성 사용자 템플릿에 그 이름의 확장점이 없음 | 템플릿(또는 자기 화면 레이아웃)에 자리를 연다 |
| 「…route_param 을 알려 주지 않아…」 | 동적 위치인데 대상 값 경로가 없어 히어로가 자리를 채우지 않음 | `target.route_param` 을 등록하거나 자기 조각을 둔다 |
| 「등록되지 않은 위치」 탭 | 위치를 더한 확장이 꺼짐 — 배너는 남아 있고 사이트에 나가지 않음 | 확장을 다시 켜면 그대로 보인다 |
| 「지금 노출 중 0개」 | 자리는 있으나 노출 중인 배너 없음(동적 위치는 그 대상에) | 배너를 등록하거나 기간·사용 여부를 확인한다 |

그 밖의 의무:

- **의존 선언** — 히어로 없이는 의미가 없는 화면이면 `dependencies` 에, 히어로가 있을 때만 배너를 붙이는 선택 기능이면 `integrations` 에 `sirsoft-hero_section` 을 적는다.
- **대상 변경 반영(동적 위치)** — 대상의 주소 값이 바뀌거나 대상이 지워질 때 `app(PublicHeroService::class)->forgetTargets('위치 키')` 를 부른다(위 목록 참고).
- **이름** — `label` 을 언어별 객체로 주면 관리자 탭과 활동 로그 목록에 그 이름이 보인다. 화면 문구 키(문자열)로 주면 관리자 탭은 번역되지만, 활동 로그(서버 문구)는 그 키를 읽지 못해 위치 키가 그대로 보인다.
- **자기 조각을 둘 때** — 출력 모양을 바꾸거나 대상 값을 주소 매개변수 하나로 읽을 수 없으면, 자기 확장의 `resources/extensions/` 에 그 확장점을 겨냥한 조각을 둔다. 그 자리에 히어로 슬라이더가 있으면 히어로는 채우지 않는다. 히어로가 붙이는 조각(`resources/js/__tests__/fixtures/generated_acme_event_hero.json`·`generated_acme_event_page_hero.json`)을 출발점으로 data-source 이름·위치 키를 자기 것으로 바꾸고, 히어로가 꺼졌을 때 그리지 않게 `data-if="{{_global.plugins?.['sirsoft-hero_section'] != null}}"` 를 붙인다(예: `third_party_event_hero.json`·`third_party_event_page_hero.json`).

히어로가 붙이는 조각과 위 표본은 시험이 지킨다 — 빌더 출력이 내장 조각과 같은지, 표본이 빌더 출력과 같은지, 실제 레이아웃 병합이 자리(중첩·반복 템플릿 포함)를 채우는지, 엔진이 그 조각과 실제 슬라이더로 배너를 그리는지 본다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.layout_extension.after_apply` | filter | `PositionOutputListener` | `fill` | 50 |
| `core.modules.after_activate` | action (미선언) | `SlotWidthHintListener` | `onExtensionEvent` | 20 |
| `core.modules.after_deactivate` | action (미선언) | `SlotWidthHintListener` | `onExtensionEvent` | 20 |
| `core.modules.after_uninstall` | action (미선언) | `SlotWidthHintListener` | `onExtensionEvent` | 20 |
| `core.modules.after_update` | action (미선언) | `SlotWidthHintListener` | `onExtensionEvent` | 20 |
| `core.plugin_settings.after_reset` | action (미선언) | `HeroCacheListener` | `onPluginSettingsSaved` | - |
| `core.plugin_settings.after_reset` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugin_settings.after_save` | action (미선언) | `HeroCacheListener` | `onPluginSettingsSaved` | - |
| `core.plugin_settings.filter_save_data` | filter | `SlotWidthHintListener` | `fillHint` | 20 |
| `core.plugins.after_activate` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugins.after_deactivate` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugins.after_uninstall` | action (미선언) | `SlotWidthHintListener` | `onPluginUninstall` | 20 |
| `core.plugins.after_update` | action (미선언) | `SlotWidthHintListener` | `onPluginEvent` | 20 |
| `core.plugins.before_uninstall` | action (미선언) | `PluginLifecycleListener` | `beforeUninstall` | 10 |
| `core.settings.after_save` | action (미선언) | `HeroCacheListener` | `onCoreSettingsSaved` | - |
| `core.thumbnail.filter_presets` | filter | `ThumbnailRegistrationListener` | `addPreset` | 10 |
| `core.thumbnail.filter_source_providers` | filter | `ThumbnailRegistrationListener` | `addSourceProvider` | 10 |
| `core.thumbnail.filter_url_patterns` | filter | `ThumbnailRegistrationListener` | `addUrlPattern` | 10 |
| `sirsoft-hero_section.banner.after_bulk_delete` | action (미선언) | `BannerActivityLogListener` | `handleBulkDelete` | 20 |
| `sirsoft-hero_section.banner.after_bulk_delete` | action (미선언) | `HeroCacheListener` | `onBulkDelete` | - |
| `sirsoft-hero_section.banner.after_bulk_delete` | action (미선언) | `SlotWidthHintListener` | `onBannerChange` | 20 |
| `sirsoft-hero_section.banner.after_bulk_status` | action (미선언) | `BannerActivityLogListener` | `handleBulkStatus` | 20 |
| `sirsoft-hero_section.banner.after_bulk_status` | action (미선언) | `HeroCacheListener` | `onBulkStatus` | - |
| `sirsoft-hero_section.banner.after_bulk_status` | action (미선언) | `SlotWidthHintListener` | `onBannerChange` | 20 |
| `sirsoft-hero_section.banner.after_create` | action (미선언) | `BannerActivityLogListener` | `handleCreated` | 20 |
| `sirsoft-hero_section.banner.after_create` | action (미선언) | `HeroCacheListener` | `onBannerSaved` | - |
| `sirsoft-hero_section.banner.after_create` | action (미선언) | `SlotWidthHintListener` | `onBannerChange` | 20 |
| `sirsoft-hero_section.banner.after_delete` | action (미선언) | `BannerActivityLogListener` | `handleDeleted` | 20 |
| `sirsoft-hero_section.banner.after_delete` | action (미선언) | `HeroCacheListener` | `onBannerDeleted` | - |
| `sirsoft-hero_section.banner.after_delete` | action (미선언) | `SlotWidthHintListener` | `onBannerChange` | 20 |
| `sirsoft-hero_section.banner.after_reorder` | action (미선언) | `BannerActivityLogListener` | `handleReordered` | 20 |
| `sirsoft-hero_section.banner.after_reorder` | action (미선언) | `HeroCacheListener` | `onReorder` | - |
| `sirsoft-hero_section.banner.after_update` | action (미선언) | `BannerActivityLogListener` | `handleUpdated` | 20 |
| `sirsoft-hero_section.banner.after_update` | action (미선언) | `HeroCacheListener` | `onBannerUpdated` | - |
| `sirsoft-hero_section.banner.after_update` | action (미선언) | `SlotWidthHintListener` | `onBannerChange` | 20 |
| `sirsoft-hero_section.banner_image.after_change` | action (미선언) | `BannerActivityLogListener` | `handleImageChanged` | 20 |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `BannerActivityLogListener` | `handlePositionSetting` | 20 |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `HeroCacheListener` | `onPositionSetting` | - |
| `sirsoft-hero_section.position_setting.after_update` | action (미선언) | `SlotWidthHintListener` | `onPositionSetting` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
활동 로그에는 배너 id 만 저장하고 표시 때 이름으로 바꾼다 — 삭제된 배너도 기록 시점 제목 스냅샷으로 읽히게 하려는 것이다. 다른 확장의 설명 키는 건드리지 않는다. 위치는 키(`board_hero`)로 저장하고 표시 때 위치 이름으로 바꾸며(언어별 이름으로 더한 위치는 그 이름, 이름을 모르는 위치는 키 그대로), 동적 위치 기록은 기록 시점 대상 이름(`properties.target`, 없으면 대상 키)을 목록 한 줄 끝에 「(대상: …)」로 덧붙인다 — 목록에서 상세를 열지 않고도 어느 자리의 일인지 알아보게.

활동 로그 리스너는 큐로 돈다(워커가 돌 때 실행). 그래서 일괄 노출·비노출과 일괄 삭제는 DB 를 다시 읽지 않고 훅의 변경 전 스냅숏(`after_bulk_status`·`after_bulk_delete` 의 마지막 인자)으로 건마다 기록한다 — 워커가 돌기 전에 지우거나 다시 고친 배너도 바뀐 그때의 값으로 남는다. 이 훅을 구독하는 다른 확장도 같은 인자를 쓴다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BannerActivityLogListener` | 8개 | 명시 등록 | ✅ | `src/Listeners/BannerActivityLogListener.php` |
| `HeroCacheListener` | 10개 | 명시 등록 | ✅ | `src/Listeners/HeroCacheListener.php` |
| `PluginLifecycleListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PluginLifecycleListener.php` |
| `SlotWidthHintListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/SlotWidthHintListener.php` |
| `ThumbnailRegistrationListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/ThumbnailRegistrationListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
플러그인 리스너는 자동 발견되지 않으므로 `plugin.php` `getHookListeners()` 에 올린 것만 동작한다. 공개 캐시 비우기(`HeroCacheListener`)는 `sync` 로 즉시 돈다 — 저장 직후 사용자 화면에 옛 배너가 남지 않게. 데이터까지 지우는 제거의 이미지 폴더 정리는 리스너가 아니라 `Plugin::uninstall()` 이 맡는다 — `core.plugins.before_uninstall` 은 제거 가드보다 먼저 발화해 거기서 지우면 제거가 막혀도 파일만 사라진다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/board_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `board_hero` |
| `resources/extensions/category_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `category_hero` |
| `resources/extensions/home_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `home_hero` |
| `resources/extensions/product_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `product_hero` |
| `resources/extensions/shop_hero.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `shop_hero` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
사용자 템플릿 확장점 `home_hero`(사이트 홈 상단)·`shop_hero`(쇼핑몰 메인 상단)과 동적 위치 `board_hero`(게시판 목록)·`product_hero`(상품 상세)·`category_hero`(카테고리 목록)에 슬라이더 조각을 `append` 로 주입한다. 확장점 이름 = 배너 노출 위치 키다(위치 등록 필터의 `key`). 다른 템플릿도 본문 맨 앞에 같은 이름의 빈 슬롯 `<extension-point name="home_hero"><default/></extension-point>` 만 두면 배너가 붙는다 — 주입이 없으면 아무것도 그리지 않는다.

- **동적 위치 조각(대상 전달)** — 조각의 data-source 가 화면 주소 값을 대상으로 넘긴다: `board_hero`·`category_hero` 는 `route.slug`, `product_hero` 는 `route.product_code`(`GET …/hero?position=board_hero&target={{route?.slug}}`). 엔진은 주소 값이 바뀌면 data-source 를 다시 부르므로 SPA 로 다른 게시판·상품으로 옮겨도 맞는 배너가 보인다(컴포넌트 마운트 때 한 번 부르는 방식은 같은 레이아웃 안 이동에서 다시 부르지 않는다 — 2026-11-09 실측). 응답은 받은 화면 값(앞뒤 공백만 걷은 값)을 `target` 으로 되돌려 주고, 조각은 그 값이 지금 주소 값과 같을 때만 배너를 넘긴다 — 새 응답 전 앞 대상 배너가 보이지 않는다. 경로 이름이 다른 템플릿은 그 템플릿이 조각을 덮어써 값을 맞춘다. sirsoft-basic 은 `board/index`·`shop/show`·`shop/category` 레이아웃의 `page_top` 슬롯에 자리를 둔다(본문 상자 값은 쇼핑몰 메인과 같다).
- **동적 위치의 첫 로드(D8)** — 대상마다 첫 로드 힌트(`slot_*`)를 줄 수 없어 동적 조각은 힌트를 넘기지 않고 슬라이더에 `target`(화면 값)을 넘긴다. 슬라이더는 브라우저 기억을 `{위치}:{대상}` 마다 두고, 그 기억이 「배너 있음」일 때만 받는 동안 스켈레톤을 그린다 — 첫 방문과 배너 없는 대상은 자리를 잡지 않는다.

- **표시 폭(본문 폭·화면 전체)** — 운영자가 위치마다 고른다(위치 설정 `width`, 기본 `content`). 화면 전체를 쓰려면 템플릿이 자리를 본문 상자(여백·최대 폭) **밖**에 두고, 본문 상자 클래스를 확장점 속성으로 넘긴다: `contentClass`(기본 폭) · `contentClassDesktop`(데스크톱 1024px 이상, 없으면 `contentClass`). 예(sirsoft-basic): `<extension-point name="home_hero" contentClass="px-4" contentClassDesktop="max-w-7xl mx-auto px-8"><default/></extension-point>` 를 `_user_base` 의 본문 앞 전폭 슬롯 `page_top` 에 둔다. 조각은 이 값을 `HeroSlider` 의 `contentClass` 로 넘기고(`<responsive on="desktop">` 로 데스크톱 값), 슬라이더는 본문 폭이면 그 상자로 감싸고, 화면 전체면 끝까지 그리되 제목·넘김 단추만 그 상자에 맞춘다(높이 상한 640px — 위치 설정의 기기별 최대 높이 `max_height_pc`·`max_height_mobile` 을 정하면 두 폭 모두 그 값에서 멈춘다). 클래스는 템플릿 자기 CSS 에 있는 값이어야 한다. 자리를 본문 상자 **안**에 두고 속성을 넘기지 않는 템플릿은 두 설정 모두 자리 폭 그대로다(종전 동작).

- **메뉴 겹침(템플릿 계약)** — 위치 설정 `header_overlay` 를 켜고 표시 폭이 화면 전체면 사용자 슬라이더가 문서 뿌리에 `data-header-overlay="dark|light"`(지금 장의 바탕 밝기 `presentation.tone`)를 달고, 사라지거나 0건이면 지운다(편집 미리보기는 달지 않는다). 받는 동안은 힌트 `_global.plugins['sirsoft-hero_section'].slot_overlays.{위치}`(조각이 `skeletonOverlay` 로 넘김)를 따른다. 이 신호를 읽는 템플릿은 헤더를 배너 위에 투명하게 겹치고 보이는 헤더 높이를 CSS 변수 `--g7-header-height`(px)로 문서 뿌리에 알린다 — 슬라이더는 메뉴 겹침일 때 문구 띠 위 여백에 이 값을 더한다. 신호를 읽지 않는 템플릿은 변수가 없어(0) 화면이 겹침 끔과 같다. sirsoft-basic 구현: 자리 `page_top`(`g7-page-top`)을 헤더 높이만큼 위로 당기고, 화면 맨 위에서만 `data-header-overlay-active` 로 데스크톱 헤더·모바일 툴바(`g7-site-header`)를 투명하게 한다.
- 조각은 공개 API `GET /api/plugins/sirsoft-hero_section/hero?position=…` 를 data-source `heroSectionHome`·`heroSectionShop` 으로 받아(인증 없음) 배너가 있을 때만 슬라이더를 그린다. 0건·받기 실패면 숨긴다(fallback null). 언어를 바꾸면 화면이 새로 마운트되며 새 언어로 다시 받는다.
- 디자인을 바꾸려면 템플릿 오버라이드(템플릿 루트 기준 src/components/overrides/sirsoft-hero_section/HeroSlider.tsx)로 `sirsoft-hero_section.HeroSlider` 를 대체한다. props 계약: `banners`(공개 API 배열 — `id`·`title`·`description`·`button_text`·`image_alt`·`title_visible`·`title_color`·`title_align`·`description_*`·`link_url`·`link_new_window`·`images{pc{url,width,height},mobile|null}`), `settings`(`autoplay`·`interval_seconds`·`effect` slide·fade·cube·flip·coverflow·creative·none·`width` content·full·`header_overlay`·`pagination` dots·progress·tabs·fraction·pill·`max_height_pc`·`max_height_mobile` px 또는 null), `position`(위치 키), `contentClass`(템플릿 본문 상자 클래스 — 지금 화면 폭에 맞는 값), `loading`(공개 API 응답 전이면 true — 배너가 없으면 같은 크기의 스켈레톤 `section.hs-slider.hs-slider--skeleton` 을 그린다), `skeletonWidth`(받는 동안의 표시 폭 힌트 `content`·`full`), `skeletonOverlay`(받는 동안 메뉴 겹침 힌트), `skeletonMaxHeight`(받는 동안 기기별 최대 높이 힌트 `{pc, mobile}` — `slot_max_heights.{위치}`), `className`. 배너 객체에는 머리글 `eyebrow`·`eyebrow_visible`·`eyebrow_color` 도 있다. 배너가 0건이면 컴포넌트가 스스로 아무것도 그리지 않는다(조각에 `if` 를 두지 않는다 — 두면 받는 동안 자리를 잡지 못한다). 문구는 띠 `.hs-slider__text`(세로 위치) 안의 상자 `.hs-slider__textbox`(폭·가로 위치·등장 효과 `hs-slider__textbox--{효과}`) > `.hs-slider__scroll` > 줄 `.hs-slider__line`(머리글 `.hs-slider__eyebrow`·제목·설명·버튼) 구조이고, 화면 전체 폭에서는 `.hs-slider__text.hs-slider__text--boxed > .hs-slider__box > .hs-slider__textbox > .hs-slider__scroll`, 일시정지 단추는 `.hs-slider__controls-layer > .hs-slider__controls-box > .hs-slider__controls--boxed` 구조다. 좌우 화살표 `button.hs-slider__arrow.hs-slider__prev`·`button.hs-slider__arrow.hs-slider__next`(루트에 `hs-slider--portable` 이 없을 때만 보임)와 점 표시 `.hs-slider__bullets > button.hs-slider__bullet`(현재 장 `aria-current`)는 `section.hs-slider` 바로 아래에 둔다. 플러그인 CSS 는 화면 폭 미디어를 보지 않는다 — 대체 컴포넌트가 이 CSS 의 휴대폰·태블릿 모양을 그대로 쓰려면 루트에 `hs-slider--mobile`(767px 이하)·`hs-slider--portable`(1023px 이하)을 직접 붙인다(판정은 `G7Core.useResponsive()` 폭으로).
- 검색 봇 화면은 확장 composite 를 그리지 않을 수 있다 — 배너는 색인할 본문이 아니라 허용한다.
- **7.2.0 전 기본 템플릿을 복사해 쓰는 사이트** — 복사본의 사이트 홈·쇼핑몰 메인에는 `home_hero`·`shop_hero` 자리가, 게시판 목록·상품 상세·카테고리 목록에는 `board_hero`·`product_hero`·`category_hero` 자리가 없어 배너를 등록해도 보이지 않는다(오류는 없고 화면은 그대로다). 동적 위치 자리는 복사한 `board/index`·`shop/show`·`shop/category` 레이아웃의 `page_top` 슬롯(없으면 본문 맨 앞)에 같은 방식으로 빈 확장점을 둔다 — 대상은 조각이 화면 주소에서 읽으므로 확장점에 넘길 값은 없다. 코어가 자리를 대신 끼워 주지 않으므로, 복사한 템플릿의 `home` 레이아웃과 `shop/index` 레이아웃 본문 맨 앞에 빈 슬롯 `<extension-point name="home_hero"><default/></extension-point>`·`<extension-point name="shop_hero"><default/></extension-point>` 를 직접 넣는다. 화면 전체 폭과 메뉴 겹침까지 쓰려면 위 「표시 폭」·「메뉴 겹침」 계약대로 자리를 본문 상자 밖에 두고 헤더가 신호를 읽게 해야 한다 — 7.2.0 sirsoft-basic 의 `_user_base`·`home`·`shop/index` 레이아웃과 헤더 컴포넌트가 그 예다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
자체 미들웨어가 필요 없다 — 관리자 API 는 코어 `permission:` 미들웨어, 공개 API 는 throttle 만 쓴다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
실시간 갱신이 필요 없어 채널을 쓰지 않는다 — 배너 변경은 다음 조회 때 반영되고, 공개 캐시는 변경 훅에서 지운다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `prune-temp-images` | `0 0 * * *` | 임시 배너 이미지 삭제 |
<!-- @generated:schedules END -->

<!-- @intent START -->
고아 임시 이미지 정리(`sirsoft-hero_section:prune-temp-images`, 매일 0시, 이커머스 임시 상품 이미지 정리와 같은 방식)를 등록한다. 노출 시작·종료는 스케줄이 아니라 조회 시점 계산이라 cron 이 필요 없다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
알림을 보내지 않는다 — 배너 노출은 운영자가 화면에서 확인한다.
<!-- @intent END -->

## 표시 설정·관리자 미리보기 계약

`HeroSlider`의 배너 객체는 선택적 `presentation`을 받습니다. `pc_x`, `pc_y`, `mobile_x`, `mobile_y`는 0~100의 이미지 초점이며 `overlay`는 none/dark/light입니다. PC와 모바일 표시 비율은 각각 1920×640·750×1000으로 고정해 순서 변경이 영역 높이를 바꾸지 않습니다. 문구 값은 `text_{pc|mobile}_{x|y}`(0~100 — 띠의 세로 위치와 상자의 가로 위치), `text_width`(30~100%), `animation`(none·rise·fade·left·right·zoom·blur·letters)·`animation_speed`(slow 1초·normal 0.6초·fast 0.35초)·`animation_delay`(0~2초), `tone`(dark·light)입니다. 등장 효과는 활성 장이 될 때마다 줄마다 0.2초씩 늦게 재생하고, 움직임 줄이기면 끕니다. 템플릿 오버라이드도 이 계약을 반영해야 합니다.

`HeroSlider` 의 편집 전용 속성: `onTextMove(spot, device)`(주면 문구 상자를 끌거나 화살표 1%·Shift 10% 로 옮기고 놓을 때 알린다 — `forceDevice` 와 함께), `replay`(값이 바뀌면 등장 효과를 다시 재생). `HeroBannerPreview` 는 이를 `onTextPositionChange(patch)`(`{ text_{기기}_x, text_{기기}_y }`)로 편집 화면에 알리고 효과가 있으면 「효과 다시 보기」 단추를 둔다. `HeroTextPositionPad`(9칸 빠른 선택·가로/세로 숫자·기기 전환, `onChange(patch)`·`onDeviceChange(device)`)도 플러그인이 등록한다.

관리자 전용 `HeroBannerPreview`는 기존 banners뿐 아니라 원본 다국어 draft, locale, defaultLocale을 받습니다. `onContentLocaleChange`는 실제로 바뀐 언어 한 개를 알리고, 대상 배너가 바뀌는 초기화는 편집으로 알리지 않습니다. `HeroPeriodHint`·`HeroPreviewLanguage`도 플러그인이 등록하며 사용자 컴포넌트 팔레트에는 노출하지 않습니다.

`HeroBannerPreview`는 선택적 `frame`(위치 설정 응답 `editor.preview_frame`)과 `position`(`home_hero`·`shop_hero`)을 받아 실제 사이트와 같은 구좌 폭에서 그립니다. 주지 않으면 종전처럼 기기 폭 전체를 씁니다.

구좌 폭을 정하는 템플릿 제작자를 위한 제약: 미리보기 틀은 활성 사용자 템플릿에서 확장점(`home_hero`·`shop_hero`)까지 내려가는 **레이아웃 노드의 정적 `className`과 `responsive`의 `className` 덮어쓰기만** 읽어, 같은 템플릿 CSS(sirsoft-basic 이면 `templates/_bundled/sirsoft-basic/dist/css/components.css`)로 다시 그립니다. 다음은 반영되지 않으므로 미리보기 폭이 실제 사이트와 어긋날 수 있습니다.

- 합성 컴포넌트가 자기 안에서 주는 여백·최대 폭(예: 내부에 `max-w-*`·`px-*`를 가진 컨테이너 컴포넌트) — 미리보기는 컴포넌트를 렌더하지 않고 노드 클래스만 씁니다.
- 노드의 `style` 속성(인라인 폭·여백)과 `{{…}}` 바인딩이 든 클래스 조각(서버가 상태 값을 모르므로 지웁니다).
- 템플릿 CSS 밖에서 오는 규칙(레이아웃별 추가 스타일시트 등).

미리보기와 실제 구좌를 맞추려면 확장점을 감싸는 본문 상자의 폭·여백을 레이아웃 노드의 정적 클래스(필요하면 `responsive` 클래스)로 두세요. 확장점을 가진 레이아웃을 읽지 못하면 그 레이아웃은 건너뛰고 오류 로그(`error` 수준 — 템플릿·레이아웃·확장점·오류, 틀 캐시 주기마다 한 번)를 남기며, 쓸 수 있는 레이아웃이 없으면 기기 폭 전체로 그립니다. 관리자 레이아웃 편집기에서 저장하면 확장 캐시 버전이 올라 틀도 바로 다시 읽습니다.

관리자 전용 `HeroConflictResolver`(`base`·`mine`·`latest`·`locales`, `onApply(merged, nextBase)`)는 수정 충돌 뒤 비교·재적용에 쓰며 역시 팔레트에 노출하지 않습니다.

슬라이드 문구 마크업은 `.hs-slider__text > .hs-slider__scroll > (.hs-slider__title, .hs-slider__description, [data-hs-line="actions"])` 입니다. 버튼 문구가 없어 슬라이드 전체가 링크인 배너는 `.hs-slider__slide > (a.hs-slider__link, .hs-slider__picture, .hs-slider__text)` 처럼 링크를 **비어 있는 형제**(슬라이드를 덮음, `aria-label`=제목)로 두고 문구는 그 위에 둡니다 — 문구를 눌러도 같은 링크로 가고, 링크 안에 다른 포커스 요소가 들어가지 않습니다. 긴 문구는 `.hs-slider__scroll` 안에서만 스크롤하고 `.hs-slider__text` 의 바깥 여백(넘김 단추 자리)으로는 지나가지 않습니다. 문구가 넘칠 때만(ResizeObserver 로 다시 잼) `.hs-slider__scroll` 에 `role="region"`·`aria-label`(배너 제목, 없으면 대체 텍스트)·`tabIndex`(보이는 장 0, 나머지 -1)를 주어 키보드로 들어가 스크롤하게 하고, 넘치지 않으면 이 속성을 두지 않습니다. 휴대폰에서 문구 영역을 세로로 밀면 문구가 스크롤되고 가로로 밀면 장이 넘어갑니다. `HeroSlider` 를 오버라이드하는 템플릿은 문구를 자르지 말고 같은 구조(또는 같은 효과)를 유지해야 합니다.
