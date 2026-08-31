<?php

namespace Tests\Unit\Support;

use Tests\Concerns\ScansPhpSourceFunctions;
use Tests\TestCase;

/**
 * PHP probe 사유 키가 사전에 실재하는지 고정합니다 (7차 G3 라운드 D-5).
 *
 * `ExecutionEnvironment` 와 `PhpBinaryResolver` 는 실패 사유를 `message_key` 로 실어
 * 보내고 「소비처가 자기 로케일로 번역한다」고 선언한다. 그런데 그 키 열 개는 **어떤
 * 언어팩에도 없었고** 번역하는 소비처도 없었다 — 계약이 선언만 남고 사문이었던 것이다.
 * 화면(P5 공정)이 붙는 순간 운영자에게 `php_probe_exec_failed` 같은 원시 키가 그대로
 * 노출됐을 상태다.
 *
 * 키 목록은 **생산자 소스에서 도출**한다. 손으로 적으면 새 사유가 생길 때 그 키가 조용히
 * 빠지고, 그것이 바로 이 검사가 막으려는 형태다.
 *
 * @scenario probe_key_source=producer_sources, locale=ko, locale=en
 */
class PhpProbeMessageKeyDictionaryTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 사유 키를 생산하는 소스 파일 (프로젝트 루트 기준 상대 경로).
     *
     * @var array<int, string>
     */
    private const PRODUCER_SOURCES = [
        'app/Support/ExecutionEnvironment.php',
        'app/Support/PhpBinaryResolver.php',
    ];

    /**
     * 사전 네임스페이스 — 소비처가 붙이는 접두사와 같아야 한다.
     */
    private const NAMESPACE_PREFIX = 'settings.';

    /**
     * 생산자 소스에서 `php_probe_*` 키를 도출한다.
     *
     * @return array<int, string> 키 목록 (중복 제거·정렬)
     */
    private function producedKeys(): array
    {
        $found = [];

        foreach (self::PRODUCER_SOURCES as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, '사유 키 생산자 소스가 없다: '.$relative);

            // 주석 속 키 예시가 모집단에 섞이면 사전에 없는 유령 키가 red 를 낸다.
            $source = $this->sourceWithoutComments($path);

            if (preg_match_all("/'(php_probe_[a-z0-9_]+)'/", $source, $matches)) {
                foreach ($matches[1] as $key) {
                    $found[$key] = true;
                }
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * 도출된 키가 **기저 로케일**(ko·en)에서 해석된다.
     *
     * 이름과 `@effects` 를 「번들 로케일 전부」로 적어 두고 본문은 ko·en 두 개만 돌고
     * 있었다 — 번들 ja 팩은 실재하는 번들 로케일인데 그 축이 한 번도 측정되지 않았고,
     * 선언만 읽은 사람은 그 축이 닫혀 있다고 믿는다. 재는 것에 맞춰 이름을 좁힌다
     * (8차 G3 라운드 D-r5).
     *
     * ja 팩 동기는 **별개 축**이며 그 통로는 `check-language-pack-sync` 다. 이 검사가
     * ja 를 함께 돌면 「코어 사전이 옳은가」와 「팩이 따라왔는가」 두 질문이 한 단언에
     * 섞여, 팩 지연이 코어 사전의 실패로 보고된다.
     *
     * @effects php_probe_message_keys_resolve_in_base_locales
     */
    public function test_every_produced_probe_key_resolves_in_base_locales(): void
    {
        $keys = $this->producedKeys();

        // 도출이 비면 아래 비교가 공허하다.
        $this->assertGreaterThanOrEqual(
            10,
            count($keys),
            '생산자 소스에서 사유 키를 도출하지 못했다 — 키 이름 규약이 바뀌었다면 이 검사도 함께 옮긴다'
        );

        $unresolved = [];

        foreach (['ko', 'en'] as $locale) {
            foreach ($keys as $key) {
                $full = self::NAMESPACE_PREFIX.$key;
                $translated = __($full, [], $locale);

                if ($translated === $full) {
                    $unresolved[] = $locale.':'.$full;
                }
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "사전에 없는 사유 키 — 소비처가 붙는 순간 화면에 원시 키가 그대로 뜬다:\n".implode("\n", $unresolved)
        );
    }

    /**
     * 치환 자리를 갖는 키는 그 자리가 실제로 채워진다.
     *
     * 자리표시자만 있고 파라미터가 오지 않으면 운영자 화면에 `:path` 가 그대로 남는다 —
     * 실패했을 때만 드러나므로 정상 흐름 테스트로는 잡히지 않는다.
     */
    public function test_placeholder_keys_are_substituted(): void
    {
        $cases = [
            'php_probe_file_missing' => ['path' => '/usr/bin/php'],
            'php_probe_exec_failed' => ['path' => '/usr/bin/php'],
            'php_probe_auto_detected' => ['path' => '/usr/bin/php'],
            'php_probe_ok' => ['version' => '8.3.0', 'min_version' => '8.2.0'],
            'php_probe_version_low' => ['version' => '8.0.0', 'min_version' => '8.2.0'],
        ];

        foreach (['ko', 'en'] as $locale) {
            foreach ($cases as $key => $params) {
                $translated = __(self::NAMESPACE_PREFIX.$key, $params, $locale);

                foreach (array_keys($params) as $placeholder) {
                    $this->assertStringNotContainsString(
                        ':'.$placeholder,
                        $translated,
                        $locale.' 의 '.$key.' 에 치환되지 않은 자리표시자가 남았다'
                    );
                }
            }
        }
    }
}
