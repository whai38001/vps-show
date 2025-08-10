<?php
require_once __DIR__ . '/config.php';

function i18n_current_lang(): string {
    $allowed = ['zh', 'en'];
    $lang = 'zh';
    if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed, true)) {
        $lang = $_GET['lang'];
        if (!headers_sent()) {
            @setcookie('lang', $lang, time() + 180 * 24 * 3600, '/', '', false, true);
        }
    } elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $allowed, true)) {
        $lang = $_COOKIE['lang'];
    } else {
        // Fallback: detect by Accept-Language header
        $al = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($al) {
            $alLower = strtolower($al);
            if (strpos($alLower, 'zh') !== false) {
                $lang = 'zh';
            } elseif (strpos($alLower, 'en') !== false) {
                $lang = 'en';
            }
        }
    }
    return $lang;
}

function i18n_messages(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) { return $cache[$lang]; }
    $ZH = [
        'search_placeholder' => '搜索套餐或厂商',
        'filters_all_vendors' => '全部厂商',
        'filters_all_billing' => '全部周期',
        'billing_per_month' => '月付',
        'billing_per_year' => '年付',
        'billing_one_time' => '一次性',
        'sort_default' => '默认排序',
        'sort_price_asc' => '价格从低到高',
        'sort_price_desc' => '价格从高到低',
        'sort_newest' => '最新发布',
        'sort_cpu_desc' => 'CPU核数：高到低',
        'sort_cpu_asc' => 'CPU核数：低到高',
        'sort_ram_desc' => '内存：高到低',
        'sort_ram_asc' => '内存：低到高',
        'sort_storage_desc' => '存储：高到低',
        'sort_storage_asc' => '存储：低到高',
        'filter_button' => '筛选',
        'admin_panel' => '管理后台',
        'recently_added' => '最近新增',
        'prev_page' => '上一页',
        'next_page' => '下一页',
        'page_x_of_y' => '第 {x} / {y} 页',
        'first_page' => '第一页',
        'last_page' => '最后一页',
        'total_items' => '共 {n} 条',
        'jump_to' => '跳转到',
        'go' => '跳转',
        'no_data' => '暂无数据，请先访问 {seed_link} 导入示例套餐。',
        'seed_script' => '初始化脚本',
        'order_now' => '立即订购',
        'lang_zh' => '中文',
        'lang_en' => 'EN',
        'label_location' => '机房/地区',
        'input_min' => '最小价',
        'input_max' => '最大价',
        'input_min_cpu' => 'CPU≥',
        'input_min_ram_gb' => '内存≥(GB)',
        'input_min_storage_gb' => '存储≥(GB)',
        'reset' => '重置',
        'per_page' => '每页',
        'site_title' => SITE_NAME,
        // Compare UI
        'compare' => '对比',
        'compare_selected' => '已选 {n} 项',
        'clear_all' => '清空',
        'max_compare_hint' => '最多选择 {n} 项进行对比',
        'close' => '关闭',
        'col_vendor_plan' => '厂商 / 套餐',
        'col_price' => '价格',
        'col_price_billing' => '价格/周期',
        'col_duration' => '周期',
        'col_location' => '机房/地区',
        'col_features' => '特性',
        'col_cpu' => 'CPU',
        'col_ram' => '内存',
        'col_storage' => '存储',
        'unit_vcpu' => 'vCPU',
        'unit_gb' => 'GB',
        'copy' => '复制',
        'export_csv' => '导出CSV',
        'copied' => '已复制',
    ];
    $EN = [
        'search_placeholder' => 'Search plans or vendors',
        'filters_all_vendors' => 'All vendors',
        'filters_all_billing' => 'All billing cycles',
        'billing_per_month' => 'Monthly',
        'billing_per_year' => 'Yearly',
        'billing_one_time' => 'One-time',
        'sort_default' => 'Default',
        'sort_price_asc' => 'Price: Low to High',
        'sort_price_desc' => 'Price: High to Low',
        'sort_newest' => 'Newest',
        'sort_cpu_desc' => 'CPU cores: High to Low',
        'sort_cpu_asc' => 'CPU cores: Low to High',
        'sort_ram_desc' => 'RAM: High to Low',
        'sort_ram_asc' => 'RAM: Low to High',
        'sort_storage_desc' => 'Storage: High to Low',
        'sort_storage_asc' => 'Storage: Low to High',
        'filter_button' => 'Filter',
        'admin_panel' => 'Admin',
        'recently_added' => 'Recently Added',
        'prev_page' => 'Prev',
        'next_page' => 'Next',
        'page_x_of_y' => 'Page {x} / {y}',
        'first_page' => 'First',
        'last_page' => 'Last',
        'total_items' => 'Total {n}',
        'jump_to' => 'Jump to',
        'go' => 'Go',
        'no_data' => 'No data yet. Please visit {seed_link} to import sample plans.',
        'seed_script' => 'seed script',
        'order_now' => 'Order Now',
        'lang_zh' => '中文',
        'lang_en' => 'EN',
        'label_location' => 'Location/Region',
        'input_min' => 'Min price',
        'input_max' => 'Max price',
        'input_min_cpu' => 'CPU≥',
        'input_min_ram_gb' => 'RAM≥(GB)',
        'input_min_storage_gb' => 'Storage≥(GB)',
        'reset' => 'Reset',
        'per_page' => 'Per page',
        'site_title' => SITE_NAME,
        // Compare UI
        'compare' => 'Compare',
        'compare_selected' => '{n} selected',
        'clear_all' => 'Clear',
        'max_compare_hint' => 'Select up to {n} plans to compare',
        'close' => 'Close',
        'col_vendor_plan' => 'Vendor / Plan',
        'col_price' => 'Price',
        'col_price_billing' => 'Price/Billing',
        'col_duration' => 'Billing',
        'col_location' => 'Location',
        'col_features' => 'Features',
        'col_cpu' => 'CPU',
        'col_ram' => 'RAM',
        'col_storage' => 'Storage',
        'unit_vcpu' => 'vCPU',
        'unit_gb' => 'GB',
        'copy' => 'Copy',
        'export_csv' => 'Export CSV',
        'copied' => 'Copied',
    ];
    $cache['zh'] = $ZH; $cache['en'] = $EN;
    return $cache[$lang] ?? $ZH;
}

