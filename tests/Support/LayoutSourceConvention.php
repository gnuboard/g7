<?php

namespace Tests\Support;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Traits\ResolvesLayoutInstallSource;
use App\Extension\Traits\ValidatesLayoutFiles;

/**
 * 레이아웃 **소스 선택 규약**의 표면 — 가드들이 공유하는 단일 SSoT (34차 신설).
 *
 * ## 왜 한 곳인가
 *
 * 27차가 우선순위 규칙을 `ResolvesLayoutInstallSource` 한 곳에 모으면서 남긴 교훈이 "규약을
 * 복제해 배포하면 한쪽만 넓힐 때 나머지가 반쪽으로 남는다" 였다. 그런데 **그 규약의 표면을
 * 아는 목록**은 가드마다 따로 있었다 —
 *
 * - 조각 규약 라우팅 가드: `LAYOUT_SOURCE_DELEGATIONS` 9개 (위임 표기)
 * - 오라클 독립성 가드: `TRAIT_RESOLVER_ENTRY_POINTS` 4개 (손으로 쓴 진입점 열거)
 *
 * 두 목록은 같은 것을 가리키는데 값이 달랐다. 34차 실측 — 후자에 규약의 **소유자**인
 * `layoutSourceCandidates()`·`mergeLayoutSourcesByPriority()` 와 조각 축의 `resolveAllPartials()`
 * 가 빠져 있었고, 그 진입점으로 만든 항진명제는 문지기에서 걸러져 검사에 들어오지도 않았다.
 * 31차가 `resolvePartialSource` 누락으로 고친 것과 **같은 여집합이 한 층 위에** 남아 있던 것이다.
 *
 * 목록이 둘이면 다음 진입점이 생길 때 한쪽만 갱신된다. 그래서 표기 목록 하나를 정본으로 두고,
 * 진입점 이름은 거기서 **도출**한다.
 */
final class LayoutSourceConvention
{
    /**
     * 소스 선택 규약에 위임하는 표기 — 이 규약의 공개 표면 정본.
     *
     * 메서드는 `이름(` 으로, 클래스는 이름만으로 적는다(정적 호출·import 어느 표기든 잡히도록).
     *
     * @var array<int, string>
     */
    public const DELEGATIONS = [
        'layoutSourceCandidates(',
        'mergeLayoutSourcesByPriority(',
        'collectLayoutSources(',
        'collectFragmentSources(',
        'loadFragmentDefinitions(',
        'loadFragmentSource(',
        'resolveFragmentSource(',
        'resolvePartialSource(',
        // **39차 — `resolveAllPartials(` 를 여기서 걷어냈다.** 그것은 아래
        // {@see NON_DELEGATING_SURFACE} 에도 등재돼 있었고, 그 독블록이 *"여기 등재된 것은 …
        // 면제 목록에 넣으면 안 된다"* 를 명시적으로 금지한다. 즉 **등록부가 자기 규칙을 스스로
        // 어긴 상태**였는데, 폐쇄 단언이 둘의 **합집합**({@see selectionSurface()})과 비교하므로
        // 그 모순은 구조적으로 검출되지 않았다.
        //
        // 걷어낸 효과는 **오늘 검출 변화 0** 이다(제거 전후로 라우팅 가드 두 축 모두 green —
        // 실측). 즉 이것은 실현된 사각이 아니라 **잠재 면제**였다: 우선순위를 자기가 다시
        // 구현하면서 `resolveAllPartials()` 를 한 번 부르는 사본이 생기면, 그 호출 하나로
        // 라우팅 가드를 통과했을 것이다. 교집합 하드 0 은
        // {@see \Tests\Unit\Support\LayoutMarkup\OracleIndependenceGuardTest} 가 잠근다.
        'resolvePartialPath(',
        'LayoutFragmentSourceHelper',
    ];

