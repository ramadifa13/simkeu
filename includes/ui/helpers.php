<?php
/**
 * Shared UI helpers: query params, pagination, action menu, ellipsis.
 * Tidak mengubah business logic — hanya presentasi & navigasi list.
 */

if (!function_exists('ui_query_params')) {
    /**
     * @param array $filterKeys extra GET keys for filters (e.g. status, jenis, mulai)
     * @return array{q:string,page:int,per_page:int,sort:string,dir:string,filters:array}
     */
    function ui_query_params(array $filterKeys = []): array
    {
        $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }
        $sort = isset($_GET['sort']) && is_string($_GET['sort']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['sort']) : '';
        $dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $filters = [];
        foreach ($filterKeys as $key) {
            $filters[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : (string)($_GET[$key] ?? '');
        }
        return [
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'filters' => $filters,
        ];
    }
}

if (!function_exists('ui_build_qs')) {
    /** Build query string preserving params; $overrides replace/remove (null = remove). */
    function ui_build_qs(array $base, array $overrides = []): string
    {
        $params = $base;
        foreach ($overrides as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        // Drop empty
        $params = array_filter($params, static function ($v) {
            return $v !== '' && $v !== null;
        });
        return $params ? ('?' . http_build_query($params)) : '';
    }
}

if (!function_exists('ui_qs_from_request')) {
    function ui_qs_from_request(array $extra = [], array $remove = []): string
    {
        $params = $_GET;
        foreach ($remove as $k) {
            unset($params[$k]);
        }
        foreach ($extra as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        $params = array_filter($params, static function ($v) {
            return $v !== '' && $v !== null;
        });
        return $params ? ('?' . http_build_query($params)) : '';
    }
}

if (!function_exists('ui_paginate')) {
    /**
     * @return array{offset:int,per_page:int,page:int,total:int,pages:int}
     */
    function ui_paginate(int $total, int $page, int $perPage): array
    {
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        return [
            'offset' => $offset,
            'per_page' => $perPage,
            'page' => $page,
            'total' => $total,
            'pages' => $pages,
        ];
    }
}

if (!function_exists('ui_cell_ellipsis')) {
    function ui_cell_ellipsis(?string $text, int $max = 40): string
    {
        $text = (string)$text;
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (mb_strlen($text) <= $max) {
            return '<span class="ui-ellipsis">' . $safe . '</span>';
        }
        $short = htmlspecialchars(mb_substr($text, 0, $max) . '…', ENT_QUOTES, 'UTF-8');
        return '<span class="ui-ellipsis" title="' . $safe . '">' . $short . '</span>';
    }
}

if (!function_exists('ui_action_menu')) {
    /**
     * @param array<int,array{label:string,href?:string,onclick?:string,class?:string,icon?:string,disabled?:bool,divider?:bool,form?:string,attrs?:array<string,scalar>,target?:string,title?:string,hidden?:bool}> $items
     */
    function ui_action_menu(array $items, string $ariaLabel = 'Aksi baris'): string
    {
        $items = array_values(array_filter($items, static function ($it) {
            return empty($it['hidden']);
        }));
        if ($items === []) {
            return '';
        }
        $renderAttrs = static function (array $attrs): string {
            $out = '';
            foreach ($attrs as $ak => $av) {
                if ($av === null || $av === false) {
                    continue;
                }
                $name = preg_replace('/[^a-zA-Z0-9_:-]/', '', (string)$ak);
                if ($name === '') {
                    continue;
                }
                if ($av === true) {
                    $out .= ' ' . $name;
                    continue;
                }
                $out .= ' ' . $name . '="' . htmlspecialchars((string)$av, ENT_QUOTES, 'UTF-8') . '"';
            }
            return $out;
        };
        $id = 'am-' . bin2hex(random_bytes(4));
        // strategy:fixed agar menu tidak terpotong overflow .table-responsive
        $html = '<div class="dropdown ui-action-menu">';
        $html .= '<button class="btn btn-sm btn-outline-secondary ui-action-toggle" type="button"'
            . ' data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-popper-config=\'{"strategy":"fixed"}\''
            . ' aria-expanded="false" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<i class="fas fa-ellipsis-v" aria-hidden="true"></i><span class="visually-hidden">Aksi</span>';
        $html .= '</button><ul class="dropdown-menu dropdown-menu-end shadow" id="' . $id . '">';
        foreach ($items as $it) {
            if (!empty($it['divider'])) {
                $html .= '<li><hr class="dropdown-divider"></li>';
                continue;
            }
            $label = htmlspecialchars((string)($it['label'] ?? 'Aksi'), ENT_QUOTES, 'UTF-8');
            // Standar item ⋮: selalu ikon + teks
            $iconClass = trim((string)($it['icon'] ?? 'fas fa-circle'));
            if ($iconClass === '') {
                $iconClass = 'fas fa-circle';
            }
            $icon = '<i class="' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i><span>' . $label . '</span>';
            $cls = trim('ui-action-item ' . (string)($it['class'] ?? ''));
            $cls = htmlspecialchars($cls, ENT_QUOTES, 'UTF-8');
            $disabled = !empty($it['disabled']);
            $extraAttrs = $renderAttrs(isset($it['attrs']) && is_array($it['attrs']) ? $it['attrs'] : []);
            if (!empty($it['title'])) {
                $extraAttrs .= ' title="' . htmlspecialchars((string)$it['title'], ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($disabled) {
                $html .= '<li><span class="dropdown-item disabled ' . $cls . '"' . $extraAttrs . '>' . $icon . '</span></li>';
                continue;
            }
            $onAttr = !empty($it['onclick'])
                ? ' onclick="' . htmlspecialchars((string)$it['onclick'], ENT_QUOTES, 'UTF-8') . '"'
                : '';
            if (!empty($it['href'])) {
                $href = htmlspecialchars((string)$it['href'], ENT_QUOTES, 'UTF-8');
                $extra = !empty($it['target']) ? ' target="' . htmlspecialchars($it['target'], ENT_QUOTES, 'UTF-8') . '" rel="noopener"' : '';
                $html .= '<li><a class="dropdown-item ' . $cls . '" href="' . $href . '"' . $extra . $onAttr . $extraAttrs . '>' . $icon . '</a></li>';
            } elseif ($onAttr !== '' || $extraAttrs !== '') {
                $html .= '<li><button type="button" class="dropdown-item ' . $cls . '"' . $onAttr . $extraAttrs . '>' . $icon . '</button></li>';
            } else {
                $html .= '<li><span class="dropdown-item ' . $cls . '">' . $icon . '</span></li>';
            }
        }
        $html .= '</ul></div>';
        return $html;
    }
}

if (!function_exists('ui_page_header')) {
    /**
     * Compact page title row used across list/form screens.
     * @param array{title:string,icon?:string,subtitle?:string,actions?:string} $opts
     */
    function ui_page_header(array $opts = []): void
    {
        $title = (string)($opts['title'] ?? '');
        $icon = (string)($opts['icon'] ?? '');
        $subtitle = (string)($opts['subtitle'] ?? '');
        $actions = (string)($opts['actions'] ?? '');
        echo '<div class="ui-page-header">';
        echo '<div class="min-w-0">';
        echo '<h1 class="h2 mb-0">';
        if ($icon !== '') {
            echo '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' me-1" aria-hidden="true"></i>';
        }
        echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="text-muted small mb-0 mt-1">' . $subtitle . '</p>';
        }
        echo '</div>';
        if ($actions !== '') {
            echo '<div class="ui-page-header-actions d-flex flex-wrap gap-2">' . $actions . '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_table_toolbar')) {
    /**
     * @param array $filters [['name'=>'status','label'=>'Status','type'=>'select','options'=>[''=>'Semua','aktif'=>'Aktif']], ...]
     */
    function ui_table_toolbar(array $opts = []): void
    {
        $action = $opts['action'] ?? '';
        $q = $opts['q'] ?? '';
        $placeholder = $opts['placeholder'] ?? 'Cari…';
        $perPage = (int)($opts['per_page'] ?? 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }
        $filters = $opts['filters'] ?? [];
        $extraHidden = $opts['hidden'] ?? [];
        echo '<form method="get" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="ui-table-toolbar row g-2 align-items-end mb-3">';
        foreach ($extraHidden as $hk => $hv) {
            echo '<input type="hidden" name="' . htmlspecialchars($hk, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$hv, ENT_QUOTES, 'UTF-8') . '">';
        }
        echo '<div class="col-12 col-md-4"><label class="form-label">Cari</label>';
        echo '<input type="search" name="q" class="form-control" value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"></div>';
        foreach ($filters as $f) {
            $name = htmlspecialchars((string)$f['name'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)($f['label'] ?? $name), ENT_QUOTES, 'UTF-8');
            $type = $f['type'] ?? 'select';
            $val = (string)($f['value'] ?? '');
            echo '<div class="col-6 col-md-2"><label class="form-label">' . $label . '</label>';
            if ($type === 'date') {
                echo '<input type="date" name="' . $name . '" class="form-control" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '">';
            } elseif ($type === 'text') {
                echo '<input type="text" name="' . $name . '" class="form-control" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '">';
            } else {
                echo '<select name="' . $name . '" class="form-select">';
                foreach (($f['options'] ?? []) as $ov => $ol) {
                    $sel = ((string)$ov === $val) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars((string)$ov, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars((string)$ol, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                echo '</select>';
            }
            echo '</div>';
        }
        echo '<div class="col-6 col-md-2"><label class="form-label">Per halaman</label>';
        echo '<select name="per_page" class="form-select">';
        foreach ([10, 20, 50] as $n) {
            $sel = $perPage === $n ? ' selected' : '';
            echo '<option value="' . $n . '"' . $sel . '>' . $n . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col-12 col-md-auto d-flex gap-2">';
        echo '<button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Terapkan</button>';
        $resetHref = $opts['reset_href'] ?? strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars((string)$resetHref, ENT_QUOTES, 'UTF-8') . '">Reset</a>';
        echo '</div></form>';
    }
}

if (!function_exists('ui_pagination')) {
    function ui_pagination(array $pager, string $basePath = '', string $pageKey = 'page'): void
    {
        $page = (int)$pager['page'];
        $pages = (int)$pager['pages'];
        $total = (int)$pager['total'];
        if ($total < 1) {
            echo '<p class="text-secondary small mb-0">Tidak ada data.</p>';
            return;
        }
        echo '<div class="ui-pagination d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">';
        echo '<span class="text-secondary small">Menampilkan halaman ' . $page . ' dari ' . $pages . ' (' . number_format($total) . ' data)</span>';
        echo '<nav aria-label="Pagination"><ul class="pagination mb-0">';
        $link = static function (int $p) use ($basePath, $pageKey): string {
            return htmlspecialchars($basePath . ui_qs_from_request([$pageKey => $p]), ENT_QUOTES, 'UTF-8');
        };
        $prevDisabled = $page <= 1 ? ' disabled' : '';
        $nextDisabled = $page >= $pages ? ' disabled' : '';
        echo '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . ($page <= 1 ? '#' : $link($page - 1)) . '">Prev</a></li>';
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $page ? ' active' : '';
            echo '<li class="page-item' . $active . '"><a class="page-link" href="' . $link($i) . '">' . $i . '</a></li>';
        }
        echo '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . ($page >= $pages ? '#' : $link($page + 1)) . '">Next</a></li>';
        echo '</ul></nav></div>';
    }
}

if (!function_exists('ui_stat_grid')) {
    /**
     * Dense KPI cards (SSOT with dashboard).
     * @param array<int,array{label:string,value:string|int|float,hint?:string,tone?:string,icon?:string}> $items
     */
    function ui_stat_grid(array $items): void
    {
        $items = array_values($items);
        if ($items === []) {
            return;
        }
        $count = count($items);
        echo '<div class="ui-stat-grid mb-3" data-count="' . (int)$count . '">';
        foreach ($items as $kpi) {
            $tone = preg_replace('/[^a-z]/', '', strtolower((string)($kpi['tone'] ?? 'primary'))) ?: 'primary';
            $icon = (string)($kpi['icon'] ?? 'ti ti-chart-bar');
            $label = htmlspecialchars((string)($kpi['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $hint = htmlspecialchars((string)($kpi['hint'] ?? ''), ENT_QUOTES, 'UTF-8');
            $valueRaw = $kpi['value'] ?? '';
            if (is_int($valueRaw) || is_float($valueRaw)) {
                $value = htmlspecialchars(number_format((float)$valueRaw, 0, ',', '.'), ENT_QUOTES, 'UTF-8');
            } else {
                $value = htmlspecialchars((string)$valueRaw, ENT_QUOTES, 'UTF-8');
            }
            echo '<div class="stat-card tone-' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '">';
            echo '<div class="card-body d-flex justify-content-between align-items-start gap-2">';
            echo '<div class="min-w-0">';
            echo '<p class="mb-0 text-muted small text-uppercase">' . $label . '</p>';
            echo '<h4 class="mb-0">' . $value . '</h4>';
            if ($hint !== '') {
                echo '<small class="text-muted">' . $hint . '</small>';
            }
            echo '</div>';
            echo '<span class="stat-icon" aria-hidden="true"><i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>';
            echo '</div></div>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_flash')) {
    /**
     * Flash session → toast (JS) + noscript fallback alert.
     * Optional $opts['href'] + $opts['link_label'] for success action link.
     */
    function ui_flash(array $opts = []): void
    {
        $linkHref = (string)($opts['href'] ?? '');
        $linkLabel = (string)($opts['link_label'] ?? 'Lihat bukti');
        if (!empty($_SESSION['success'])) {
            $raw = (string)$_SESSION['success'];
            unset($_SESSION['success']);
            $msg = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            $extra = '';
            if ($linkHref !== '') {
                $extra = ' data-ui-toast-href="' . htmlspecialchars($linkHref, ENT_QUOTES, 'UTF-8') . '" data-ui-toast-link="' . htmlspecialchars($linkLabel, ENT_QUOTES, 'UTF-8') . '"';
            }
            echo '<div class="ui-toast-source" hidden data-ui-toast="success" data-ui-toast-message="' . $msg . '"' . $extra . '></div>';
            echo '<noscript><div class="alert alert-success" role="alert">' . $msg . '</div></noscript>';
        }
        if (!empty($_SESSION['error'])) {
            $msg = htmlspecialchars((string)$_SESSION['error'], ENT_QUOTES, 'UTF-8');
            unset($_SESSION['error']);
            echo '<div class="ui-toast-source" hidden data-ui-toast="danger" data-ui-toast-message="' . $msg . '"></div>';
            echo '<noscript><div class="alert alert-danger" role="alert">' . $msg . '</div></noscript>';
        }
    }
}

if (!function_exists('ui_page_key')) {
    /** Read page number from a custom GET key (for dual lists). */
    function ui_page_key(string $key = 'page'): int
    {
        return max(1, (int)($_GET[$key] ?? 1));
    }
}

if (!function_exists('ui_slice_rows')) {
    /**
     * In-memory pagination after filters (safe when query already loaded).
     * @param array $rows
     * @return array{rows:array,pager:array}
     */
    function ui_slice_rows(array $rows, ?int $page = null, ?int $perPage = null): array
    {
        $ui = ui_query_params();
        $page = $page ?? $ui['page'];
        $perPage = $perPage ?? $ui['per_page'];
        $pager = ui_paginate(count($rows), $page, $perPage);
        return [
            'rows' => array_slice($rows, $pager['offset'], $pager['per_page']),
            'pager' => $pager,
        ];
    }
}

if (!function_exists('ui_bind_params')) {
    /** Bind named params onto a PDOStatement. */
    function ui_bind_params(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
    }
}
