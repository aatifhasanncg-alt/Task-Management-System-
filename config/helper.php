<?php
/**
 * config/fiscal_year_helper.php
 * 
 * Save this file at:  config/fiscal_year_helper.php
 * 
 * It is auto-included from config/config.php — you do NOT need
 * to require it manually in every page.
 *
 * Provides:
 *   getFiscalYears($db)            → array of active FYs for <select> dropdowns
 *   getCurrentFiscalYear($db)      → fy_code string of the current FY
 *   getFiscalYearId($db, $code)    → int id for a given fy_code string
 *   syncTaskFiscalYear($db, $id)   → call after saving any dept detail
 *   fiscalYearSelect(...)          → renders a <select> HTML string
 */

/**
 * Returns all active fiscal years ordered newest-first.
 *
 * Falls back to the FISCAL_YEARS constant defined in config.php
 * if the fiscal_years DB table hasn't been created yet.
 *
 * @return array  [['id'=>1,'fy_code'=>'2081/82','fy_label'=>'FY 2081/82','is_current'=>1], ...]
 */
function getFiscalYears(PDO $db): array
{
    static $cache = null;
    if ($cache !== null)
        return $cache;
    try {
        $cache = $db->query(
            "SELECT id, fy_code, fy_label, is_current
             FROM fiscal_years
             WHERE is_active = 1
             ORDER BY fy_code DESC"
        )->fetchAll();
    } catch (Exception $e) {
        // Fallback: build from FISCAL_YEARS constant in config.php
        $currentFY = getCurrentFiscalYear($db);

        $cache = array_map(
            fn($c) => [
                'id' => null,
                'fy_code' => $c,
                'fy_label' => $c,
                'is_current' => ($c === $currentFY ? 1 : 0)
            ],
            []
        );
    }
    return $cache;
}

/**
 * Returns the fy_code of the current fiscal year (is_current = 1).
 * Falls back to the FISCAL_YEAR constant in config.php.
 */
function getCurrentFiscalYear(PDO $db): string
{
    try {
        $v = $db->query(
            "SELECT fy_code FROM fiscal_years WHERE is_current = 1 LIMIT 1"
        )->fetchColumn();
        return $v ?: (defined('FISCAL_YEAR') ? FISCAL_YEAR : '');
    } catch (Exception $e) {
        return defined('FISCAL_YEAR') ? FISCAL_YEAR : '';
    }
}

/**
 * Returns the fiscal_years.id for a given fy_code string.
 * Returns null if not found.
 */
function getFiscalYearId(PDO $db, ?string $code): ?int
{
    if (!$code)
        return null;
    try {
        $s = $db->prepare("SELECT id FROM fiscal_years WHERE fy_code = ? LIMIT 1");
        $s->execute([$code]);
        $v = $s->fetchColumn();
        return $v ? (int) $v : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Syncs fiscal_year + fiscal_year_id from tasks table down to ALL
 * dept tables (task_tax, task_retail, task_corporate, task_finance,
 * task_banking) for the given task ID.
 *
 * Call this at the END of every dept save handler:
 *   syncTaskFiscalYear($db, $id);
 */
function syncTaskFiscalYear(PDO $db, int $taskId): void
{
    try {
        $db->prepare("CALL sync_task_fiscal_year(?)")->execute([$taskId]);
    } catch (Exception $e) {
        // Stored procedure may not exist on older installs — fail silently
    }
}

/**
 * Renders a <select> for fiscal year.
 *
 * Drop-in replacement for the old FISCAL_YEARS foreach loops.
 *
 * BEFORE (old pattern in every form):
 *   <select name="retail[fiscal_year]" class="form-select form-select-sm">
 *       <option value="">-- Select --</option>
 *       <?php foreach (FISCAL_YEARS as $fy): ?>
 *           <option value="<?= $fy ?>" <?= ($detail['fiscal_year']??'') === $fy ? 'selected' : '' ?>>
 *               <?= $fy ?>
 *           </option>
 *       <?php endforeach; ?>
 *   </select>
 *
 * AFTER (use this helper):
 *   <?= fiscalYearSelect('retail[fiscal_year]', $detail['fiscal_year'] ?? '', $fys) ?>
 *
 * @param string      $name      HTML name attribute  e.g. "retail[fiscal_year]"
 * @param string|null $selected  Currently selected fy_code
 * @param array       $fys       Result of getFiscalYears($db)
 * @param string      $class     CSS classes (Bootstrap sm by default)
 * @param bool        $required  Add HTML required attribute
 */
function fiscalYearSelect(
    string $name,
    ?string $selected,
    array $fys,
    string $class = 'form-select form-select-sm',
    bool $required = false
): string {
    $req = $required ? ' required' : '';
    $html = '<select name="' . htmlspecialchars($name) . '" class="' . $class . '"' . $req . ">\n";
    $html .= "    <option value=\"\">-- Select FY --</option>\n";
    foreach ($fys as $fy) {
        $isSel = ((string) $selected === (string) $fy['fy_code']);
        $sel = $isSel ? ' selected' : '';
        $star = $fy['is_current'] ? ' ★ Current' : '';
        $lbl = htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']);
        $val = htmlspecialchars($fy['fy_code']);
        // Highlight current FY in the dropdown
        $style = $fy['is_current'] ? ' style="font-weight:700;color:#16a34a;"' : '';
        $html .= "    <option value=\"{$val}\"{$sel}{$style}>{$lbl}{$star}</option>\n";
    }
    $html .= '</select>';
    return $html;
}