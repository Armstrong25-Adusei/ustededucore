<?php
// Simple migration runner script

// Read the SQL file
$sqlFile = __DIR__ . '/add_dm_tables.sql';
$sql = file_get_contents($sqlFile);

// Connect to database
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'educore';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Execute the SQL
    // Split by ; to handle multiple statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && !str_starts_with(trim($s), '--')
    );
    
    $count = 0;
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            $pdo->exec($statement . ';');
            $count++;
        }
    }
    
    echo "✓ Migration completed successfully!\n";
    echo "✓ Executed $count SQL statements\n";
    echo "✓ All DM tables created:\n";
    echo "  - direct_message_threads\n";
    echo "  - direct_messages\n";
    echo "  - dm_reads\n";
    echo "  - dm_reactions\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
