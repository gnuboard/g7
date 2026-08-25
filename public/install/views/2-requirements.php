<?php
/**
 * Step 2: 요구사항 검증
 *
 * 서버 요구사항을 검증하고 결과를 표시합니다.
 */
?>

<div class="installer-container installer-container-wide">
    <h1 class="installer-title"><?= htmlspecialchars(lang('step_2_requirements')) ?></h1>

    <?php /* 스피너 → 결과 카드로 **통째로** 갈아 끼워지는 영역이다. 라이브 리전 선언이 없으면
             검사가 끝났다는 사실도, 통과 여부도 화면 밖에서는 전달되지 않는다. inprocess 에서만
             렌더되는 안내 카드가 유일한 라이브 리전이라 subprocess 환경의 이 화면에는 라이브
             리전이 하나도 없었다 (12차 #B34). `aria-busy` 는 검사가 도는 동안 중간 상태를
             읽지 않게 한다 — JS 가 결과를 그린 뒤 해제한다. */ ?>
    <div id="requirements-result" class="requirements-list" role="status" aria-live="polite" aria-busy="true">
        <!-- AJAX 로딩 중 -->
        <div class="loading-box">
            <div class="spinner"></div>
            <p class="loading-text"><?= htmlspecialchars(lang('checking_requirements')) ?></p>
        </div>
    </div>

    <div id="navigation-buttons" class="btn-group btn-group-spread hidden">
        <button type="button" onclick="goToStep(1)" class="btn btn-secondary">
            <?= htmlspecialchars(lang('previous')) ?>
        </button>

        <div class="btn-group-right">
            <?php /* [다시 검사] 는 중립 재시도다 — 빨강 솔리드(btn-danger)는 삭제·중단과 같은
                     시각 언어라 요구사항 화면에서 「무언가를 지운다」로 오독된다 (9차 #L30, §12 W1) */ ?>
            <button onclick="recheckRequirements()" id="recheck-btn" class="btn btn-secondary hidden">
                <?= htmlspecialchars(lang('recheck')) ?>
            </button>

            <form method="POST" class="form-inline">
                <button type="submit" name="proceed" id="next-btn" disabled class="btn btn-primary hidden">
                    <?= htmlspecialchars(lang('next')) ?>
                </button>
            </form>
        </div>
    </div>
</div>