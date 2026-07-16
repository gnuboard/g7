# Settings 카탈로그 다국어 자동 보강

## TL;DR (5초 요약)

```text
1. settings JSON 의 다국어 카탈로그 라벨(_cached_name 등)은 카탈로그 빌드 시점에 보강
2. helper: localize_catalog_field($field, $langKey) — 단일 함수 호출
3. 모듈/플러그인 Service 가 자기 빌드 메서드 안에서 직접 호출
4. lang key segment 는 settings JSON 의 식별자(id/code/key) 와 동일
5. audit 룰 settings-multilingual-enrichment-coverage 가 호출 누락 검출 (warning)
```

## 사용법

### Helper

[`app/Helpers/locale_helpers.php`](../../app/Helpers/locale_helpers.php) 의 `localize_catalog_field()`:

```php
/**
 * Settings 카탈로그 다국어 JSON 필드에 활성 언어팩 키 자동 보강.
 *
 * 운영자 편집값(비어있지 않은 값)은 보존, 부재한 locale 만 lang pack 에서 채움.
 *
 * @param  array<string, string>  $field    ['ko' => '...', 'en' => '...']
 * @param  string                  $langKey  완전한 lang key (네임스페이스 prefix 포함)
 * @return array<string, string>             보강된 다국어 JSON
 */
function localize_catalog_field(array $field, string $langKey): array;
```

### 모듈/플러그인 Service 사용 예 (실제 EcommerceSettingsService)

```php
private function getBuiltinPaymentMethods(): array
{
    $defaults = $this->getDefaults();
    $methods = $defaults['defaults']['order_settings']['payment_methods'] ?? [];

    return array_map(function (array $method) {
        $id = $method['id'];

        return [
            'id' => $id,
            // 카탈로그 빌드 시점에 직접 보강
            'name' => localize_catalog_field(
                $method['_cached_name'] ?? ['ko' => $id, 'en' => $id],
                "sirsoft-ecommerce::settings.payment_methods.{$id}.name",
            ),
            'description' => localize_catalog_field(
                $method['_cached_description'] ?? ['ko' => '', 'en' => ''],
                "sirsoft-ecommerce::settings.payment_methods.{$id}.description",
            ),
            // ...
        ];
    }, $methods);
}
```

### 빌드 단계가 따로 없는 카탈로그 (currencies / countries)

settings 응답 빌드 메서드 안에서 inline foreach 로 호출:

```php
public function getAllSettings(): array
{
    // ... settings 머지 ...

    if (isset($settings['language_currency']['currencies'])) {
        foreach ($settings['language_currency']['currencies'] as $idx => $currency) {
            if (! empty($currency['code']) && isset($currency['name']) && is_array($currency['name'])) {
                $settings['language_currency']['currencies'][$idx]['name'] = localize_catalog_field(
                    $currency['name'],
                    "sirsoft-ecommerce::settings.currencies.{$currency['code']}.name",
                );
            }
        }
    }

    return $settings;
}
```

### Lang 파일 작성

`{module|plugin}/_bundled/{id}/{src/}lang/{ko,en}/settings.php`. **lang key 의 segment 는 settings JSON 의 식별자(id/code/key) 와 정확히 일치해야 함**.

```php
// modules/_bundled/sirsoft-ecommerce/src/lang/ko/settings.php
return [
    'payment_methods' => [
        'card' => ['name' => '신용카드', 'description' => '신용카드로 안전하게 결제'],
        'dbank' => ['name' => '무통장입금', 'description' => '지정 계좌로 직접 입금'],
        // settings JSON 의 id 를 그대로 사용
    ],
];
```

ja/zh 등 다른 locale 은 번들 lang pack 자동 빌드 (`build-language-pack.cjs`).

## 운영자 편집 보존

helper 동작:

- `field[locale]` 이 존재하고 비어있지 않으면 → 그대로 유지 (운영자 편집값 보존)
- 키 부재 또는 빈 문자열 → lang pack 에서 채움
- lang pack 에도 키 부재 → 변경 없음

운영자가 admin UI 에서 ja 라벨을 직접 입력했다면 보존. 입력 안한 locale 만 lang pack 으로 채움.

## audit 룰

`settings-multilingual-enrichment-coverage` (severity: warning):

- `{modules|plugins}/_bundled/*/config/settings/defaults.json` 에 다국어 카탈로그 entry 발견
- 같은 확장의 `src/Services/*.php` 안에 `localize_catalog_field` 호출 없음
- → warning (호출 누락 안내)

신규 모듈/플러그인 개발자가 카탈로그 추가 시 helper 호출을 빠뜨리지 않도록 안내.

## 함정 — settings JSON id 와 lang key 일치

settings JSON 의 entry 식별자(id/code/key) 와 lang 파일의 segment 가 다르면 보강이 동작하지 않음.

```php
// defaults.json
{ "id": "dbank", "_cached_name": { "ko": "무통장입금", "en": "Bank Transfer" } }

// lang/ko/settings.php — 키가 'dbank' 이어야 함
'payment_methods' => [
    'dbank' => ['name' => '무통장입금'],   // ✓ id 와 일치
    // 'bank_transfer' => [...]            // ✗ 보강 안됨
],
```

## 참고

- Helper: [`localize_catalog_field()`](../../app/Helpers/locale_helpers.php)
- 적용 사례: [`EcommerceSettingsService::getBuiltinPaymentMethods()`](../../modules/_bundled/sirsoft-ecommerce/src/Services/EcommerceSettingsService.php), `getAllSettings()` inline 보강
- Feature 테스트: [`EcommerceSettingsLocalizationTest`](../../tests/Feature/Settings/EcommerceSettingsLocalizationTest.php)
