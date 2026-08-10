<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\Gdpr\Enums\ConsentSource;

/**
 * 동의 출처 어휘 3계층 정합 가드
 *
 * 불변식: **기록 어휘(ConsentSource) = 화면 필터 옵션 = 라벨 키**
 *
 * 어긋나면 두 가지가 동시에 깨집니다 (#492 브라우저 실측에서 확인된 결함 D-35):
 * - 화면 필터에 없는 출처로 기록된 행은 어떤 필터 조합으로도 도달할 수 없다 (60행 중 20행)
 * - 라벨 키가 없는 출처는 목록 셀에 원시 키 문자열이 그대로 노출된다
 *
 * DB·라우트에 의존하지 않는 정적 검사이므로 `Tests\TestCase` 가 아니라 순수 TestCase 를 상속합니다.
 */
class ConsentSourceVocabularyParityTest extends TestCase
{
    /** 플러그인 루트 경로 */
    private function pluginPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    /**
     * 소스 코드가 실제로 기록하는 출처 값이 모두 enum 에 선언되어 있는지 검사합니다.
     *
     * enum 밖에 리터럴을 두면 화면·라벨이 그 값을 영영 모릅니다.
     */
    #[Test]
    public function 기록_경로의_출처_값이_전부_enum_에_선언되어_있다(): void
    {
        $declared = ConsentSource::allValues();

        // 실제 기록 지점 — source:/'source' =>/'last_source' => 인자로 넘어가는 리터럴
        $sources = [
            'src/Listeners/GdprAuthConsentListener.php',
            'src/Services/GdprConsentService.php',
            'src/Http/Controllers/User/GdprConsentController.php',
        ];

        $literals = [];
        foreach ($sources as $relative) {
            $code = file_get_contents($this->pluginPath($relative));

            // 기록 지점이 enum 을 참조하고 있어야 한다 — 리터럴로 되돌아가면 화면·라벨이 그 값을 모른다
            $this->assertStringContainsString(
                'ConsentSource::',
                $code,
                "{$relative} 이 출처를 enum 이 아닌 값으로 기록합니다."
            );

            preg_match_all("/(?:source:|'source'\s*=>|'last_source'\s*=>)\s*'([a-z_]+)'/", $code, $m);
            $literals = array_merge($literals, $m[1]);
        }

        foreach (array_unique($literals) as $literal) {
            $this->assertContains(
                $literal,
                $declared,
                "기록 경로가 enum 에 없는 출처 '{$literal}' 를 저장합니다 — ConsentSource 에 케이스를 추가하세요."
            );
        }
    }

    /**
     * 관리자 동의 이력 화면의 출처 필터 옵션이 enum 전 케이스를 덮는지 검사합니다.
     */
    #[Test]
    public function 화면_출처_필터_옵션이_enum_전_케이스를_덮는다(): void
    {
        $layout = file_get_contents(
            $this->pluginPath('resources/layouts/admin/gdpr_consent_log.json')
        );

        foreach (ConsentSource::allValues() as $value) {
            $this->assertStringContainsString(
                "includes('{$value}')",
                $layout,
                "출처 필터에 '{$value}' 체크박스가 없습니다 — 그 출처로 기록된 행은 어떤 필터로도 도달할 수 없습니다."
            );
            $this->assertStringContainsString(
                "consent_log.source.{$value}",
                $layout,
                "출처 필터 '{$value}' 의 라벨 바인딩이 없습니다."
            );
        }
    }

    /**
     * 모든 출처 값에 ko/en 라벨이 정의되어 있는지 검사합니다.
     *
     * 백엔드(`lang/{locale}/messages.php`)와 프론트엔드(`resources/lang/{locale}.json`)를
     * **둘 다** 봅니다. 관리자 화면의 `$t:` 는 프론트엔드 JSON 에서 해석되므로 PHP 쪽만
     * 채우면 화면에는 여전히 원시 키가 남습니다 (#492 7차에서 실제로 겪은 누락).
     */
    #[Test]
    public function 모든_출처_값에_ko_en_라벨이_정의되어_있다(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $backend = require $this->pluginPath("lang/{$locale}/messages.php");
            $frontend = json_decode(
                file_get_contents($this->pluginPath("resources/lang/{$locale}.json")),
                true
            );

            $surfaces = [
                "lang/{$locale}/messages.php" => $backend['admin']['consent_log']['source'] ?? [],
                "resources/lang/{$locale}.json" => $frontend['admin']['consent_log']['source'] ?? [],
            ];

            foreach ($surfaces as $where => $labels) {
                foreach (ConsentSource::allValues() as $value) {
                    $this->assertArrayHasKey(
                        $value,
                        $labels,
                        "{$where} 에 출처 '{$value}' 라벨이 없습니다 — 목록 셀에 원시 키가 노출됩니다."
                    );
                    $this->assertNotSame('', trim((string) $labels[$value]));
                }
            }
        }
    }

    /**
     * 정책 버전 이력 페이저가 쓰는 다국어 키가 양쪽 표면에 모두 정의되어 있는지 검사합니다.
     */
    #[Test]
    public function 정책_버전_이력_페이저_라벨이_양쪽_표면에_있다(): void
    {
        $keys = ['history_page_summary', 'history_prev', 'history_next'];

        foreach (['ko', 'en'] as $locale) {
            $backend = require $this->pluginPath("lang/{$locale}/messages.php");
            $frontend = json_decode(
                file_get_contents($this->pluginPath("resources/lang/{$locale}.json")),
                true
            );

            $surfaces = [
                "lang/{$locale}/messages.php" => $backend['settings']['policy_version'] ?? [],
                "resources/lang/{$locale}.json" => $frontend['settings']['policy_version'] ?? [],
            ];

            foreach ($surfaces as $where => $labels) {
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $labels, "{$where} 에 '{$key}' 가 없습니다.");
                }
            }

            // 치환 문법이 표면마다 다르다 — 백엔드(Laravel)는 `:name`, 프론트 엔진은 `{name}` 만 치환한다.
            // 뒤바꿔 적으면 키는 해석되지만 숫자가 자리표시자 그대로 화면에 남는다.
            foreach (['total', 'current', 'last'] as $param) {
                $this->assertStringContainsString(
                    ':'.$param,
                    (string) ($backend['settings']['policy_version']['history_page_summary'] ?? ''),
                    "lang/{$locale}/messages.php 는 Laravel 문법 ':{$param}' 을 써야 합니다."
                );
                $this->assertStringContainsString(
                    '{'.$param.'}',
                    (string) ($frontend['settings']['policy_version']['history_page_summary'] ?? ''),
                    "resources/lang/{$locale}.json 은 프론트 엔진 문법 '{{$param}}' 을 써야 합니다 — ':{$param}' 는 치환되지 않고 그대로 노출됩니다."
                );
            }
        }
    }

    /**
     * 공개 요청으로 지정 가능한 출처는 서버 자체 기록 경로를 포함하지 않아야 합니다.
     */
    #[Test]
    public function 서버_자체_기록_출처는_요청으로_지정할_수_없다(): void
    {
        $selectable = ConsentSource::requestSelectableValues();

        $this->assertNotContains(ConsentSource::MypageRenewAll->value, $selectable);
        $this->assertContains(ConsentSource::Banner->value, $selectable);
    }
}