    /**
     * 코어의 선택 표면이지만 **위임 표기는 아닌** 메서드 → 사유 (35차).
     *
     * {@see DELEGATIONS} 는 라우팅 가드가 "여기를 부르면 사본이 아니다" 로 쓰는 **면제 목록**이라,
     * 넓히면 사본 검출이 약해진다. 반대로 오라클 독립성 가드의 문지기는 "출력이 선택에서 나온
     * 자리" 를 전부 알아야 하므로 넓어야 안전하다. 폭이 다른 두 소비처를 한 집합에서 갈라 내되,
     * **어느 쪽에도 분류되지 않은 코어 선택 표면은 남지 않는다** — 그것이 역방향 폐쇄다.
     *
     * 여기 등재된 것은 우선순위 결정을 위임받지 않는다. 이 메서드를 부른다고 해서 그 코드가
     * 규약을 따른다는 뜻이 아니므로 면제 목록에 넣으면 안 된다.
     *
     * @var array<string, string>
     */
    public const NON_DELEGATING_SURFACE = [
        'layoutSourcePathKey' => '경로 문자열 정규화만 한다. 우선순위 판단이 없으므로 이것을 부르는 '
            .'코드가 규약을 따른다고 볼 수 없다 — 경로만 헬퍼로 접고 우선순위는 자기가 다시 '
            .'구현하는 사본이 정확히 이 형태다.',
        'normalizeLayoutInstallSource' => "옵션 값('auto'|'json')을 정규화할 뿐 소스를 고르지 않는다. "
            .'강제 여부를 읽어 자기 분기를 만드는 사본이 이것을 부를 수 있으므로 면제로 쓰면 안 된다.',
        'layoutInstallSourceIsJsonForced' => '강제 여부(bool)만 읽는다. 우선순위를 위임하는 것이 '
            .'아니라 **강제 플래그를 읽어 자기 분기를 만드는 사본**이 정확히 이 형태이므로, 이것을 '
            .'면제 신호로 쓰면 라우팅 가드가 잡아야 할 사본을 놓친다.',

        // ── 37차: 도출을 고정점까지 돌리자 드러난 깊이 2 이상의 선택 소비 표면 (다섯) ──
        //
        // **38차 정정 — 종전 이 주석은 "넷 다" 라고 적었으나 아래 등재는 다섯이다**
        // (`resolvePartial` · `resolveAllPartials` · `readFragment` · `countInstallableLayouts` ·
        // `validateLayoutFiles`). 37차 이력 문장도 이름 다섯을 나열하면서 "4건" 이라 적었다.
        // 수를 산문에 적는 순간 등재와 갈리므로, 세어야 할 때는 이 배열을 세는 것이 정본이다.
        //
        // 다섯 다 **출력이 선택 결과에서 나오므로** 오라클 문지기는 알아야 하고(그래서 여기 있다),
        // 동시에 **면제로 쓰면 안 된다** — 이들을 부른다고 그 코드가 우선순위 규약을 따르는 것이
        // 아니라, 오히려 이들은 규약을 탄 결과를 *소비*하는 자리다. 종전에 이 넷이 어느 쪽에도
        // 없었던 이유는 판정이 씨앗으로부터 호출깊이 1 이었기 때문이다.
        'resolvePartial' => '조각 참조 하나를 해석해 인라인한다. 소스 선택은 resolvePartialSource() 에 '
            .'위임하지만 그 결과를 컴파일·디코드해 반환하므로 **출력이 선택에서 나온다** — 이것을 '
            .'통과한 값을 오라클의 양변에 쓰면 항등식이 된다.',
        'resolveAllPartials' => '문서의 조각 참조 전량을 해석한다. 위 항목과 같은 이유로 문지기 대상이다.',
        'readFragment' => 'fragment 파일 하나를 규약 경유로 읽어 정의 배열을 돌려준다. 확장이 자기 '
            .'확장점 정의를 읽는 코어 공개 진입점의 내부 단계라, 출력이 선택 결과다.',
        'countInstallableLayouts' => '설치 대상 레이아웃 수를 센다. 짝 접기(중복 계상 금지)가 선택 '
            .'규약에서 나오므로 그 수 자체가 선택의 함수다.',
        'validateLayoutFiles' => '레이아웃 파일 묶음을 검증한다. 검증 대상 집합이 선택 결과라 '
            .'통과/실패 판정이 선택에 의존한다.',
        // #727: 조각 출처 표식을 켜고 끄는 래퍼와 본문으로 갈렸다 — 본문이 collectLayoutSources() 를 부른다.
        'validateLayoutFilesAnnotated' => 'validateLayoutFiles() 의 본문(조각 출처 표식이 켜진 상태). '
            .'collectLayoutSources() 의 선택 결과를 검증·컴파일해 반환하므로 **출력이 선택에서 나온다** — '
            .'위 항목과 같은 이유로 문지기 대상이고, 결과를 소비할 뿐이라 면제로 쓰면 안 된다.',

        // ── #476: 조각 식별자(fragment_key) 도입으로 생긴 선택 소비 표면 ──
        'fragmentSourceKeys' => '조각 디렉토리의 **소스 파일** 키 집합을 만든다. 집합의 원소가 '
            .'collectFragmentSources() 의 짝 접기(.g7.html 우선·짝 1건)에서 나오므로 **출력이 '
            .'선택 결과다** — 갱신 경로는 이 집합의 여집합을 "파일에서 사라진 조각" 으로 보고 DB 행을 '
            .'지우므로, 선택이 틀리면 살아 있는 행이 지워진다. 동시에 면제로 쓰면 안 된다: 이것을 '
            .'부른다고 그 코드가 우선순위 규약을 따르는 것이 아니라 결과를 소비할 뿐이다.',
    ];

