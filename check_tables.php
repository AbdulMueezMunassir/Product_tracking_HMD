<?php
require_once 'config.php';

echo "<h2>Your Database Structure</h2>";

$tables = $pdo->query("SHOW TABLES")->fetchAll();
echo "<h3>Tables in hameedia_tracking:</h3>";
echo "<ul>";
foreach($tables as $table) {
    $tableName = reset($table);
    echo "<li><strong>$tableName</strong>";
    
    $columns = $pdo->query("DESCRIBE `$tableName`")->fetchAll();
    echo "<ul>";
    foreach($columns as $col) {
        echo "<li>{$col['Field']} - {$col['Type']}</li>";
    }
    echo "</ul>";
    echo "</li>";
}
echo "</ul>";
?>