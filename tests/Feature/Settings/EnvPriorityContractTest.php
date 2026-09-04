<?php

namespace Tests\Feature\Settings;

use App\Support\EnvPriority;
use Tests\TestCase;

/**
 * `.env` 키 단위 우선 규약의 계약 테스트.
 *
 * 이 결함군은 오류를 남기지 않는다 — 매핑이 뒤처지거나 배선이 빠지면 잠금이 조용히
 * 미발동하고, 그 상태는 "그 키가 `.env` 에 없다"와 화면상 구분되지 않는다. 그래서
 * 판정을 행위가 아니라 **구조**(맵 패리티 · 캡처 일치 · 배선 실존)로 고정한다.
 *
 * @effects explicit_env_skips_injection, empty_string_env_not_explicit
 */
class EnvPriorityContractTest extends TestCase
{
    /**
     * 주입 대상 카테고리의 모든 defaults 키는 MAP 이나 EXEMPT 에 등재되어 있습니다.
     *
     * Provider 에 새 주입이 추가되면 이 단언이 red 가 되어, 그 키가 `.env` 대응을 갖는지
     * 판단하도록 강제합니다. 등재를 잊으면 그 키는 영원히 settings 승으로 남습니다.
     *
     * @effects map_parity_covers_every_injected_default_key
     */
    public function test_모든_주입_카테고리_키가_맵이나_면제목록에_등재된다(): void
    {
        $defaults = $this->loadDefaults();
        $unregistered = [];

        foreach (EnvPriority::INJECTED_CATEGORIES as $category) {
            foreach (array_keys($defaults[$category] ?? []) as $key) {
                $storageKey = $category.'.'.$key;

                if (! array_key_exists($storageKey, EnvPriority::MAP)
                    && ! array_key_exists($storageKey, EnvPriority::EXEMPT)) {
                    $unregistered[] = $storageKey;
                }
            }
        }

        $this->assertSame(
            [],
            $unregistered,
            'EnvPriority::MAP 또는 EXEMPT 에 등재되지 않은 설정 키: '.implode(', ', $unregistered)
        );
    }

