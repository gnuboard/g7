<?php

namespace App\Extension\Helpers;

use App\Extension\Traits\CollectsFragmentSources;

/**
 * 확장이 소유한 fragment 정의 파일 하나를 소스 SSoT 우선으로 읽는 헬퍼.
 *
 * 대부분의 fragment 는 코어가 디렉토리째 수집해 등록하지만(`CollectsFragmentSources`), 확장이
 * **자기 확장점 정의를 스스로 읽어야 하는** 경우가 있다 — 호스트 레이아웃에 자기 확장이 이미
 * 주입돼 있는지 확인하고 없으면 채워 넣는 Listener 계열이 그렇다.
 *
 * 그 자리들이 각자 `file_get_contents`+`json_decode` 로 `.json` 을 직접 읽으면 소스(`.g7.html`)만
 * 고쳤을 때 조용히 반영되지 않는다. 그리고 같은 코드가 확장마다 복제되므로 규약을 넓힐 때 한쪽이
 * 반쪽으로 남는다 — 실제로 결제 플러그인 네 곳이 같은 사본을 갖고 있었다. 그래서 확장이 쓸 수 있는
 * 공개 진입점을 코어에 한 자리만 둔다.
 *
 * ```php
 * $extension = LayoutFragmentSourceHelper::read(
 *     dirname(__DIR__, 2).'/resources/extensions/admin_order_payment_query.json'
 * );
 * ```
 *
 * 짝 `.g7.html` 이 있으면 그쪽을 컴파일해 돌려주고, 없으면 `.json` 을 파싱한다.
 * `--layout-source=json` 이면 우선순위가 뒤집힌다(코어 설치 경로와 같은 규약).
 */
class LayoutFragmentSourceHelper
{
    use CollectsFragmentSources;

    /**
     * 읽기 결과 캐시 (실행 단위 · 키는 소스 경로+mtime+size).
     *
     * 이 진입점의 호출처는 요청마다 도는 Listener 라, 종전에는 각 Listener 가 `static $extension`
     * 메모이제이션을 따로 들고 있었다. 그 메모이제이션까지 여기로 모아 사본을 남기지 않는다.
     * 설치 경로는 이 헬퍼를 쓰지 않으므로(디렉토리 수집을 쓴다) 신선도에 영향이 없다.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $readCache = [];

    /**
     * fragment 소스 경로에서 조각 식별자(`fragment_key`)를 만듭니다 — 기본 이름에서 `.g7.html`/`.json` 을 뗀 것.
     *
     * 같은 확장이 같은 대상(`target_layout`/`extension_point`)에 조각을 둘 보낼 수 있으므로 DB 행은
     * 대상만으로 갈리지 않는다. 짝(`x.g7.html`·`x.json`)은 같은 논리 조각이라 같은 키다. 파생 규칙은
     * 이 한 벌뿐이다 — 등록 경로마다 따로 만들면 갈린다.
     *
     * @param  string  $path  fragment 소스 경로
     * @return string 조각 식별자
     */
    public static function fragmentKey(string $path): string
    {
        return (string) preg_replace('/\.(?:g7\.html|json)$/i', '', basename(str_replace('\\', '/', $path)));
    }

    /**
     * fragment 정의 파일 하나를 읽습니다.
     *
     * 호출처는 대개 "있으면 쓰고 없으면 아무것도 하지 않는" 자리라, 부재·파싱 실패는 예외 대신
     * 빈 배열로 돌려주고 실패 사유는 로그에 남긴다 (읽기 실패가 요청 전체를 세우지 않는다).
     *
     * @param  string  $fragmentPath  fragment 정의 경로 (`.json` 또는 `.g7.html`)
     * @return array<string, mixed> fragment 정의, 읽지 못하면 빈 배열
     */
    public static function read(string $fragmentPath): array
    {
        return (new self)->readFragment($fragmentPath);
    }

    /**
     * fragment 디렉토리의 정의를 전부 읽습니다 — 소스 SSoT 우선 + 짝 병존 시 1건.
     *
     * 확장이 자기 확장점을 **하나** 확인하는 자리({@see read()}) 말고, **전부** 다시 등록해야 하는
     * 자리가 있다 — 업데이트 직후 overlay 확장을 복원하는 Listener 계열이 그렇다. 그 자리가 스스로
     * `glob('*.json')` 하면 소스가 통째로 관측 밖이 되므로 디렉토리 수집도 코어가 맡는다.
     *
     * 디렉토리가 없으면 빈 배열이다 (호출처가 존재 확인을 중복하지 않게 한다).
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @return array<int, array<string, mixed>> 읽기에 성공한 정의만 (등록 순서 = 수집 순서)
     */
    public static function readDirectory(string $extensionsPath): array
    {
        return array_column(self::readDirectoryKeyed($extensionsPath), 'data');
    }

    /**
     * fragment 디렉토리의 정의를 **조각 식별자와 함께** 읽습니다.
     *
     * 같은 확장이 같은 대상(`target_layout`/`extension_point`)에 조각을 둘 보낼 수 있어 DB 행은
     * 대상만으로 갈리지 않는다 — 그래서 `registerExtension()` 은 `fragment_key` 를 받는다. 키 없는
     * 호출도 호환 경로로 동작하지만(살아 있는 단일 행의 키를 잇는다), 같은 대상에 행이 둘 이상이면
     * 어느 조각인지 알 수 없어 **아무것도 하지 않는다**. 그 모호성이 생길 수 있는 자리는 파일에서
     * 바로 키를 얻어 넘기는 편이 옳다.
     *
     * `readDirectory()` 는 이 결과에서 `data` 만 뽑은 것이다 — 새로 쓰는 자리는 이쪽을 쓴다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @return array<int, array{path: string, data: array<string, mixed>, key: string}> 읽기에 성공한 정의만
     */
    public static function readDirectoryKeyed(string $extensionsPath): array
    {
        return (new self)->loadFragmentDefinitions($extensionsPath, [], ['fragment_dir' => $extensionsPath]);
    }

    /**
     * fragment 정의를 소스 SSoT 우선으로 읽습니다.
     *
     * @param  string  $fragmentPath  fragment 정의 경로
     * @return array<string, mixed> fragment 정의, 읽지 못하면 빈 배열
     */
    private function readFragment(string $fragmentPath): array
    {
        $source = $this->resolveFragmentSource($fragmentPath);

        if ($source === null) {
            return [];
        }

        $cacheKey = $source['path'].'|'.(@filemtime($source['path']) ?: 0).'|'.(@filesize($source['path']) ?: 0);

        if (isset(self::$readCache[$cacheKey])) {
            return self::$readCache[$cacheKey];
        }

        return self::$readCache[$cacheKey] = $this->loadFragmentSource($source, ['fragment' => $fragmentPath]) ?? [];
    }
}
