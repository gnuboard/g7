<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 언어 선택지 라벨 전수 테스트 (S2-D11 회귀 · #599 통합실측 세션2)
 *
 * **증상**: 마이페이지 [내 정보] → [수정하기] 의 「언어」 드롭다운이 이렇게 떴다.
 *
 * ```
 * ["언어를 선택하세요", "한국어 (Korea)", "English", "languages.ja"]
 *                                                    ↑ 원문 키
 * ```
 *
 * 선택지로는 **제시하면서 라벨이 없다.** 일본어를 고르려면 `languages.ja` 라는 개발자용
 * 키를 눌러야 한다. 값은 정상 저장되므로(`users.language='ja'`) 서버·DB 에는 흔적이 없고,
 * **화면을 열어 글자를 읽어야만** 드러난다.
 *
 * **원인**: 선택지 목록은 지원 로케일에서 오는데 라벨은 `languages.json` 에서 온다.
 * 로케일을 늘릴 때 목록만 늘고 라벨 파일이 따라오지 않으면 그 항목만 키가 노출된다.
 * 번역 누락은 보통 **조용하다** — 이 화면은 그 침묵이 사용자에게 보이는 드문 자리다.
 *
 * **왜 전수인가**: S2-D11 은 ja 하나의 문제로 보였지만, 같은 원인이 **사본마다 따로** 난다.
 * 실제로 이 테스트를 세우며 `lang-packs/g7-template-sirsoft-basic-ja/`(활성 사본)에 `ja`
 * 키가 **여전히 없는 것**을 찾았다 — `_bundled` 만 고치고 활성본이 뒤처져 있었다. 한 벌만
 * 보는 검사는 그 상태를 초록으로 통과시킨다.
 *
 * 그래서 이 테스트는 **모든 사본 × 모든 지원 로케일**을 돈다. 파일이 없으면 통과가 아니라
 * **실패**한다(경로가 바뀌었는데 조용히 지나가면 같은 사고가 되풀이된다 — 자매 테스트
 * `ConfirmPurchaseWarningAccuracyTest` 가 정확히 그 구멍으로 ja 오역을 놓쳤다).
 */
class LanguageOptionLabelsTest extends ModuleTestCase
{
    /**
     * 라벨이 있어야 하는 로케일.
     *
     * 지원 로케일을 늘리면 여기에 더한다 — 그 순간 라벨이 빠진 사본이 red 로 드러난다.
     */
    private const REQUIRED_LOCALES = ['ko', 'en', 'ja'];

    /**
     * 저장소 루트.
     *
     * **단계 수를 고정하지 않는다.** 이 테스트는 `_bundled` 원본과 활성 사본 **양쪽**에
     * 같은 내용으로 복제되는데 두 경로의 깊이가 한 단계 다르다 —
     * `modules/_bundled/sirsoft-ecommerce/tests/Unit/Lang` 는 6단계,
     * `modules/sirsoft-ecommerce/tests/Unit/Lang` 는 5단계다. 6단계로 고정하면 활성
     * 사본에서 저장소 **밖**(`htdocs/`)을 가리켜, 언어 파일이 멀쩡한데도
     * `assertFileExists` 가 「파일이 없다」로 터진다 — 가드가 스스로를 무력화한다
     * (#599 세션2 계획서준수검증 2차 결함3).
     *
     * 그래서 `artisan` 과 `composer.json` 이 **함께** 있는 디렉터리를 만날 때까지
     * 올라간다. 둘 중 하나만 보면 다른 PHP 프로젝트에서도 멈출 수 있어 둘 다 요구한다.
     *
     * @return string 저장소 루트 절대 경로
     */
    private function repoRoot(): string
    {
        $dir = __DIR__;

        while (! is_file($dir.'/artisan') || ! is_file($dir.'/composer.json')) {
            $parent = dirname($dir);

            // dirname() 이 더는 줄지 않으면 드라이브 루트다. 마커 없이 여기까지 왔으면
            // 경로 가정이 깨진 것이므로 조용히 넘기지 않고 red 로 세운다.
            if ($parent === $dir) {
                $this->fail('저장소 루트를 찾지 못했습니다(artisan·composer.json 마커 부재) — 시작: '.__DIR__);
            }

            $dir = $parent;
        }

        return $dir;
    }

    /**
     * 언어 라벨이 사는 **모든 사본**의 경로.
     *
     * 템플릿 원본(ko·en)과 언어팩(ja)이 서로 다른 트리에 있고, 각각 `_bundled`(원본)과
     * 활성 사본을 갖는다. 넷 다 봐야 「한쪽만 고쳤다」가 드러난다.
     *
     * @return array<string, string> 사본 이름 => 절대 경로
     */
    private function labelFilePaths(): array
    {
        $root = $this->repoRoot();

        return [
            'template:ko(_bundled)' => "{$root}/templates/_bundled/sirsoft-basic/lang/partial/ko/languages.json",
            'template:ko(active)' => "{$root}/templates/sirsoft-basic/lang/partial/ko/languages.json",
            'template:en(_bundled)' => "{$root}/templates/_bundled/sirsoft-basic/lang/partial/en/languages.json",
            'template:en(active)' => "{$root}/templates/sirsoft-basic/lang/partial/en/languages.json",
            'langpack:ja(_bundled)' => "{$root}/lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/languages.json",
            'langpack:ja(active)' => "{$root}/lang-packs/g7-template-sirsoft-basic-ja/frontend/partial/languages.json",
        ];
    }

    /**
     * 모든 사본이 지원 로케일 전건의 라벨을 갖는다.
     *
     * @scenario language_labels=all_copies
     *
     * @effects every_supported_locale_has_a_display_label_in_every_copy
     */
    public function test_every_copy_has_a_label_for_every_supported_locale(): void
    {
        foreach ($this->labelFilePaths() as $label => $path) {
            $this->assertFileExists(
                $path,
                "언어 라벨 사본({$label})이 없습니다. 경로가 바뀌었다면 이 테스트의 "
                    .'labelFilePaths() 도 함께 고쳐야 합니다 — 여기서 조용히 넘어가면 '
                    .'그 사본은 드롭다운에 원문 키를 그대로 띄웁니다.'
            );

            $data = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($data, "언어 라벨 사본({$label})이 JSON 객체가 아닙니다.");

            foreach (self::REQUIRED_LOCALES as $locale) {
                $this->assertArrayHasKey(
                    $locale,
                    $data,
                    "언어 라벨 사본({$label})에 '{$locale}' 라벨이 없습니다 — 드롭다운에 "
                        ."`languages.{$locale}` 라는 원문 키가 그대로 뜹니다(S2-D11). "
                        .'사용자는 개발자용 키를 눌러 언어를 고르게 됩니다.'
                );

                $this->assertIsString($data[$locale], "라벨({$label}.{$locale})이 문자열이 아닙니다.");

                $this->assertNotSame(
                    '',
                    trim($data[$locale]),
                    "라벨({$label}.{$locale})이 빈 문자열입니다 — 선택지가 이름 없이 뜹니다."
                );

                // 라벨 자리에 키가 들어간 경우 — 번역기가 키를 그대로 복사하면 이렇게 된다.
                $this->assertStringNotContainsString(
                    'languages.',
                    $data[$locale],
                    "라벨({$label}.{$locale})이 원문 키를 담고 있습니다: {$data[$locale]}"
                );
            }
        }
    }
}
