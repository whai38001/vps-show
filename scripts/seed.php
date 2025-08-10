<?php
require_once __DIR__ . '/../lib/db.php';

db_init_schema();
$pdo = get_pdo();

// Upsert vendor
$vendorName = 'RackNerd';
$website = 'https://www.racknerd.com';
$logo = 'https://www.racknerd.com/assets/images/logo.png';
$desc = 'High value KVM VPS Black Friday deals';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO vendors (name, website, logo_url, description)
        VALUES (:name, :website, :logo, :desc)
        ON DUPLICATE KEY UPDATE website = VALUES(website), logo_url = VALUES(logo_url), description = VALUES(description), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([
        ':name' => $vendorName,
        ':website' => $website,
        ':logo' => $logo,
        ':desc' => $desc,
    ]);

    $vendorId = (int)$pdo->lastInsertId();
    if ($vendorId === 0) {
        $vendorId = (int)$pdo->query('SELECT id FROM vendors WHERE name = ' . $pdo->quote($vendorName))->fetchColumn();
    }

    // Clean old plans for this vendor to avoid duplicates on re-run
    $pdo->prepare('DELETE FROM plans WHERE vendor_id = :vid')->execute([':vid' => $vendorId]);

    $plans = [
        [
            'title' => '1GB',
            'subtitle' => 'KVM VPS',
            'price' => 10.99,
            'duration' => 'per year',
            'order_url' => 'https://my.racknerd.com/cart.php?a=add&pid=879',
            'location' => 'Multiple Datacenter Locations',
            'highlights' => 'Black Friday',
            'features' => [
                '1 vCPU Core',
                '20 GB Pure SSD Storage',
                '1 GB RAM',
                '1500 GB Monthly Transfer',
                '1 Gbps Network Port',
                'Full Root Admin Access',
                '1 Dedicated IPv4 Address',
                'KVM / SolusVM Control Panel',
                'FREE Clientexec License',
                'Available in: Multiple Locations',
                'ONLY $10.99/YEAR!'
            ]
        ],
        [
            'title' => '2.5GB',
            'subtitle' => 'KVM VPS',
            'price' => 18.93,
            'duration' => 'per year',
            'order_url' => 'https://my.racknerd.com/cart.php?a=add&pid=880',
            'location' => 'Multiple Datacenter Locations',
            'highlights' => 'Hot',
            'features' => [
                '2 vCPU Cores',
                '40 GB Pure SSD Storage',
                '2.5 GB RAM',
                '3000 GB Monthly Transfer',
                '1 Gbps Network Port',
                'Full Root Admin Access',
                '1 Dedicated IPv4 Address',
                'KVM / SolusVM Control Panel',
                'FREE Clientexec License',
                'Available in: Multiple Locations',
                'ONLY $18.93/YEAR!'
            ]
        ],
        [
            'title' => '3GB',
            'subtitle' => 'KVM VPS',
            'price' => 27.89,
            'duration' => 'per year',
            'order_url' => 'https://my.racknerd.com/cart.php?a=add&pid=881',
            'location' => 'Multiple Datacenter Locations',
            'highlights' => '',
            'features' => [
                '2 vCPU Cores',
                '60 GB Pure SSD Storage',
                '3 GB RAM',
                '5500 GB Monthly Transfer',
                '1 Gbps Network Port',
                'Full Root Admin Access',
                '1 Dedicated IPv4 Address',
                'KVM / SolusVM Control Panel',
                'FREE Clientexec License',
                'Available in: Multiple Locations',
                'ONLY $27.89/YEAR!'
            ]
        ],
        [
            'title' => '4.5GB',
            'subtitle' => 'KVM VPS',
            'price' => 39.88,
            'duration' => 'per year',
            'order_url' => 'https://my.racknerd.com/cart.php?a=add&pid=882',
            'location' => 'Multiple Datacenter Locations',
            'highlights' => 'Limited',
            'features' => [
                '3 vCPU Cores',
                '100 GB Pure SSD Storage',
                '4.5 GB RAM',
                '8500 GB Monthly Transfer',
                '1 Gbps Network Port',
                'Full Root Admin Access',
                '1 Dedicated IPv4 Address',
                'KVM / SolusVM Control Panel',
                'FREE Clientexec License',
                'Available in: Multiple Locations',
                'ONLY $39.88/YEAR!'
            ]
        ],
        [
            'title' => '5GB',
            'subtitle' => 'KVM VPS',
            'price' => 55.93,
            'duration' => 'per year',
            'order_url' => 'https://my.racknerd.com/cart.php?a=add&pid=883',
            'location' => 'Multiple Datacenter Locations',
            'highlights' => '',
            'features' => [
                '4 vCPU Cores',
                '130 GB Pure SSD Storage',
                '5 GB RAM',
                '12,000 GB Monthly Transfer',
                '1 Gbps Network Port',
                'Full Root Admin Access',
                '1 Dedicated IPv4 Address',
                'KVM / SolusVM Control Panel',
                'FREE Clientexec License',
                'Available in: Multiple Locations',
                'ONLY $55.93/YEAR!'
            ]
        ]
    ];

    $sort = 1;
    foreach ($plans as $plan) {
        // derive cpu/ram/storage best effort from features
        $cpu = '';$ram='';$storage='';
        foreach ($plan['features'] as $f) {
            if ($cpu === '' && preg_match('/\b(?:[0-9]+(?:\.[0-9]+)?\s*)?v?CPU\b|核心/i', $f)) { $cpu = $f; }
            if ($ram === '' && preg_match('/\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b.*(?:RAM|内存)|\bRAM\b|内存/i', $f)) { $ram = $f; }
            if ($storage === '' && preg_match('/(?:NVMe|SSD|HDD|存储|Storage)/i', $f)) { $storage = $f; }
        }
        $stmt = $pdo->prepare('INSERT INTO plans (vendor_id, title, subtitle, price, price_duration, order_url, location, features, cpu, ram, storage, cpu_cores, ram_mb, storage_gb, highlights, sort_order)
            VALUES (:vendor_id, :title, :subtitle, :price, :duration, :order_url, :location, :features, :cpu, :ram, :storage, :cpu_cores, :ram_mb, :storage_gb, :highlights, :sort_order)
            ON DUPLICATE KEY UPDATE price = VALUES(price), price_duration = VALUES(price_duration), order_url = VALUES(order_url), location = VALUES(location), features = VALUES(features), cpu = VALUES(cpu), ram = VALUES(ram), storage = VALUES(storage), cpu_cores = VALUES(cpu_cores), ram_mb = VALUES(ram_mb), storage_gb = VALUES(storage_gb), highlights = VALUES(highlights), sort_order = VALUES(sort_order), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            ':vendor_id' => $vendorId,
            ':title' => $plan['title'],
            ':subtitle' => $plan['subtitle'],
            ':price' => $plan['price'],
            ':duration' => $plan['duration'],
            ':order_url' => $plan['order_url'],
            ':location' => $plan['location'],
            ':features' => json_encode($plan['features'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':cpu' => $cpu,
            ':ram' => $ram,
            ':storage' => $storage,
            ':cpu_cores' => $cpu_cores ?? null,
            ':ram_mb' => $ram_mb ?? null,
            ':storage_gb' => $storage_gb ?? null,
            ':highlights' => $plan['highlights'],
            ':sort_order' => $sort++,
        ]);
    }

    $pdo->commit();
    echo "Seed completed.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Seed failed: ' . $e->getMessage();
}
