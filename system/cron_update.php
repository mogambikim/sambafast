<?php
/**
 * CRON UPDATE - Fetches current users from MikroTik and updates database
 */

include __DIR__ . "/../init.php";

echo "=== UPDATING ONLINE USERS ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

// Get router
$router = ORM::for_table('tbl_routers')->where('enabled', '1')->find_one();
if (!$router) {
    die("No enabled router found\n");
}
echo "Router: {$router->name} ({$router->ip_address})\n";

// Connect to MikroTik
try {
    $client = Mikrotik::getClient($router->ip_address, $router->username, $router->password);
    echo "✓ Connected to MikroTik\n\n";
} catch (Exception $e) {
    die("✗ Failed to connect: " . $e->getMessage() . "\n");
}

$online_users = [];
$now = date('Y-m-d H:i:s');

// Get Hotspot users
echo "Fetching Hotspot users...\n";
try {
    $hotspot = $client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/active/print'));
    echo "Found " . count($hotspot) . " hotspot users\n";
    
    foreach ($hotspot as $user) {
        $username = $user->getProperty('user');
        if (empty($username)) continue;
        
        $online_users[$username] = [
            'username' => $username,
            'type' => 'hotspot',
            'ip' => $user->getProperty('address'),
            'mac' => $user->getProperty('mac-address'),
            'rx' => intval($user->getProperty('bytes-in')),
            'tx' => intval($user->getProperty('bytes-out')),
            'last_seen' => $now
        ];
        echo "  - $username @ {$online_users[$username]['ip']}\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

// Get PPPoE users
echo "\nFetching PPPoE users...\n";
try {
    $ppp = $client->sendSync(new PEAR2\Net\RouterOS\Request('/ppp/active/print'));
    echo "Found " . count($ppp) . " PPPoE users\n";
    
    foreach ($ppp as $user) {
        $username = $user->getProperty('name');
        if (empty($username)) continue;
        
        $online_users[$username] = [
            'username' => $username,
            'type' => 'pppoe',
            'ip' => $user->getProperty('address'),
            'mac' => $user->getProperty('caller-id'),
            'rx' => intval($user->getProperty('bytes-in')),
            'tx' => intval($user->getProperty('bytes-out')),
            'last_seen' => $now
        ];
        echo "  - $username @ {$online_users[$username]['ip']}\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

// Update database
echo "\n=== Updating Database ===\n";
$updated = 0;
$created = 0;

foreach ($online_users as $username => $data) {
    $session = ORM::for_table('tbl_usage_sessions')
        ->where('username', $username)
        ->find_one();
    
    if ($session) {
        // Update existing
        $session->last_seen = $now;
        $session->rx_bytes = $data['rx'];
        $session->tx_bytes = $data['tx'];
        $session->session_rx = $data['rx'];
        $session->session_tx = $data['tx'];
        $session->ip_address = $data['ip'];
        $session->mac_address = $data['mac'];
        $session->connection_type = $data['type'];
        $session->save();
        $updated++;
        echo "  ✓ Updated: $username\n";
    } else {
        // Create new
        $session = ORM::for_table('tbl_usage_sessions')->create();
        $session->router_id = $router->id;
        $session->username = $username;
        $session->connection_type = $data['type'];
        $session->ip_address = $data['ip'];
        $session->mac_address = $data['mac'];
        $session->rx_bytes = $data['rx'];
        $session->tx_bytes = $data['tx'];
        $session->session_rx = $data['rx'];
        $session->session_tx = $data['tx'];
        $session->start_time = $now;
        $session->last_seen = $now;
        $session->save();
        $created++;
        echo "  ✓ Created: $username\n";
    }
}

// Remove old sessions (offline users)
$old_threshold = date('Y-m-d H:i:s', strtotime('-60 seconds'));
$old = ORM::for_table('tbl_usage_sessions')
    ->where_lt('last_seen', $old_threshold)
    ->delete_many();
echo "\n  Removed $old stale sessions (last seen before $old_threshold)\n";

echo "\n=== SUMMARY ===\n";
echo "  Online Users: " . count($online_users) . "\n";
echo "  Updated: $updated\n";
echo "  Created: $created\n";
echo "  Removed: $old\n";

echo "\n=== CURRENT ONLINE USERS ===\n";
if (count($online_users) > 0) {
    foreach ($online_users as $username => $data) {
        echo "  {$username} ({$data['type']}) - {$data['ip']}\n";
        echo "    RX: " . formatBytes($data['rx']) . " | TX: " . formatBytes($data['tx']) . "\n";
    }
} else {
    echo "  No users online\n";
}

echo "\n✅ Update completed at " . date('Y-m-d H:i:s') . "\n";