    /**
     * MAP 의 모든 settings 키는 defaults.json 에 실존합니다.
     *
     * 반대 방향 — 오타나 사라진 키가 맵에 남아 있으면 그 항목은 영원히 잠기지 않습니다.
     *
     * @effects map_parity_covers_every_injected_default_key
     */
    public function test_맵의_모든_키가_defaults에_실존한다(): void
    {
        $defaults = $this->loadDefaults();
        $missing = [];

        foreach (array_keys(EnvPriority::MAP + EnvPriority::EXEMPT) as $storageKey) {
            [$category, $key] = explode('.', $storageKey, 2);

            if (! array_key_exists($key, $defaults[$category] ?? [])) {
                $missing[] = $storageKey;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'defaults.json 에 없는 키가 맵/면제목록에 남아 있습니다: '.implode(', ', $missing)
        );
    }

    /**
     * MAP 의 모든 config 키는 실존하는 config 경로입니다.
     *
     * 오타난 config 키는 표시값이 조용히 null 이 되고, 문서 근거도 함께 어긋납니다.
     * 런타임에 생성되는 키(`RUNTIME_CREATED_CONFIG_KEYS`)만 사유와 함께 제외합니다.
     *
     * @effects locked_plain_shows_effective_value
     */
    public function test_맵의_config_키가_실존한다(): void
    {
        $missing = [];

        foreach (EnvPriority::MAP as $storageKey => $entry) {
            foreach ($entry['config'] as $configKey) {
                if (in_array($configKey, EnvPriority::RUNTIME_CREATED_CONFIG_KEYS, true)) {
                    continue;
                }

                if (! config()->has($configKey)) {
                    $missing[] = $storageKey.' → '.$configKey;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            '실존하지 않는 config 키: '.implode(', ', $missing)
        );
    }

    /**
     * `config/env-priority.php` 의 캡처 대상이 `EnvPriority::envVars()` 와 일치합니다.
     *
     * 캡처 목록이 뒤처지면 그 env 변수는 명시해도 잠기지 않습니다 — 오류 없이.
     *
     * @effects explicit_env_skips_injection
     */
    public function test_캡처_대상이_맵의_env_변수와_일치한다(): void
    {
        $source = (string) file_get_contents(config_path('env-priority.php'));

        $this->assertStringContainsString(
            'EnvPriority::envVars()',
            $source,
            'config/env-priority.php 는 캡처 대상을 EnvPriority::envVars() 에서 도출해야 합니다 (목록 중복 기재 금지).'
        );

        // 실제 평가 결과가 맵의 변수만 다루는지도 확인한다 (SSoT 도출이 형식만 남는 것 차단).
        $captured = require config_path('env-priority.php');

        $this->assertIsArray($captured['explicit']);
        $this->assertSame(
            [],
            array_diff(array_keys($captured['explicit']), EnvPriority::envVars()),
            '캡처 결과에 맵 밖의 env 변수가 섞였습니다.'
        );
    }

    /**
     * 스위치는 `config('env-priority.enabled')` 만으로 해석됩니다.
     *
     * @effects switch_off_behaves_identically
     */
    public function test_스위치가_config_로만_해석된다(): void
    {
        $source = (string) file_get_contents(app_path('Support/EnvPriority.php'));
        $stripped = $this->stripComments($source);

        $this->assertStringNotContainsString(
            'env(',
            $stripped,
            'EnvPriority 안에서 env() 를 읽으면 config:cache 환경에서 null 로 고정되어 판정이 영구 미발동합니다.'
        );

        config(['env-priority.enabled' => true]);
        $this->assertTrue(EnvPriority::enabled());

        config(['env-priority.enabled' => false]);
        $this->assertFalse(EnvPriority::enabled());
    }

    /**
     * `SettingsServiceProvider` 의 모든 주입 지점에 잠금 필터가 배선되어 있습니다.
     *
     * 한 곳이라도 빠지면 그 카테고리만 조용히 `.env` 를 계속 덮습니다.
     *
     * 모집단은 `getCategory('<카테고리>')` 호출부에서 **도출한다** — 메서드 이름을 손으로
     * 열거하면 새 주입 메서드가 늘어도 케이스 없이 통과한다(오늘 우연히 일치할 뿐이다).
     * EXEMPT 키만 읽어 필터가 no-op 인 지점은 그 메서드 안의 `audit:allow` 사유로 면제한다.
     *
     * @effects explicit_env_skips_injection
     */
    public function test_provider_주입_지점에_잠금_필터가_배선된다(): void
    {
        $sites = $this->injectionSites();

        // 하한 — 도출식이 죽으면 이 단언이 "위반 0" 으로 위장된다.
        $this->assertNotEmpty(
            $sites,
            "getCategory('<카테고리>') 주입 지점을 하나도 도출하지 못했습니다 — 호출 형태가 바뀌었다면 이 도출식도 함께 갱신해야 합니다."
        );

        $missing = [];

        foreach ($sites as $site) {
            if (str_contains($site['body'], 'audit:allow env-priority-filter-wiring')) {
                continue;
            }

            if (! str_contains($site['body'], "EnvPriority::filterLocked('{$site['category']}'")) {
                $missing[] = "{$site['method']}() → {$site['category']}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            '잠금 필터가 배선되지 않은 주입 지점: '.implode(', ', $missing)
        );
    }

    /**
     * `INJECTED_CATEGORIES` 가 소스에서 도출한 주입 카테고리 집합과 일치합니다.
     *
     * 이 상수는 맵 패리티 검사의 모집단이다. 손으로 적힌 채 뒤처지면 새 카테고리의 키가
     * MAP/EXEMPT 등재 검사를 통째로 비껴가고, 그 상태는 "이상 0건" 과 구분되지 않는다.
     *
     * @effects map_parity_covers_every_injected_default_key
     */
    public function test_주입_카테고리_상수가_소스_도출_결과와_일치한다(): void
    {
        $derived = array_values(array_unique(array_column($this->injectionSites(), 'category')));
        sort($derived);

        $declared = EnvPriority::INJECTED_CATEGORIES;
        sort($declared);

        $this->assertNotEmpty($derived, '주입 카테고리를 하나도 도출하지 못했습니다.');
        $this->assertSame(
            $derived,
            $declared,
            'EnvPriority::INJECTED_CATEGORIES 가 Provider 의 실제 주입 카테고리와 어긋납니다 — '
            .'누락된 카테고리의 키는 MAP/EXEMPT 등재 검사를 받지 않습니다.'
        );
    }

    /**
     * `defaults.json` 이 민감으로 표시한 키는 MAP 에서도 민감으로 표시됩니다.
     *
     * 표시값 오버레이는 MAP 의 `sensitive` 플래그만 보고 건너뛴다. 그런데 민감 여부의
     * 본래 출처는 `frontend_schema` 라, 두 곳이 갈라지면 그 키의 `.env` 비밀값이
     * 관리자 응답에 조용히 실린다 — 오류도 로그도 남지 않는다.
     *
     * 반대 방향(MAP 만 민감)은 과하게 가리는 쪽이라 안전측이므로 검사하지 않는다.
     *
     * @effects locked_sensitive_hides_effective_value
     */
    public function test_스키마가_민감으로_표시한_키는_맵에서도_민감이다(): void
    {
        $schema = json_decode(
            (string) file_get_contents(config_path('settings/defaults.json')),
            true
        )['frontend_schema'] ?? [];

        $schemaSensitive = [];

        foreach ($schema as $category => $definition) {
            foreach (($definition['fields'] ?? []) as $key => $field) {
                if (($field['sensitive'] ?? false) === true) {
                    $schemaSensitive[] = $category.'.'.$key;
                }
            }
        }

        // 하한 — 스키마 형태가 바뀌어 0건이 되면 이 검사는 아무것도 재지 않는다.
        $this->assertNotEmpty(
            $schemaSensitive,
            'frontend_schema 에서 민감 키를 하나도 도출하지 못했습니다 — 스키마 형태가 바뀌었다면 이 도출식도 함께 갱신해야 합니다.'
        );

        $unflagged = [];

        foreach ($schemaSensitive as $storageKey) {
            if (! array_key_exists($storageKey, EnvPriority::MAP)) {
                continue; // 잠금 대상이 아니면 오버레이 자체가 없다
            }

            if (! EnvPriority::isSensitive($storageKey)) {
                $unflagged[] = $storageKey;
            }
        }

        $this->assertSame(
            [],
            $unflagged,
            "defaults.json 이 민감으로 표시했으나 EnvPriority::MAP 에 'sensitive' => true 가 없는 키: "
            .implode(', ', $unflagged).' — 이 키들은 잠기면 `.env` 값이 화면에 노출됩니다.'
        );
    }

    /**
     * testing 격리 가드가 런타임 `env('APP_ENV')` 로 되돌아가지 않습니다.
     *
     * config:cache 환경에서 env() 는 null 이라 가드가 통째로 무력해집니다.
     *
     * @effects switch_off_behaves_identically
     */
    public function test_provider_가_런타임_env_로_환경을_판별하지_않는다(): void
    {
        $source = (string) file_get_contents(app_path('Providers/SettingsServiceProvider.php'));
        $stripped = $this->stripComments($source);

        $this->assertStringNotContainsString(
            "env('APP_ENV')",
            $stripped,
            "환경 판별은 app()->environment() 로 합니다 — env('APP_ENV') 는 config:cache 에서 null 입니다."
        );

        $this->assertStringNotContainsString(
            "env('G7_ENV_PRIORITY')",
            $stripped,
            '스위치는 config(env-priority.enabled) 로만 읽습니다.'
        );
    }

    /**
     * `.env.example` 은 스위치를 주석 상태로 안내하고, 테스트용 env 는 활성 라인을 두지 않습니다.
     *
     * @effects env_examples_document_env_priority_switch
     */
    public function test_env_예시가_스위치를_주석으로_안내한다(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('G7_ENV_PRIORITY', $example, '.env.example 에 스위치 안내가 필요합니다.');
        $this->assertMatchesRegularExpression(
            '/^\s*#\s*G7_ENV_PRIORITY=/m',
            $example,
            '.env.example 의 스위치는 주석 상태여야 합니다 (기본 OFF).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*G7_ENV_PRIORITY=/m',
            $example,
            '.env.example 에 활성 스위치 라인을 두면 신규 설치가 즉시 잠깁니다.'
        );

        // `.env.testing.example` 은 저장소 추적 파일이라 반드시 존재한다 — 부재를 건너뛰면
        // 그 축이 공허 통과한다. 안내 문구가 사라지면 "왜 여기엔 스위치가 없는가" 의 근거가
        // 사라져, 다음 사람이 디버깅 편의로 활성 라인을 넣게 된다.
        $testingExamplePath = base_path('.env.testing.example');
        $this->assertFileExists($testingExamplePath, '.env.testing.example 은 저장소 추적 파일입니다.');
        $this->assertStringContainsString(
            'G7_ENV_PRIORITY',
            (string) file_get_contents($testingExamplePath),
            '.env.testing.example 에 스위치를 켜지 않는 사유 안내가 필요합니다.'
        );

        foreach (['.env.testing.example', '.env.testing'] as $file) {
            $path = base_path($file);

            if (! file_exists($path)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*G7_ENV_PRIORITY=/m',
                (string) file_get_contents($path),
                "{$file} 에 활성 스위치 라인을 두면 테스트가 개발 머신의 .env 상태에 좌우됩니다."
            );
        }
    }

    /**
     * `defaults.json` 의 defaults 블록을 읽습니다.
     *
     * @return array<string, array<string, mixed>> 카테고리별 기본값
     */
    private function loadDefaults(): array
    {
        $raw = (string) file_get_contents(config_path('settings/defaults.json'));

        return json_decode($raw, true)['defaults'] ?? [];
    }

    /**
     * `SettingsServiceProvider` 의 settings 카테고리 주입 지점을 소스에서 도출합니다.
     *
     * `getCategory('<카테고리>')` 호출부를 찾고 그것을 감싸는 메서드를 역으로 특정한다.
     * 메서드 이름 목록을 손으로 들고 있으면 새 주입이 늘어도 검사가 그것을 보지 못하므로,
     * 모집단은 언제나 소스에서 파생한다. audit 룰 `env-priority-filter-wiring` 과 같은 도출식이다.
     *
     * @return list<array{category: string, method: string, body: string}> 주입 지점 목록
     */
    private function injectionSites(): array
    {
        $source = (string) file_get_contents(app_path('Providers/SettingsServiceProvider.php'));

        preg_match_all("/getCategory\(\s*'([a-z_]+)'\s*\)/", $source, $matches, PREG_OFFSET_CAPTURE);

        $sites = [];
        $seen = [];

        foreach ($matches[1] as $index => $capture) {
            $category = $capture[0];
            $offset = $matches[0][$index][1];

            if (! preg_match_all('/function\s+([A-Za-z0-9_]+)\s*\(/', substr($source, 0, $offset), $heads, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $last = array_key_last($heads[1]);
            $method = $heads[1][$last][0];
            $body = $this->extractMethodBody($source, $method);

            $key = $method.':'.$category;

            if ($body === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sites[] = ['category' => $category, 'method' => $method, 'body' => $body];
        }

        return $sites;
    }

    /**
     * PHP 소스에서 주석을 제거합니다 ("하면 안 되는 것" 설명문 오탐 차단).
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
    }

    /**
     * 소스에서 지정 메서드의 본문을 잘라냅니다 (중괄호 균형 기준).
     *
     * @param  string  $source  원본 소스
     * @param  string  $method  메서드명
     * @return string 메서드 본문 (찾지 못하면 빈 문자열)
     */
    private function extractMethodBody(string $source, string $method): string
    {
        $signature = 'function '.$method.'(';
        $start = strpos($source, $signature);

        if ($start === false) {
            return '';
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
