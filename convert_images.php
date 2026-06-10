<?php
require_once 'config.php';

echo "<h2>Converting Base64 Images to Files</h2>";

if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
    echo "<p>Created uploads directory</p>";
}

$stmt = $pdo->query("SELECT id, product_name, image_url FROM order_products WHERE image_url LIKE 'data:image%'");
$products = $stmt->fetchAll();

$converted = 0;
foreach ($products as $product) {
    $base64 = $product['image_url'];
    
    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
        $image_type = $type[1];
        $base64_data = substr($base64, strpos($base64, ',') + 1);
        $image_data = base64_decode($base64_data);
        
        $filename = 'uploads/product_' . $product['id'] . '_' . time() . '.' . $image_type;
        
        if (file_put_contents($filename, $image_data)) {
            // Update database with file path
            $update = $pdo->prepare("UPDATE order_products SET image_url = ? WHERE id = ?");
            $update->execute([$filename, $product['id']]);
            
            echo "<p style='color:green'>✓ Converted: {$product['product_name']} → <a href='$filename' target='_blank'>$filename</a></p>";
            $converted++;
        } else {
            echo "<p style='color:red'>✗ Failed to save: {$product['product_name']}</p>";
        }
    }
}

echo "<hr>";
echo "<h3>Conversion Complete!</h3>";
echo "<p>Converted $converted images from Base64 to files.</p>";
echo "<p><a href='admin_dashboard.php'>Go to Dashboard →</a></p>";
?>