    /**
     * 오라클 문지기가 알아야 하는 **선택 표면 전량** — 위임 표기 + 비위임 표면.
     *
     * 출력이 선택에서 나오는 자리는 전부 여기 있어야 한다. 좁으면 그 진입점으로 만든 항진명제가
     * 문지기에서 걸러져 검사에 들어오지도 않는다(31·34차가 그 형태였다).
     *
     * @return array<int, string> 메서드 이름
     */
    public static function selectionSurface(): array
    {
        return array_values(array_unique(array_merge(
            self::selectionMethodNames(),
            array_keys(self::NON_DELEGATING_SURFACE),
        )));
    }

    /**
     * 규약 표면을 소유할 수 있는 코어 클래스·트레이트 — 진입점 실재 정박용.
     *
     * @var array<int, class-string>
     */
    public const OWNERS = [
        LayoutFragmentSourceHelper::class,
        ResolvesLayoutInstallSource::class,
        CollectsFragmentSources::class,
        ValidatesLayoutFiles::class,
    ];

    /**
     * 위임 표기에서 **메서드 이름**만 도출합니다.
     *
     * 클래스 표기(`LayoutFragmentSourceHelper`)는 메서드가 아니므로 제외한다 — 그 클래스의
     * 진입점은 리플렉션이 따로 도출한다.
     *
     * @return array<int, string> 메서드 이름
     */
    public static function selectionMethodNames(): array
    {
        $names = [];

        foreach (self::DELEGATIONS as $token) {
            if (! str_ends_with($token, '(')) {
                continue;
            }

            $names[] = rtrim($token, '(');
        }

        return array_values(array_unique($names));
    }

