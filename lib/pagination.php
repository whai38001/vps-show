<?php
require_once __DIR__ . '/i18n.php';

function render_pagination_controls(array $opts): void {
    $page = max(1, (int)($opts['page'] ?? 1));
    $totalPages = max(1, (int)($opts['total_pages'] ?? 1));
    $totalItems = max(0, (int)($opts['total_items'] ?? 0));
    $baseQuery = $opts['base_query'] ?? [];
    $pageParam = $opts['page_param'] ?? 'page';
    $align = $opts['align'] ?? 'center';
    $window = max(0, (int)($opts['window'] ?? 2));

    if ($totalPages <= 1) { return; }

    echo '<nav style="display:flex; gap:8px; justify-content:' . htmlspecialchars($align) . '; margin:18px 0 8px; align-items:center; flex-wrap:wrap;">';
    $renderLink = function($p, $label, $disabled=false) use ($baseQuery, $pageParam) {
        $q = $baseQuery; $q[$pageParam] = $p; $href = '?' . http_build_query($q);
        $cls = 'btn'; if ($disabled) { $cls .= ' disabled'; $href = 'javascript:void(0)'; }
        echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
    };

    $renderLink(1, t('first_page'), $page<=1);
    $renderLink(max(1, $page-1), t('prev_page'), $page<=1);

    $start = max(1, $page - $window); $end = min($totalPages, $page + $window);
    if ($start > 1) {
        $renderLink(1, '1', $page===1);
        if ($start > 2) { echo '<span class="muted small" style="align-self:center;">…</span>'; }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === 1 || $i === $totalPages) { continue; }
        $renderLink($i, (string)$i, $i===$page);
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) { echo '<span class="muted small" style="align-self:center;">…</span>'; }
        $renderLink($totalPages, (string)$totalPages, $page===$totalPages);
    }

    echo '<span style="opacity:.6;align-self:center;">' . str_replace(['{x}','{y}'], [$page, $totalPages], t('page_x_of_y')) . ' · ' . t('total_items', ['n' => $totalItems]) . '</span>';
    $renderLink(min($totalPages, $page+1), t('next_page'), $page>=$totalPages);
    $renderLink($totalPages, t('last_page'), $page>=$totalPages);
    echo '</nav>';
}

function render_pagination_jump_form(array $opts): void {
    $page = max(1, (int)($opts['page'] ?? 1));
    $totalPages = max(1, (int)($opts['total_pages'] ?? 1));
    $baseQuery = $opts['base_query'] ?? [];
    $pageParam = $opts['page_param'] ?? 'page';
    $align = $opts['align'] ?? 'center';

    if ($totalPages <= 1) { return; }

    echo '<form method="get" style="display:flex; gap:8px; justify-content:' . htmlspecialchars($align) . '; align-items:center; margin:8px 0 12px; flex-wrap:wrap;">';
    foreach ($baseQuery as $k => $v) {
        if ($k === $pageParam) { continue; }
        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }
    echo '<label class="small" style="color:#9ca3af;">' . htmlspecialchars(t('jump_to')) . '</label>';
    echo '<input class="input" style="width:90px;" type="number" name="' . htmlspecialchars($pageParam) . '" min="1" max="' . (int)$totalPages . '" value="' . (int)$page . '">';
    echo '<button class="btn" type="submit">' . htmlspecialchars(t('go')) . '</button>';
    echo '</form>';
}