function t(string $key, array $vars = []): string {
    $lang = i18n_current_lang();
    $msgs = i18n_messages($lang);
    $text = $msgs[$key] ?? $key;
    if ($vars) {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
    }
    return $text;
}

function i18n_duration_label(string $duration): string {
    $duration = strtolower(trim($duration));
    // Normalize common forms like "/mo", "monthly", "per-month", etc.
    $normalized = preg_replace('/\s+/', ' ', $duration);
    $normalized = str_replace(['per-month','per-monthly','per year','per-year'], ['per month','per month','per year','per year'], $normalized);
    if (preg_match('/(^|\s)(per month|monthly|mo|\/mo|month)(\s|$)/i', $duration)) {
        return t('billing_per_month');
    }
    if (preg_match('/(^|\s)(per year|yearly|annual|annually|yr|\/yr|year)(\s|$)/i', $duration)) {
        return t('billing_per_year');
    }
    if (preg_match('/(^|\s)(one[- ]?time|lifetime|oneoff|one\s*time)(\s|$)/i', $duration)) {
        // Map lifetime/one-time to one-time label for simplicity
        return t('billing_one_time');
    }
    return $duration;
}

function i18n_build_lang_url(string $toLang): string {
    $qs = $_GET;
    $qs['lang'] = $toLang;
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '?', '?');
    return htmlspecialchars($uri . '?' . http_build_query($qs));
}

/**
 * Translate plan-related free text to current language (best-effort, glossary-based)
 */
/**
 * Core glossary/regex translation to Chinese, regardless of current language.
 */
