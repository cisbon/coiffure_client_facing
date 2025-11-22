# AI Consultation Backend Integration Guide

This document describes how to update the backend API at `https://clouedo.com/coiffure/api/ai-consultation.php` to integrate with OpenRouter API for hairstyle visualization.

## Requirements

1. Add to `.env` file:
```env
OPENROUTER_API_KEY=your_openrouter_api_key_here
AI_MODEL=anthropic/claude-3.5-sonnet # or any vision-capable model
```

2. Update `ai-consultation.php` to use OpenRouter API

## Backend Implementation (ai-consultation.php)

```php
<?php
require_once 'config.php';
require_once 'auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verify authentication
$auth = verifySession();
if (!$auth['success']) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit();
}

try {
    // Get request data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['image']) || !isset($data['style_prompt'])) {
        throw new Exception('Missing required fields: image and style_prompt');
    }

    $imageData = $data['image'];
    $stylePrompt = $data['style_prompt'];
    $userId = $auth['user_id'];

    // Extract base64 image data
    // Image comes as data:image/jpeg;base64,/9j/4AAQSkZJRg...
    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageData, $matches)) {
        $imageType = $matches[1];
        $imageBase64 = $matches[2];
    } else {
        throw new Exception('Invalid image format');
    }

    // Prepare OpenRouter API request
    $openrouterApiKey = getenv('OPENROUTER_API_KEY');
    $aiModel = getenv('AI_MODEL') ?: 'anthropic/claude-3.5-sonnet';

    if (!$openrouterApiKey) {
        throw new Exception('OPENROUTER_API_KEY not configured');
    }

    $startTime = microtime(true);

    // Construct the prompt for hairstyle transformation
    $systemPrompt = "You are an expert hairstylist AI assistant. Analyze the person's photo and their desired hairstyle request. Provide detailed professional recommendations for achieving the requested look, including:
1. Feasibility assessment based on their current hair
2. Specific steps and techniques needed
3. Products and tools required
4. Maintenance advice
5. Alternative suggestions if the requested style may not suit their face shape or hair type

Be encouraging but honest about what's achievable.";

    $userPrompt = "The customer wants: {$stylePrompt}

Please analyze their photo and provide professional hairstyling recommendations.";

    // OpenRouter API request
    $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

    $requestBody = [
        'model' => $aiModel,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $userPrompt
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $imageData // Send the full data URI
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens' => 1000,
        'temperature' => 0.7
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openrouterApiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://salonlyft.com', // Replace with your domain
            'X-Title: SalonLyft AI Consultation'
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $endTime = microtime(true);
    $processingTime = round(($endTime - $startTime) * 1000); // ms

    if ($httpCode !== 200) {
        $errorResponse = json_decode($response, true);
        throw new Exception('OpenRouter API error: ' . ($errorResponse['error']['message'] ?? 'Unknown error'));
    }

    $apiResponse = json_decode($response, true);

    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response from AI service');
    }

    $aiRecommendation = $apiResponse['choices'][0]['message']['content'];

    // Store consultation in database (optional)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_consultations
            (user_id, style_prompt, ai_response, model_used, processing_time_ms, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $stylePrompt,
            $aiRecommendation,
            $aiModel,
            $processingTime
        ]);
    } catch (PDOException $e) {
        // Log but don't fail if database insert fails
        error_log('Failed to store AI consultation: ' . $e->getMessage());
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'ai_response' => $aiRecommendation,
        'model_used' => $aiModel,
        'processing_time_ms' => $processingTime
    ]);

} catch (Exception $e) {
    error_log('AI Consultation Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
```

## Database Migration (Optional)

If you want to store consultation history, add this table:

```sql
CREATE TABLE IF NOT EXISTS ai_consultations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    style_prompt TEXT NOT NULL,
    ai_response TEXT NOT NULL,
    model_used VARCHAR(100),
    processing_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES coiffure_users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Testing

1. Ensure `.env` has `OPENROUTER_API_KEY` and `AI_MODEL` set
2. Test with a sample image and style prompt
3. Verify the response contains hairstyle recommendations
4. Check processing time is reasonable (should be 3-10 seconds typically)

## Supported Models

OpenRouter supports many vision-capable models:
- `anthropic/claude-3.5-sonnet` (recommended for quality)
- `anthropic/claude-3-opus` (highest quality, slower)
- `anthropic/claude-3-haiku` (faster, cheaper)
- `openai/gpt-4-vision-preview`
- `google/gemini-pro-vision`

Choose based on your budget and quality requirements.

## Security Notes

1. Never expose the `OPENROUTER_API_KEY` in frontend code
2. Implement rate limiting to prevent API abuse
3. Validate image size before sending to API (max 5MB recommended)
4. Sanitize user prompts to prevent injection attacks
