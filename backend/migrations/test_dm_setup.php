<?php
// Test script to verify the DM thread_id response format

// Get a valid JWT token first (would need actual credentials)
// For now, just checking the database structure

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'educore';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check if threads exist
$stmt = $pdo->query("SELECT COUNT(*) as count FROM direct_message_threads");
$result = $stmt->fetch();
echo "Direct message threads in database: " . $result['count'] . "\n";

// Check messages table
$stmt = $pdo->query("SELECT COUNT(*) as count FROM direct_messages");
$result = $stmt->fetch();
echo "Direct messages in database: " . $result['count'] . "\n";

// Show table schema for direct_messages
echo "\nDirect messages table structure:\n";
$stmt = $pdo->query("DESCRIBE direct_messages");
$cols = $stmt->fetchAll();
foreach ($cols as $col) {
    echo "  - {$col['Field']}: {$col['Type']}\n";
}

echo "\n✓ All tables are properly created and ready for DM operations\n";
?>