function i18n_text_to_zh(string $text): string {
    $out = $text;
    // Structured patterns first
    $patterns = [
        // Features: -> 特性：
        '/\bFeatures\s*:/i' => '特性：',
        // rDNS/PTR self-update via panel -> 面板支持 rDNS/PTR 自助更新
        '/rDNS\s*\/\s*PTR\s*self[- ]?update\s+via\s+panel/i' => '面板支持 rDNS/PTR 自助更新',
        // Via panel (generic)
        '/\bvia\s+panel\b/i' => '通过面板',
        // All ports opened (including ... ) -> 端口全开（含 ...）
        '/\ball\s+ports\s+open(?:ed)?\s*\(([^)]*)\)/i' => '端口全开（$1）',
        '/\ball\s+ports\s+open(?:ed)?\b/i' => '端口全开',
        // Convert English parenthetical "including" after we localize parentheses
        '/（including\s+/i' => '（含 ',
        // SMTP: -> SMTP：
        '/\bSMTP\s*:\s*/i' => 'SMTP：',
        // Common spec labels with colon -> Chinese full-width colon
        '/\bCPU\s*Core\s*:\s*-?\s*/i' => 'CPU：',
        '/\bCPU\s*:\s*/i' => 'CPU：',
        '/\bCPU\s*:\s*-?\s*/i' => 'CPU：',
        '/\bRAM\s*:\s*/i' => '内存：',
        '/\bRAM\s*:\s*-?\s*/i' => '内存：',
        '/\bMemory\s*:\s*/i' => '内存：',
        '/\bMemory\s*:\s*-?\s*/i' => '内存：',
        '/\bStorage\s*:\s*/i' => '存储：',
        '/\bStorage\s*:\s*-?\s*/i' => '存储：',
        '/\bHard\s*Drive\s*:\s*-?\s*/i' => '存储：',
        '/\bBandwidth\s*:\s*/i' => '带宽：',
        '/\bBandwidth\s*:\s*-?\s*/i' => '带宽：',
        '/\bPort\s*:\s*/i' => '端口：',
        '/\bPort\s*:\s*-?\s*/i' => '端口：',
        '/\bIP\s*:\s*/i' => 'IP：',
        '/\bFrequency\s*:\s*/i' => '频率：',
        '/\bIP\s*:\s*-?\s*/i' => 'IP：',
        '/\bHypervisor\s*:\s*-?\s*/i' => '虚拟化：',
        '/\bPermission\s*:\s*-?\s*/i' => '权限：',
        '/\bOperating\s*System\s*:\s*-?\s*/i' => '操作系统：',
        '/\bData\s*Center\s*:\s*-?\s*/i' => '机房：',
        '/\bData[Cc]enter\s*:\s*-?\s*/i' => '机房：',
        '/\bNote\s*:\s*/i' => '注意：',
        '/\bBonus\s*:\s*/i' => '赠品：',
        '/\bChoose\s+between\s*:\s*/i' => '可选：',
        // Normalize colon followed by stray hyphen
        '/：\s*-\s*/u' => '：',
        '/:\s*-\s*/' => '：',
        // Unmetered / FREE
        '/\bUnmetered\b/i' => '不限量',
        '/\bFREE\b/i' => '免费',
        // Backup Included Free
        '/\bBackup\s+Included\s+Free\b/i' => '包含免费备份',
        '/\bGet\s+a\s+second\s+VPS\s+FREE\b/i' => '免费赠送第二台 VPS',
        // With X Panel
        '/\bWith\s+([A-Za-z0-9]+)\s+Panel\b/i' => '配备 $1 面板',
        // Get this <name> with VirtFusion in Dallas:
        '/Get\s+this\s+(.+?)\s+with\s+VirtFusion\s+in\s+Dallas\s*:/i' => '达拉斯 VirtFusion $1：',
        // (based on availability)
        '/\(based\s+on\s+availability\)/i' => '（视库存情况而定）',
        // 2-for-1 offer ended & single VPS price
        '/\b2\s*[-–]?\s*for\s*[-–]?\s*1\b/i' => '买一赠一',
        '/\boffer\s+has\s+ended\b/i' => '优惠已结束',
        '/This\s+price\s+is\s+for\s+a\s+single\s+VPS\s+only\.?/i' => '该价格仅适用于单台 VPS。',
        // Normalize Chinese mix: v核心 -> vCPU
        '/\bv核心\b/i' => 'vCPU',
        '/vCPU\s*核心/u' => 'vCPU',
        // Normalize patterns like "1 Gbps-Unmetered" -> "1 Gbps 不限量"
        '/([0-9]+(?:\.[0-9]+)?\s*(?:Gbps|Mbps))\s*[-–]\s*不限量/i' => '$1 不限量',
        // Cleanup parentheses around words like ( 共享 ) / (Shared)
        '/\(\s*共享\s*\)/u' => '共享',
        '/\(\s*Shared\s*\)/i' => '共享',
        // Linux & Windows -> Linux / Windows
        '/Linux\s*&\s*Windows/i' => 'Linux / Windows',
        // free snapshot 3x / free 3 snapshots -> 赠送 N 次快照
        '/\bfree\s+snapshot\s*(\d+)x\b/i' => '赠送 $1 次快照',
        '/\bfree\s*(\d+)\s*snapshots?\b/i' => '赠送 $1 次快照',
        // Monitoring -> 监控
        '/\bMonitoring\b/i' => '监控',
        // and other(s) -> 及其他
        '/\band\s+other(s)?\b/i' => '及其他',
        // Combined: 3 TB/Mo on 1 Gb/s -> 3 TB 月流量 · 1 Gbps
        '/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*\/\s*(mo|month)\s*on\s*([0-9]+(?:\.[0-9]+)?)\s*(Gb\/s|Gbps|Gbit\/s)/i' => '$1 $2 月流量 · $4 Gbps',
        // Normalize Gb/s or Gbit/s -> Gbps (unit normalization)
        '/\bGb\/s\b/i' => 'Gbps',
        '/\bGbit\/s\b/i' => 'Gbps',
        '/\b([0-9]+(?:\.[0-9]+)?)\s*Gbit\b/i' => '$1 Gbps',
        '/\b([0-9]+(?:\.[0-9]+)?)\s*Ghz\b/i' => '$1 GHz',
        '/\b([0-9]+(?:\.[0-9]+)?)\s*Ghz\b/i' => '$1 GHz',
        // X TB/Mo -> X TB 月流量
        '/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*\/\s*(mo|month)\b/i' => '$1 $2 月流量',
        // 3000 GB Monthly Transfer / Bandwidth -> 3000 GB 月流量
        '/([0-9,.]+)\s*GB\s*(?:Monthly\s*)?(?:Transfer|Bandwidth)/i' => '$1 GB 月流量',
        // 1 Gbps Network Port -> 1 Gbps 网络端口
        '/([0-9.]+)\s*Gbps\s*(?:Network\s*)?Port/i' => '$1 Gbps 网络端口',
        '/([0-9]+)\s*Mbps\s*(?:Network\s*)?Port/i' => '$1 Mbps 网络端口',
        // 1 Gbps Shared -> 1 Gbps 共享
        '/([0-9.]+)\s*Gbps\s*Shared/i' => '$1 Gbps 共享',
        '/([0-9]+)\s*Mbps\s*Shared/i' => '$1 Mbps 共享',
        // 4 vCPU / 2 CPU -> 4 vCPU
        '/([0-9]+)\s*v?CPUs?\b/i' => '$1 vCPU',
        // NAT/DNS features included
        '/\bNAT46\s*\/\s*NAT64\s*\/\s*DNS64\s*Included\b/i' => '包含 NAT46/NAT64/DNS64',
        '/\bC-Servers\s+ExtraNAT\s+Included\b/i' => '包含 C-Servers ExtraNAT',
        '/\bExtraNAT\s+Included\b/i' => '包含 ExtraNAT',
        // X TB Included
        '/([0-9,.]+)\s*(TB|GB)\s*Included\b/i' => '包含 $1 $2',
        // Fair Share / Fair Use
        '/\bFair\s*Share\b/i' => '公平共享',
        '/\bFair\s*Use\b/i' => '公平使用',
        '/\bAfter\s+Exceeded\b/i' => '超出后',
        // 9 GB RAM DDR4 -> 9 GB 内存 DDR4
        '/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\s*RAM\b/i' => '$1 $2 内存',
        // 512 MB Memory -> 512 MB 内存
        '/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\s*Memory\b/i' => '$1 $2 内存',
        // 60 GB NVMe SSD Storage -> 60 GB NVMe SSD 存储
        '/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*(NVMe\s+)?SSD\s*(Storage)?/i' => '$1 $2 ${3}SSD 存储',
        // 100 GB NVMe Storage -> 100 GB NVMe 存储
        '/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*NVMe\s*Storage/i' => '$1 $2 NVMe 存储',
        // 60 GB Storage -> 60 GB 存储
        '/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*Storage/i' => '$1 $2 存储',
        // TUN/TAP Enabled -> 支持 TUN/TAP
        '/\bTUN\s*\/\s*TAP\s*Enabled\b/i' => '支持 TUN/TAP',
        // NAT IPV4/IPV6 -> NAT IPv4/IPv6
        '/\bIPV4\b/i' => 'IPv4',
        '/\bIPV6\b/i' => 'IPv6',
        // 20 usable ports -> 20 个可用端口
        '/\b(\d+)\s+usable\s+ports?\b/i' => '$1 个可用端口',
        // Replace bandwidth style using '@' -> middle dot
        '/\s*@\s*([0-9]+(?:\.[0-9]+)?)\s*(Gbps|Mbps)\b/i' => ' · $1 $2',
        // Renews at $10.99/YR -> 续费 $10.99/年
        '/\bRenews\s+at\s+\$([0-9]+(?:\.[0-9]+)?)\s*\/\s*(YR|Year|yr)\b/i' => '续费 \$$1/年',
        // SSD-Cached RAID-10 Storage -> SSD 缓存 RAID-10 存储
        '/SSD-?Cached\s+RAID-?10\s+Storage\b\.?/i' => 'SSD 缓存 RAID-10 存储',
        // included in all locations -> 在所有机房均可用
        '/included\s+in\s+all\s+locations\b\.?/i' => '在所有机房均可用',
        // including local, international, and IX. -> 包含本地、国际及 IX
        '/including\s+local,\s+international,\s+and\s+IX\.?/i' => '包含本地、国际及 IX',
        // IPv6 only available in -> IPv6 仅在
        '/IPv6\s+only\s+available\s+in\b/i' => 'IPv6 仅在',
    ];
    foreach ($patterns as $re => $rep) {
        $out = preg_replace($re, $rep, $out);
    }
    // Collapse excessive spaces for more reliable replacements
    $out = preg_replace('/\s{2,}/', ' ', $out);
    // Simple glossary replacements
    $map = [
        'HostDZire Special' => 'HostDZire 特惠',
        'Mumbai' => '孟买',
        'India' => '印度',
        'Iron Mountain DC' => '铁山数据中心',
        'No Refund Policy' => '不退款政策',
        'Annually-Special' => '年付特惠',
        'Hypervisor' => '虚拟化',
        'Permission' => '权限',
        'Full Root' => '完全 Root',
        'Operating System' => '操作系统',
        'DataCenter' => '机房',
        'Washington, USA' => '美国华盛顿',
        'Leaseweb DC' => 'Leaseweb 机房',
        'Dallas' => '达拉斯',
        'Multiple Datacenter Locations' => '多个机房/地区',
        'Datacenter Locations' => '机房/地区',
        'Datacenter' => '机房',
        'Los Angeles' => '洛杉矶',
        'Custom kernels are allowed' => '允许自定义内核',
        'Custom kernel is allowed' => '允许自定义内核',
        'Kernel needs to include VirtIO drivers' => '内核需包含 VirtIO 驱动',
        'Kernel must include VirtIO drivers' => '内核必须包含 VirtIO 驱动',
        'Deployed with' => '采用',
        'Time Offer' => '限时优惠',
        'Limited Time Offer' => '限时优惠',
        'Limited Offer' => '限量优惠',
        'Virtualization' => '虚拟化',
        'KVM Virtualization' => 'KVM 虚拟化',
        'KVM / SolusVM Control Panel' => 'KVM / SolusVM 控制面板',
        'FREE Clientexec License' => '赠送 Clientexec 许可',
        'Available in:' => '可选机房：',
        'Multiple Locations' => '多个机房/地区',
        'Dedicated IPv4 Address' => '独立 IPv4 地址',
        'IPv4 Address' => 'IPv4 地址',
        'IP Addresses' => 'IP 地址',
        'IPv6 Address' => 'IPv6 地址',
        'Operating System' => '操作系统',
        'Control Panel' => '控制面板',
        'License' => '许可',
        'Dedicated' => '独立',
        'Test IP' => '测试 IP',
        'Singapore' => '新加坡',
        'Hong Kong' => '香港',
        'Information' => '信息',
        'Always Promo' => '长期促销',
        'Flash Sale' => '限时抢购',
        'Hot' => '热门',
        'Limited' => '限量',
        'VPS BUDGET (ALWAYS PROMO)' => '预算型 VPS（长期促销）',
        'Bandwidth' => '带宽',
        'Transfer' => '流量',
        'Network Port' => '网络端口',
        'Port Speed' => '端口速率',
        'DDoS Protection' => 'DDoS 防护',
        'Locations' => '机房/地区',
        'Location' => '机房/地区',
        'CPU Cores' => 'CPU 核心',
        'Cores' => '核心',
        'RAM' => '内存',
        'Disk' => '磁盘',
        'NVMe' => 'NVMe',
        'SSD' => 'SSD',
        'HDD' => 'HDD',
        'Backups' => '备份',
        'Snapshots' => '快照',
        'Uptime' => '在线率',
        'Support' => '支持',
        'Ticket' => '工单',
        'Premium China Mainland' => '中国大陆优质线路',
        'Full Root Admin Access' => 'Root 管理员完全访问权限',
        'Full Root Access' => '完全 Root 访问权限',
        'Admin Access' => '管理员访问权限',
        'Shared' => '共享',
    ];
    // Replace longer phrases first
    uksort($map, function($a, $b){ return strlen($b) <=> strlen($a); });
    foreach ($map as $en => $zh) {
        $out = str_replace($en, $zh, $out);
    }
    return $out;
}

/**
 * Translate plan-related free text to current language (best-effort, glossary-based)
 * Only applies when current language is zh.
 */
function i18n_text(string $text): string {
    if (i18n_current_lang() !== 'zh') {
        return $text;
    }
    return i18n_text_to_zh($text);
}
