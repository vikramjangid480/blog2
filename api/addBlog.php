<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_errors.log');

require_once 'config.php';
require_once 'auth.php';

// Check admin authentication for all admin operations
requireAdminAuth();

$database = new DatabaseConfig();
$db = $database->getConnection();

if (!$db) {
    sendResponse(['error' => 'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        addBlog($db);
        break;
    
    case 'PUT':
        updateBlog($db);
        break;
    
    case 'DELETE':
        // Check both query parameter and request body for ID
        $id = null;
        
        // Priority 1: Check query parameter (?id=7)
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
        }
        // Priority 2: Check request body
        else {
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['id'])) {
                $id = $input['id'];
            }
        }
        
        // Validate ID exists
        if (!$id || empty($id)) {
            sendResponse(['error' => 'Blog ID required for deletion'], 400);
        } else {
            deleteBlog($db, $id);
        }
        break;
    
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function addBlog($db) {
    // Log the incoming request data for debugging
    error_log('POST data: ' . print_r($_POST, true));
    error_log('FILES data: ' . print_r($_FILES, true));
    
    try {
        // Get form data
        $title = isset($_POST['title']) ? sanitizeInput($_POST['title']) : null;
        $content = isset($_POST['content']) ? $_POST['content'] : null;
        $excerpt = isset($_POST['excerpt']) ? sanitizeInput($_POST['excerpt']) : null;
        $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $tags = isset($_POST['tags']) ? sanitizeInput($_POST['tags']) : '';
        $meta_title = isset($_POST['meta_title']) ? sanitizeInput($_POST['meta_title']) : $title;
        $meta_description = isset($_POST['meta_description']) ? sanitizeInput($_POST['meta_description']) : $excerpt;
        $is_featured = isset($_POST['is_featured']) ? (bool)$_POST['is_featured'] : false;
        $status = isset($_POST['status']) ? sanitizeInput($_POST['status']) : 'draft';
        
        // Enhanced validation with detailed error messages
        $errors = [];
        if (!$title || trim($title) === '') {
            $errors[] = 'Title is required and cannot be empty';
        }
        if (!$content || trim($content) === '') {
            $errors[] = 'Content is required and cannot be empty';
        }
        if (!$category_id || !is_numeric($category_id)) {
            $errors[] = 'Valid category is required';
        }
        
        if (!empty($errors)) {
            error_log('Validation errors: ' . implode(', ', $errors));
            sendResponse(['error' => 'Validation failed', 'details' => $errors], 422);
        }
        
        // Generate slug
        $slug = generateSlug($title);
        
        // Check if slug already exists
        $check_slug = "SELECT id FROM blogs WHERE slug = :slug";
        $check_stmt = $db->prepare($check_slug);
        $check_stmt->bindParam(':slug', $slug);
        $check_stmt->execute();
        
        if ($check_stmt->fetch()) {
            $slug = $slug . '-' . time();
        }
        
        // Handle file uploads - dual featured images
        $featured_image = null;
        $featured_image_2 = null;
        
        // Handle first featured image
        if (isset($_FILES['featured_image'])) {
            $featured_image = uploadFile($_FILES['featured_image']);
            if (!$featured_image && $_FILES['featured_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                sendResponse(['error' => 'Failed to upload featured image'], 400);
            }
        }
        
        // Handle second featured image
        if (isset($_FILES['featured_image_2'])) {
            $featured_image_2 = uploadFile($_FILES['featured_image_2']);
            if (!$featured_image_2 && $_FILES['featured_image_2']['error'] !== UPLOAD_ERR_NO_FILE) {
                sendResponse(['error' => 'Failed to upload second featured image'], 400);
            }
        }
        
        // Filter out external URLs (only allow uploaded images or null)
        if ($featured_image && (strpos($featured_image, 'http') === 0 && strpos($featured_image, '/uploads/') === false)) {
            $featured_image = null;
        }
        if ($featured_image_2 && (strpos($featured_image_2, 'http') === 0 && strpos($featured_image_2, '/uploads/') === false)) {
            $featured_image_2 = null;
        }
        
        // Generate excerpt if not provided
        if (!$excerpt && $content) {
            $excerpt = substr(strip_tags($content), 0, 200) . '...';
        }
        
        // Insert blog with dual featured images
        $query = "INSERT INTO blogs (title, slug, content, excerpt, featured_image, featured_image_2, category_id, tags, meta_title, meta_description, is_featured, status) 
                  VALUES (:title, :slug, :content, :excerpt, :featured_image, :featured_image_2, :category_id, :tags, :meta_title, :meta_description, :is_featured, :status)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':slug', $slug);
        $stmt->bindParam(':content', $content);
        $stmt->bindParam(':excerpt', $excerpt);
        $stmt->bindParam(':featured_image', $featured_image);
        $stmt->bindParam(':featured_image_2', $featured_image_2);
        $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        $stmt->bindParam(':tags', $tags);
        $stmt->bindParam(':meta_title', $meta_title);
        $stmt->bindParam(':meta_description', $meta_description);
        $stmt->bindParam(':is_featured', $is_featured, PDO::PARAM_BOOL);
        $stmt->bindParam(':status', $status);
        
        $stmt->execute();
        $blog_id = $db->lastInsertId();
        
        // Handle related books
        if (isset($_POST['related_books']) && $_POST['related_books']) {
            $related_books = json_decode($_POST['related_books'], true);
            if ($related_books && is_array($related_books)) {
                $books_added = addRelatedBooks($db, $blog_id, $related_books);
                error_log("Blog $blog_id: Added $books_added related books successfully");
            } else {
                error_log("Blog $blog_id: Invalid related_books JSON data: " . json_last_error_msg());
            }
        }
        
        $response_data = ['message' => 'Blog created successfully', 'blog_id' => $blog_id, 'slug' => $slug];
        
        // Add related books info to response if applicable
        if (isset($books_added)) {
            $response_data['related_books_added'] = $books_added;
            if ($books_added > 0) {
                $response_data['message'] = "Blog created successfully with $books_added related books";
            }
        }
        
        sendResponse($response_data, 201);
        
    } catch (PDOException $e) {
        error_log('PDO Exception in addBlog: ' . $e->getMessage());
        error_log('PDO Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'Database error occurred', 'details' => $e->getMessage()], 500);
    } catch (Exception $e) {
        error_log('General Exception in addBlog: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'An unexpected error occurred', 'details' => $e->getMessage()], 500);
    }
}

function updateBlog($db) {
    // PHP doesn't populate $_POST and $_FILES for PUT requests with multipart/form-data
    // We need to manually parse the input stream
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isPutWithFormData = $_SERVER['REQUEST_METHOD'] === 'PUT' && 
                         (strpos($contentType, 'multipart/form-data') !== false);
    
    if ($isPutWithFormData) {
        // Parse multipart/form-data for PUT requests
        $_PUT = array();
        $_FILES_PUT = array();
        
        $raw_data = file_get_contents('php://input');
        $boundary = substr($raw_data, 0, strpos($raw_data, "\r\n"));
        
        if (empty($boundary)) {
            error_log('Failed to parse boundary from PUT request');
            sendResponse(['error' => 'Invalid multipart/form-data format'], 400);
        }
        
        $parts = array_slice(explode($boundary, $raw_data), 1);
        
        foreach ($parts as $part) {
            if ($part == "--\r\n") break;
            if (empty($part)) continue;
            
            $sections = explode("\r\n\r\n", $part, 2);
            if (count($sections) < 2) continue;
            
            $headers = $sections[0];
            $content = rtrim($sections[1], "\r\n");
            
            // Parse Content-Disposition header
            if (preg_match('/Content-Disposition:.*?name="([^"]+)"(?:.*?filename="([^"]+)")?/i', $headers, $matches)) {
                $name = $matches[1];
                $filename = $matches[2] ?? null;
                
                if ($filename) {
                    // This is a file upload
                    $temp_filename = tempnam(sys_get_temp_dir(), 'php_upload_');
                    file_put_contents($temp_filename, $content);
                    
                    // Determine file type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $temp_filename);
                    finfo_close($finfo);
                    
                    $_FILES_PUT[$name] = array(
                        'name' => $filename,
                        'type' => $mime_type,
                        'tmp_name' => $temp_filename,
                        'error' => UPLOAD_ERR_OK,
                        'size' => strlen($content)
                    );
                } else {
                    // This is a regular field
                    $_PUT[$name] = $content;
                }
            }
        }
        
        // Use parsed data for PUT requests
        $_POST = $_PUT;
        $_FILES = $_FILES_PUT;
        
        error_log('Parsed PUT FormData - POST: ' . print_r($_POST, true));
        error_log('Parsed PUT FormData - FILES: ' . print_r(array_keys($_FILES), true));
    }
    
    // Log incoming request for debugging
    error_log('PUT Request - POST data: ' . print_r($_POST, true));
    error_log('PUT Request - FILES data: ' . print_r($_FILES, true));
    
    try {
        // Check if this is a form data request (with files) or JSON request
        $isFormData = isset($_POST['id']);
        
        if ($isFormData) {
            // Handle form data with potential file uploads
            $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
            $title = isset($_POST['title']) ? sanitizeInput($_POST['title']) : null;
            $content = isset($_POST['content']) ? $_POST['content'] : null;
            $excerpt = isset($_POST['excerpt']) ? sanitizeInput($_POST['excerpt']) : null;
            $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $tags = isset($_POST['tags']) ? sanitizeInput($_POST['tags']) : '';
            $meta_title = isset($_POST['meta_title']) ? sanitizeInput($_POST['meta_title']) : null;
            $meta_description = isset($_POST['meta_description']) ? sanitizeInput($_POST['meta_description']) : null;
            $is_featured = isset($_POST['is_featured']) ? (bool)$_POST['is_featured'] : false;
            $status = isset($_POST['status']) ? sanitizeInput($_POST['status']) : 'draft';
            
            // Log parsed values for debugging
            error_log("Parsed FormData - ID: $id, Title: $title, Content length: " . strlen($content ?? '') . ", Category: $category_id");
            
            // Handle file uploads for update
            $featured_image = null;
            $featured_image_2 = null;
            $updateImages = false;
            
            // Handle first featured image
            if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
                $featured_image = uploadFile($_FILES['featured_image']);
                if (!$featured_image) {
                    sendResponse(['error' => 'Failed to upload featured image'], 400);
                }
                $updateImages = true;
            }
            
            // Handle second featured image
            if (isset($_FILES['featured_image_2']) && $_FILES['featured_image_2']['error'] === UPLOAD_ERR_OK) {
                $featured_image_2 = uploadFile($_FILES['featured_image_2']);
                if (!$featured_image_2) {
                    sendResponse(['error' => 'Failed to upload second featured image'], 400);
                }
                $updateImages = true;
            }
            
            // Filter out external URLs
            if ($featured_image && (strpos($featured_image, 'http') === 0 && strpos($featured_image, '/uploads/') === false)) {
                $featured_image = null;
            }
            if ($featured_image_2 && (strpos($featured_image_2, 'http') === 0 && strpos($featured_image_2, '/uploads/') === false)) {
                $featured_image_2 = null;
            }
            
        } else {
            // Handle JSON data for regular updates
            $input = json_decode(file_get_contents('php://input'), true);
            
            $id = isset($input['id']) ? (int)$input['id'] : null;
            $title = isset($input['title']) ? sanitizeInput($input['title']) : null;
            $content = isset($input['content']) ? $input['content'] : null;
            $excerpt = isset($input['excerpt']) ? sanitizeInput($input['excerpt']) : null;
            $category_id = isset($input['category_id']) ? (int)$input['category_id'] : null;
            $tags = isset($input['tags']) ? sanitizeInput($input['tags']) : '';
            $meta_title = isset($input['meta_title']) ? sanitizeInput($input['meta_title']) : null;
            $meta_description = isset($input['meta_description']) ? sanitizeInput($input['meta_description']) : null;
            $is_featured = isset($input['is_featured']) ? (bool)$input['is_featured'] : false;
            $status = isset($input['status']) ? sanitizeInput($input['status']) : 'draft';
            $updateImages = false;
        }
        
        // Enhanced validation with detailed error messages
        $errors = [];
        if (!$id) {
            $errors[] = 'Blog ID is required for update';
        }
        if (!$title || trim($title) === '') {
            $errors[] = 'Title is required and cannot be empty';
        }
        if (!$content || trim($content) === '') {
            $errors[] = 'Content is required and cannot be empty';
        }
        if (!$category_id || !is_numeric($category_id)) {
            $errors[] = 'Valid category is required';
        }
        
        if (!empty($errors)) {
            error_log('Update validation errors: ' . implode(', ', $errors));
            sendResponse(['error' => 'Validation failed', 'details' => $errors], 422);
        }
        
        // Update blog with or without images
        if ($updateImages) {
            $query = "UPDATE blogs SET title = :title, content = :content, excerpt = :excerpt, category_id = :category_id, 
                      tags = :tags, meta_title = :meta_title, meta_description = :meta_description, is_featured = :is_featured, 
                      status = :status, updated_at = NOW()";
            
            if ($featured_image !== null) {
                $query .= ", featured_image = :featured_image";
            }
            if ($featured_image_2 !== null) {
                $query .= ", featured_image_2 = :featured_image_2";
            }
            
            $query .= " WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':excerpt', $excerpt);
            $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
            $stmt->bindParam(':tags', $tags);
            $stmt->bindParam(':meta_title', $meta_title);
            $stmt->bindParam(':meta_description', $meta_description);
            $stmt->bindParam(':is_featured', $is_featured, PDO::PARAM_BOOL);
            $stmt->bindParam(':status', $status);
            
            if ($featured_image !== null) {
                $stmt->bindParam(':featured_image', $featured_image);
            }
            if ($featured_image_2 !== null) {
                $stmt->bindParam(':featured_image_2', $featured_image_2);
            }
        } else {
            // Regular update without image changes
            $query = "UPDATE blogs SET title = :title, content = :content, excerpt = :excerpt, category_id = :category_id, 
                      tags = :tags, meta_title = :meta_title, meta_description = :meta_description, is_featured = :is_featured, 
                      status = :status, updated_at = NOW() WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':excerpt', $excerpt);
            $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
            $stmt->bindParam(':tags', $tags);
            $stmt->bindParam(':meta_title', $meta_title);
            $stmt->bindParam(':meta_description', $meta_description);
            $stmt->bindParam(':is_featured', $is_featured, PDO::PARAM_BOOL);
            $stmt->bindParam(':status', $status);
        }
        
        $stmt->execute();
        
        // Handle related books for form data updates
        if ($isFormData && isset($_POST['related_books']) && $_POST['related_books']) {
            $related_books = json_decode($_POST['related_books'], true);
            if ($related_books && is_array($related_books)) {
                $books_added = addRelatedBooks($db, $id, $related_books);
                error_log("Blog $id: Updated with $books_added related books successfully");
            } else {
                error_log("Blog $id: Invalid related_books JSON data in update: " . json_last_error_msg());
            }
        }
        
        // Fetch updated blog data with related books
        $fetch_query = "SELECT * FROM blogs WHERE id = :id";
        $fetch_stmt = $db->prepare($fetch_query);
        $fetch_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $fetch_stmt->execute();
        $updated_blog = $fetch_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch related books
        $books_query = "SELECT * FROM related_books WHERE blog_id = :blog_id ORDER BY id ASC";
        $books_stmt = $db->prepare($books_query);
        $books_stmt->bindParam(':blog_id', $id, PDO::PARAM_INT);
        $books_stmt->execute();
        $related_books_data = $books_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format related books with full image URLs
        $formatted_books = array_map(function($book) {
            return [
                'id' => (int)$book['id'],
                'blog_id' => (int)$book['blog_id'],
                'title' => $book['title'],
                'author' => $book['author'] ?? null,
                'purchase_link' => $book['purchase_link'],
                'cover_image' => $book['cover_image'] ? getFullImageUrl($book['cover_image']) : null,
                'description' => $book['description'],
                'price' => $book['price'],
                'created_at' => $book['created_at']
            ];
        }, $related_books_data);
        
        $updated_blog['related_books'] = $formatted_books;
        
        $response_data = [
            'message' => 'Blog updated successfully',
            'blog' => $updated_blog
        ];
        
        // Add related books info to response if applicable
        if (isset($books_added)) {
            $response_data['related_books_updated'] = $books_added;
            if ($books_added > 0) {
                $response_data['message'] = "Blog updated successfully with $books_added related books";
            }
        }
        
        sendResponse($response_data);
        
    } catch (PDOException $e) {
        error_log('PDO Exception in updateBlog: ' . $e->getMessage());
        error_log('PDO Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'Database error occurred while updating', 'details' => $e->getMessage()], 500);
    } catch (Exception $e) {
        error_log('General Exception in updateBlog: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'An unexpected error occurred while updating', 'details' => $e->getMessage()], 500);
    }
}

function deleteBlog($db, $id) {
    try {
        $query = "DELETE FROM blogs WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            sendResponse(['message' => 'Blog deleted successfully']);
        } else {
            sendResponse(['error' => 'Blog not found'], 404);
        }
        
    } catch (PDOException $e) {
        error_log('PDO Exception in deleteBlog: ' . $e->getMessage());
        error_log('PDO Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'Database error occurred while deleting', 'details' => $e->getMessage()], 500);
    } catch (Exception $e) {
        error_log('General Exception in deleteBlog: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        sendResponse(['error' => 'An unexpected error occurred while deleting', 'details' => $e->getMessage()], 500);
    }
}

function addRelatedBooks($db, $blog_id, $books) {
    $books_added = 0;
    
    try {
        // Delete existing related books
        $delete_query = "DELETE FROM related_books WHERE blog_id = :blog_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':blog_id', $blog_id, PDO::PARAM_INT);
        $delete_stmt->execute();
        
        // Add new related books with cover_image support
        $insert_query = "INSERT INTO related_books (blog_id, title, author, purchase_link, cover_image, description, price) 
                         VALUES (:blog_id, :title, :author, :purchase_link, :cover_image, :description, :price)";
        $insert_stmt = $db->prepare($insert_query);
        
        foreach ($books as $index => $book) {
            if (isset($book['title']) && isset($book['purchase_link']) && trim($book['title']) !== '' && trim($book['purchase_link']) !== '') {
                // Handle cover image upload for each book
                $cover_image = null;
                $cover_image_key = 'book_cover_' . $index;
                
                // Priority 1: Check if a new file was uploaded
                if (isset($_FILES[$cover_image_key]) && $_FILES[$cover_image_key]['error'] === UPLOAD_ERR_OK) {
                    $cover_image = uploadFileToSubfolder($_FILES[$cover_image_key], 'book_covers');
                    error_log("Book $index: New cover image uploaded - $cover_image");
                }
                
                // Priority 2: Use existing cover_image_url if no new file uploaded
                if (!$cover_image && isset($book['cover_image_url']) && !empty($book['cover_image_url'])) {
                    $cover_image = cleanImagePath($book['cover_image_url']);
                    error_log("Book $index: Preserving existing cover image - $cover_image");
                }
                
                // Priority 3: Use provided cover_image if available
                if (!$cover_image && isset($book['cover_image']) && !empty($book['cover_image'])) {
                    $cover_image = cleanImagePath($book['cover_image']);
                    error_log("Book $index: Using provided cover image - $cover_image");
                }
                
                // Prepare variables for binding
                $title = trim($book['title']);
                $author = isset($book['author']) ? trim($book['author']) : '';
                $purchase_link = trim($book['purchase_link']);
                $description = isset($book['description']) ? trim($book['description']) : '';
                $price = isset($book['price']) ? trim($book['price']) : '';
                
                $insert_stmt->bindParam(':blog_id', $blog_id, PDO::PARAM_INT);
                $insert_stmt->bindParam(':title', $title);
                $insert_stmt->bindParam(':author', $author);
                $insert_stmt->bindParam(':purchase_link', $purchase_link);
                $insert_stmt->bindParam(':cover_image', $cover_image);
                $insert_stmt->bindParam(':description', $description);
                $insert_stmt->bindParam(':price', $price);
                $insert_stmt->execute();
                
                $books_added++;
            }
        }
        
    } catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log('Failed to add related books: ' . $e->getMessage());
    }
    
    return $books_added;
}

// Helper function for file uploads with subfolder support
function uploadFileToSubfolder($file, $subfolder = '') {
    $upload_dir = '../uploads/';
    
    // Create subfolder if specified
    if ($subfolder) {
        $upload_dir .= $subfolder . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $file['type'];
    
    if (!in_array($file_type, $allowed_types)) {
        return false;
    }
    
    // Validate file size (5MB max)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        return false;
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $file_path = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // IMPORTANT: Return relative path ONLY (no domain) for database storage
        // Format: uploads/subfolder/filename.ext (without leading slash)
        return 'uploads/' . ($subfolder ? $subfolder . '/' : '') . $filename;
    }
    
    return false;
}
?>