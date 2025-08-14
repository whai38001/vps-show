<?php
require_once __DIR__ . '/i18n.php';

// Unified pagination component: numbered controls + jump + per-page
function render_pagination(array $opts): void {
    $page = max(1, (int)($opts['page'] ?? 1));
    $totalPages = max(1, (int)($opts['total_pages'] ?? 1));
    $totalItems = max(0, (int)($opts['total_items'] ?? 0));
    $baseQuery = $opts['base_query'] ?? [];
    $pageParam = $opts['page_param'] ?? 'page';
    $align = $opts['align'] ?? 'center';
    $window = max(0, (int)($opts['window'] ?? 2));
    $perPageOptions = $opts['per_page_options'] ?? null;
    $perPageParam = $opts['per_page_param'] ?? 'page_size';
    $perPageValue = isset($opts['per_page_value']) ? (int)$opts['per_page_value'] : null;

    $alignClass = 'justify-center';
    if ($align === 'flex-end' || $align === 'end' || $align === 'right') { $alignClass = 'justify-end'; }
    if ($align === 'flex-start' || $align === 'start' || $align === 'left') { $alignClass = ''; }
    echo '<nav class="pager ' . $alignClass . '">';
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
        if ($start > 2) { echo '<span class="muted small ellipsis">…</span>'; }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === 1 || $i === $totalPages) { continue; }
        $renderLink($i, (string)$i, $i===$page);
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) { echo '<span class="muted small ellipsis">…</span>'; }
        $renderLink($totalPages, (string)$totalPages, $page===$totalPages);
    }

    echo '<span class="muted opacity-60 self-center">' . str_replace(['{x}','{y}'], [$page, $totalPages], t('page_x_of_y')) . ' · ' . t('total_items', ['n' => $totalItems]) . '</span>';
    $renderLink(min($totalPages, $page+1), t('next_page'), $page>=$totalPages);
    $renderLink($totalPages, t('last_page'), $page>=$totalPages);
    echo '</nav>';
    echo '<form method="get" class="pager-form ' . $alignClass . '">';
    foreach ($baseQuery as $k => $v) {
        // Avoid duplicating current page param and per-page param as hidden fields
        if ($k === $pageParam) { continue; }
        if ($k === $perPageParam) { continue; }
        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }
    echo '<label class="small nowrap">' . htmlspecialchars(t('jump_to')) . '</label>';
    echo '<input class="input w90" type="number" name="' . htmlspecialchars($pageParam) . '" min="1" max="' . (int)$totalPages . '" value="' . (int)$page . '">';
    echo '<button class="btn" type="submit">' . htmlspecialchars(t('go')) . '</button>';
    if (is_array($perPageOptions) && !empty($perPageOptions)) {
        echo '<label class="small nowrap ml8">' . htmlspecialchars(t('per_page')) . '</label>';
        echo '<select name="' . htmlspecialchars($perPageParam) . '" class="input js-auto-submit w84">';
        foreach ($perPageOptions as $opt) {
            $opt = (int)$opt; $sel = ($perPageValue!==null && $perPageValue===$opt) ? ' selected' : '';
            echo '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
        }
        echo '</select>';
    }
    echo '</form>';
}
