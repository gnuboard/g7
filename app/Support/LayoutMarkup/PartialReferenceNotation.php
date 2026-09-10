<?php

namespace App\Support\LayoutMarkup;

use App\Extension\Traits\ResolvesLayoutInstallSource;

/**
 * 조각 참조 표기의 **경계 정규화** 한 곳 (S21.2 ⓑ · U-REFEXT 안 B · 설계 결정 2026-08-02).
 *
 * ## 규약
 *
 * 소스(`.g7.html`)은 소스를 가리키고, 산출 JSON 은 **기존 JSON 문법을 100% 유지**한다.
 * 두 세계는 각자 자기완결적이고, 확장자를 바꾸는 일은 오직 컴파일 경계에서만 일어난다.
 *
 * ```
 * 작성   <include src="_x.g7.html"/>   ──컴파일──▶   {"partial": "_x.json"}
 * 작성   <modal   src="_x.g7.html"/>   ──컴파일──▶   modals[] = {"partial": "_x.json"}
 *        (역컴파일은 정확히 그 역함수 — `.json` → `.g7.html`)
 * ```
 *
 * ## 왜 한 곳인가
 *
 * 이 규약을 소비하는 자리는 넷이다 — 컴파일 둘({@see NodeCompiler::compileInclude()},
 * {@see DocumentCompiler::compileModal()})과 역컴파일 둘({@see NodeMarkupDecompiler::node()},
 * {@see LayoutMarkupDecompiler::modal()}). 넷 중 하나만 어긋나면 왕복이 깨지거나(역컴파일 쪽)
 * 재생성이 규약을 조용히 되돌린다(계획서 유사 로직 조사 ③). 변환 규칙을 복제해 배포하면
 * 한쪽만 고칠 때 나머지가 반쪽으로 남으므로({@see ResolvesLayoutInstallSource}
 * 가 짝 판별 18줄을 합친 것과 같은 이유) 규칙 자체를 여기 한 벌만 둔다.
 *
 * ## 엄격한 이유 — 이 매핑은 정확한 역함수여야 한다
 *
 * 왕복 게이트는 `JSON → 마크업 → JSON` 이므로 매핑이 역함수이기만 하면 항등이 성립한다.
 * 바꿔 말하면 **역함수가 아닌 매핑만이 왕복에서 보인다** — 그래서 변환은 접미사 치환으로
 * 좁게 정의하고(경로 중간의 `.json`·`.g7.html` 은 건드리지 않는다), 작성 쪽 입력이 규약을
 * 벗어나면 조용히 통과시키지 않고 컴파일 에러로 세운다({@see LayoutMarkupErrorCode::PartialSrcNotAuthoring}).
 *
 * 매핑이 낸 이름이 **디스크에 실재하는가**는 왕복이 구조적으로 볼 수 없는 별개 명제이고,
 * `Tests\Unit\Support\LayoutMarkup\PartialReferenceNotationCorpusTest` 가 전수로 잰다
 * (프로덕션 코드가 테스트 네임스페이스를 import 하지 않도록 `{@see}` 대신 이름만 적는다).
 */
final class PartialReferenceNotation
{
    /**
     * 소스 SSoT 확장자 — 소스가 서로를 가리킬 때 쓰는 표기.
     */
    public const MARKUP_EXTENSION = '.g7.html';

    /**
     * 산출 확장자 — 산출 JSON 의 `partial` 값이 유지하는 표기.
     */
    public const JSON_EXTENSION = '.json';

    /**
     * 마크업 표기(`.g7.html` 로 끝남)인지 판정합니다.
     *
     * @param  string  $reference  참조 값
     * @return bool 마크업 표기면 true
     */
    public static function isAuthoringNotation(string $reference): bool
    {
        return self::hasExtension($reference, self::MARKUP_EXTENSION);
    }

    /**
     * 산출 표기(`.json` 로 끝남)인지 판정합니다.
     *
     * @param  string  $reference  참조 값
     * @return bool 산출 표기면 true
     */
    public static function isJsonNotation(string $reference): bool
    {
        return self::hasExtension($reference, self::JSON_EXTENSION);
    }

    /**
     * 마크업 표기를 산출 표기로 바꿉니다 (컴파일 방향).
     *
     * 마크업 표기가 아니면 **원본을 그대로 돌려준다** — 여기서 임의로 확장자를 붙이면 규약 위반이
     * 산출물 안에서 정상처럼 보인다. 규약 강제는 호출부(컴파일러)가 에러로 세우는 몫이다.
     *
     * @param  string  $src  소스 `src` 값
     * @return string 산출 JSON `partial` 값
     */
    public static function toJson(string $src): string
    {
        return self::isAuthoringNotation($src)
            ? substr($src, 0, -strlen(self::MARKUP_EXTENSION)).self::JSON_EXTENSION
            : $src;
    }

    /**
     * 산출 표기를 마크업 표기로 바꿉니다 (역컴파일 방향 — {@see toJson()} 의 역함수).
     *
     * 산출 표기가 아니면 원본을 그대로 돌려준다. 그 값은 다시 컴파일될 때 규약 위반으로 걸리고,
     * 역컴파일러의 재컴파일 검증(escape)이 그 자리를 원본 JSON 으로 탈출시킨다 — 조용히
     * 사라지는 대신 escape 예산으로 관측된다.
     *
     * @param  string  $partial  산출 JSON `partial` 값
     * @return string 소스 `src` 값
     */
    public static function toMarkup(string $partial): string
    {
        return self::isJsonNotation($partial)
            ? substr($partial, 0, -strlen(self::JSON_EXTENSION)).self::MARKUP_EXTENSION
            : $partial;
    }

    /**
     * 접미사 일치 판정 — **대소문자를 가린다**.
     *
     * 해석기({@see ResolvesLayoutInstallSource::layoutSourceCandidates()})는
     * 대소문자를 무시한다. 그쪽은 *"디스크의 어느 파일인가"* 를 묻고, 이쪽은 *"어느 표기인가"* 를
     * 묻기 때문에 두 술어는 같을 수 없다 — 무시하면 매핑이 **역함수가 아니게 된다**:
     * `_X.JSON` → `_X.g7.html` → `_X.json` 으로 대소문자가 소실되고, 그 손실은 왕복 게이트가
     * 잡는 유일한 성질(역함수성)을 정면으로 깬다.
     *
     * 대소문자가 다른 표기는 여기서 **인식하지 않는 값**이 되고, 그러면 컴파일러가
     * {@see \App\Enums\LayoutMarkupErrorCode::PartialSrcNotAuthoring} 로 세운다 — 조용히 뭉개는
     * 대신 작성자에게 표기를 고치라고 말한다.
     *
     * @param  string  $value  대상 값
     * @param  string  $extension  확장자
     * @return bool 그 확장자로 끝나면 true
     */
    private static function hasExtension(string $value, string $extension): bool
    {
        return strlen($value) > strlen($extension) && str_ends_with($value, $extension);
    }
}