    /**
     * 소유자에 실재하는 **선택 성질 메서드**를 리플렉션으로 전량 도출합니다 (35차 — 역방향 폐쇄).
     *
     * ## 왜 이 도출이 필요한가
     *
     * 34차는 목록 둘을 여기 하나로 **통합**했지만 폐쇄는 한 방향뿐이었다 — {@see ownerOf()} 가
     * "등재분이 코어에 실재하는가" 만 물었고, **코어에 있는데 목록에 없는** 진입점을 잡는 단언은
     * 없었다. 그래서 35차 실측에서 여집합 3건(`resolveFragmentSource`·`resolvePartial`·
     * `resolvePartialPath`)이 그대로 살아 있었고, 그중 `resolveFragmentSource()` 는 공유 오라클
     * ({@see CompilesFragmentMarkup}) 이 실제로 부르는 선택 진입점이다. 그 진입점
     * 으로 만든 항진명제는 오라클 독립성 가드의 문지기에서 걸러져 검사에 들어오지도 않는다 —
     * 31차(`resolvePartialSource` 누락) → 34차(`layoutSourceCandidates` 누락)와 **같은 형태가 한
     * 층 더** 남아 있었던 것이다.
     *
     * 열거의 여집합이 다음 회차의 검출물이 되는 구조를 끊으려면 폐쇄가 양방향이어야 한다.
     * "선택 성질" 은 이름 규칙이 아니라 **호출 관계**로 판정한다 — 규약 소유자 트레이트
     * ({@see ResolvesLayoutInstallSource})가 선언한 메서드가 씨앗이고, 그것을 부르는 자리가 곧
     * 선택 진입점이다. 씨앗을 리플렉션으로 잡는 이유는 첫 판본이 그 자리를 신호 이름 셋으로
     * **손수 적었다가** 이 조치의 판정식에 스스로 걸렸기 때문이다 — 판정에 쓰이는 손 열거는
     * 그 자체가 여집합을 만든다.
     *
     * @return array<int, string> 메서드 이름
     */
    public static function derivedSelectionMethods(): array
    {
        // 씨앗은 **규약 소유자 트레이트가 선언한 메서드 전량**이다 (리플렉션 도출).
        //
        // 35차 자기 검출 — 첫 판본은 이 씨앗을 신호 이름 셋으로 손수 적었고, 그것이 정확히 이
        // 조치가 없애려는 형태였다(판정 술어로 소비되는 손 열거 = 여집합이 곧 사각). 코어에 새
        // 우선순위 원시함수가 생기면 그 목록이 조용히 좁아진다. 소유자 트레이트를 씨앗으로 삼으면
        // 그 트레이트에 무엇이 추가되든 자동으로 씨앗에 들어온다.
        $seeds = [];

        foreach ((new \ReflectionClass(ResolvesLayoutInstallSource::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === ResolvesLayoutInstallSource::class) {
                $seeds[] = $method->getName();
            }
        }

        // **37차 — 도출을 고정점까지 반복한다.**
        //
        // 종전에는 씨앗으로부터 **호출깊이 1** 만 훑었다. 그래서 선택 진입점을 부르는 함수는
        // 잡히지만 *그 함수를 부르는* 함수는 분류 강제 밖이었다 — 예컨대
        // `ValidatesLayoutFiles::resolvePartial()` 은 `resolvePartialSource()` 를 통해서만 규약에
        // 닿으므로 깊이 2 였고, 그 축으로 만든 항진명제는 오라클 가드의 문지기에 걸러져 검사에
        // 들어오지도 않았다. 35차가 "여집합을 닫았다" 고 적은 것과 **같은 형태가 한 단 아래**에
        // 남아 있던 자리다. 더 늘지 않는 지점까지 돌리면 그 깊이 의존이 사라진다.
        $derived = $seeds;
        $frontier = $seeds;

        $sources = [];
        foreach (self::OWNERS as $owner) {
            $reflection = new \ReflectionClass($owner);
            $source = file_get_contents((string) $reflection->getFileName());

            if ($source === false) {
                continue;
            }

            $sources[$owner] = ['reflection' => $reflection, 'source' => $source];
        }

        // 반복 상한은 소유자 전체의 메서드 수 — 매 회 최소 1개는 새로 편입돼야 계속되므로
        // 그보다 오래 돌 수 없다. 상한은 무한루프 방지용이지 판정에 개입하지 않는다.
        $guard = 0;
        foreach ($sources as $entry) {
            $guard += count($entry['reflection']->getMethods());
        }

        while ($frontier !== [] && $guard-- > 0) {
            $next = [];

            foreach ($sources as $owner => $entry) {
                foreach ($entry['reflection']->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $owner) {
                        continue;
                    }

                    $name = $method->getName();

                    if (in_array($name, $derived, true)) {
                        continue;
                    }

                    $body = self::methodBody($entry['source'], $name);

                    if ($body !== null && self::bodyCallsAny($body, $frontier)) {
                        $derived[] = $name;
                        $next[] = $name;
                    }
                }
            }

            $frontier = $next;
        }

        return array_values(array_unique($derived));
    }

    /**
     * 본문이 씨앗 메서드 중 하나를 부르는지 판정합니다 (이름 규칙 비의존).
     *
     * @param  string  $body  메서드 본문
     * @param  array<int, string>  $seeds  규약 소유자가 선언한 메서드 이름
     * @return bool 하나라도 부르면 true
     */
    private static function bodyCallsAny(string $body, array $seeds): bool
    {
        foreach ($seeds as $seed) {
            if (preg_match('/(?:\$this->|self::|static::)'.preg_quote($seed, '/').'\s*\(/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 소스에서 메서드 하나의 본문을 잘라냅니다.
     *
     * @param  string  $source  클래스 소스
     * @param  string  $method  메서드 이름
     * @return string|null 본문 (찾지 못하면 null)
     */
    private static function methodBody(string $source, string $method): ?string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\(/';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1];
        $next = preg_match('/function\s+\w+\s*\(/', $source, $after, PREG_OFFSET_CAPTURE, $start + 1) === 1
            ? $after[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    /**
     * 주어진 메서드를 소유한 코어 클래스·트레이트를 찾습니다.
     *
     * @param  string  $method  메서드 이름
     * @return string|null 소유자 FQCN (없으면 null)
     */
    public static function ownerOf(string $method): ?string
    {
        foreach (self::OWNERS as $owner) {
            if (method_exists($owner, $method) || in_array($method, get_class_methods($owner) ?: [], true)) {
                return $owner;
            }

            // 트레이트는 `method_exists` 로 닿지 않는 경우가 있어 리플렉션으로 한 번 더 본다.
            if (trait_exists($owner) && (new \ReflectionClass($owner))->hasMethod($method)) {
                return $owner;
            }
        }

        return null;
    }
}
