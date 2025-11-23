<?php
/**
 * Database Migration Script
 * Purpose: Remove domain prefixes from all image path fields
 * 
 * This script cleans up existing database records that have full URLs
 * and converts them to relative paths for portability.
 * 
 * BEFORE: https://boganto.com/uploads/book_covers/691f3f892fc1d_1763655561.webp
 * AFTER:  uploads/book_covers/691f3f892fc1d_1763655561.webp
 * 
 * Usage: php migrate_image_paths.php
 */

require_once 'config.php';

echo "====================================\n";
echo "Image Path Migration Script\n";
echo "====================================\n\n";

$database = new DatabaseConfig();
$db = $database->getConnection();

if (!$db) {
    die("ERROR: Database connection failed\n");
}

/**
 * Clean image path - remove domain prefix
 */
function cleanPath($path) {
    if (!$path || empty($path)) {
        return null;
    }
    
    // Remove domain prefix (http://..., https://...)
    $cleaned = preg_replace('/^https?:\/\/[^\/]+\//', '', $path);
    
    // Remove leading slash if present
    $cleaned = ltrim($cleaned, '/');
    
    // Ensure it starts with 'uploads/' if it contains uploads
    if (strpos($cleaned, 'uploads/') !== 0 && strpos($cleaned, 'uploads/') !== false) {
        $cleaned = substr($cleaned, strpos($cleaned, 'uploads/'));
    }
    
    return $cleaned ?: null;
}

// Statistics
$stats = [
    'blogs_featured_image' => 0,
    'blogs_featured_image_2' => 0,
    'related_books_cover_image' => 0,
    'banner_images_image_url' => 0,
    'total_updated' => 0
];

echo "Starting migration...\n\n";

// ==============================================
// 1. Migrate blogs.featured_image
// ==============================================
echo "1. Migrating blogs.featured_image...\n";
try {
    $query = "SELECT id, featured_image FROM blogs WHERE featured_image IS NOT NULL AND featured_image != ''";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $update_stmt = $db->prepare("UPDATE blogs SET featured_image = :cleaned WHERE id = :id");
    
    foreach ($blogs as $blog) {
        $original = $blog['featured_image'];
        $cleaned = cleanPath($original);
        
        if ($cleaned && $cleaned !== $original) {
            $update_stmt->bindParam(':cleaned', $cleaned);
            $update_stmt->bindParam(':id', $blog['id'], PDO::PARAM_INT);
            $update_stmt->execute();
            $stats['blogs_featured_image']++;
            echo "  - Blog ID {$blog['id']}: {$original} → {$cleaned}\n";
        }
    }
    echo "  ✓ Updated {$stats['blogs_featured_image']} records\n\n";
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ==============================================
// 2. Migrate blogs.featured_image_2
// ==============================================
echo "2. Migrating blogs.featured_image_2...\n";
try {
    $query = "SELECT id, featured_image_2 FROM blogs WHERE featured_image_2 IS NOT NULL AND featured_image_2 != ''";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $update_stmt = $db->prepare("UPDATE blogs SET featured_image_2 = :cleaned WHERE id = :id");
    
    foreach ($blogs as $blog) {
        $original = $blog['featured_image_2'];
        $cleaned = cleanPath($original);
        
        if ($cleaned && $cleaned !== $original) {
            $update_stmt->bindParam(':cleaned', $cleaned);
            $update_stmt->bindParam(':id', $blog['id'], PDO::PARAM_INT);
            $update_stmt->execute();
            $stats['blogs_featured_image_2']++;
            echo "  - Blog ID {$blog['id']}: {$original} → {$cleaned}\n";
        }
    }
    echo "  ✓ Updated {$stats['blogs_featured_image_2']} records\n\n";
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ==============================================
// 3. Migrate related_books.cover_image
// ==============================================
echo "3. Migrating related_books.cover_image...\n";
try {
    $query = "SELECT id, cover_image FROM related_books WHERE cover_image IS NOT NULL AND cover_image != ''";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $update_stmt = $db->prepare("UPDATE related_books SET cover_image = :cleaned WHERE id = :id");
    
    foreach ($books as $book) {
        $original = $book['cover_image'];
        $cleaned = cleanPath($original);
        
        if ($cleaned && $cleaned !== $original) {
            $update_stmt->bindParam(':cleaned', $cleaned);
            $update_stmt->bindParam(':id', $book['id'], PDO::PARAM_INT);
            $update_stmt->execute();
            $stats['related_books_cover_image']++;
            echo "  - Book ID {$book['id']}: {$original} → {$cleaned}\n";
        }
    }
    echo "  ✓ Updated {$stats['related_books_cover_image']} records\n\n";
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ==============================================
// 4. Migrate banner_images.image_url
// ==============================================
echo "4. Migrating banner_images.image_url...\n";
try {
    $query = "SELECT id, image_url FROM banner_images WHERE image_url IS NOT NULL AND image_url != ''";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $update_stmt = $db->prepare("UPDATE banner_images SET image_url = :cleaned WHERE id = :id");
    
    foreach ($banners as $banner) {
        $original = $banner['image_url'];
        $cleaned = cleanPath($original);
        
        if ($cleaned && $cleaned !== $original) {
            $update_stmt->bindParam(':cleaned', $cleaned);
            $update_stmt->bindParam(':id', $banner['id'], PDO::PARAM_INT);
            $update_stmt->execute();
            $stats['banner_images_image_url']++;
            echo "  - Banner ID {$banner['id']}: {$original} → {$cleaned}\n";
        }
    }
    echo "  ✓ Updated {$stats['banner_images_image_url']} records\n\n";
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ==============================================
// Summary
// ==============================================
$stats['total_updated'] = $stats['blogs_featured_image'] + 
                          $stats['blogs_featured_image_2'] + 
                          $stats['related_books_cover_image'] + 
                          $stats['banner_images_image_url'];

echo "====================================\n";
echo "Migration Complete!\n";
echo "====================================\n\n";
echo "Summary:\n";
echo "  - blogs.featured_image:         {$stats['blogs_featured_image']} records\n";
echo "  - blogs.featured_image_2:       {$stats['blogs_featured_image_2']} records\n";
echo "  - related_books.cover_image:    {$stats['related_books_cover_image']} records\n";
echo "  - banner_images.image_url:      {$stats['banner_images_image_url']} records\n";
echo "  ----------------------------------------\n";
echo "  TOTAL UPDATED:                  {$stats['total_updated']} records\n\n";

if ($stats['total_updated'] > 0) {
    echo "✓ All image paths have been successfully migrated to relative format!\n";
    echo "  Database is now portable across environments.\n\n";
} else {
    echo "ℹ No records needed migration. All paths are already in relative format.\n\n";
}

echo "====================================\n";
?>
