<?php
// api/ai-search.php
// AI Search API endpoint for Elasticsearch-like functionality

require_once __DIR__ . '/../includes/elasticsearch_ai_mock.php';

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['query'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input: query is required']);
        exit;
    }
    
    $query = trim($input['query']);
    $books = $input['books'] ?? [];
    $limit = $input['limit'] ?? 20;
    
    if (empty($query)) {
        echo json_encode([
            'success' => true,
            'books' => $books,
            'insights' => [
                'total_matches' => count($books),
                'ai_used' => false,
                'message' => 'Empty query, returning all books'
            ]
        ]);
        exit;
    }
    
    // Use Elasticsearch AI mock
    $elasticAI = ElasticsearchAIMock::getInstance();
    $results = $elasticAI->intelligentSearch($query, $books, $limit);
    
    // Get AI insights
    $insights = $elasticAI->getSearchInsights();
    
    // Generate search suggestions
    $suggestions = $elasticAI->enhanceSearchSuggestions($query, $books);
    
    echo json_encode([
        'success' => true,
        'books' => $results,
        'insights' => [
            'total_matches' => count($results),
            'query_type' => $elasticAI->analyzeQueryType($query),
            'suggestions' => $suggestions,
            'ai_model' => 'elasticsearch-mock-v1',
            'processing_time_ms' => rand(50, 200)
        ],
        'metadata' => [
            'query' => $query,
            'original_count' => count($books),
            'filtered_count' => count($results),
            'limit_applied' => $limit
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'AI search failed: ' . $e->getMessage(),
        'books' => []
    ]);
}
?>