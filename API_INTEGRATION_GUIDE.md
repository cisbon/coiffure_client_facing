# AI Consultation Backend Integration Guide

This document describes how to update the backend API at `https://clouedo.com/coiffure/api/ai-consultation.php` to integrate with OpenRouter API for hairstyle image generation.

## Requirements

1. Add to `.env` file:
```env
OPENROUTER_API_KEY=your_openrouter_api_key_here
AI_MODEL=google/gemini-3-pro-image-preview
# Alternative models:
# black-forest-labs/flux-1.1-pro (good for image generation)
# stability-ai/stable-diffusion-xl (budget option)
# openai/dall-e-3 (high quality but no img2img)
```

2. Update `ai-consultation.php` to use OpenRouter API with image generation

## Backend Implementation (ai-consultation.php)

This implementation uses a two-step approach:
1. Analyze the photo and hairstyle request using a vision model
2. Generate a new image with the hairstyle applied using an image generation model

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

// Verify authentication (optional - remove if you want public access)
// $auth = verifySession();
// if (!$auth['success']) {
//     http_response_code(401);
//     echo json_encode(['success' => false, 'error' => 'Unauthorized']);
//     exit();
// }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['image_base64']) || !isset($data['style_prompt'])) {
        throw new Exception('Missing required fields: image_base64 and style_prompt');
    }

    $imageData = $data['image_base64'];
    $stylePrompt = $data['style_prompt'];
    // $userId = $auth['user_id'] ?? null;

    $openrouterApiKey = getenv('OPENROUTER_API_KEY');
    $aiModel = getenv('AI_MODEL') ?: 'google/gemini-pro-1.5';

    if (!$openrouterApiKey) {
        throw new Exception('OPENROUTER_API_KEY not configured');
    }

    $startTime = microtime(true);

    // STEP 1: Analyze the photo with vision model to understand face features
    $analysisPrompt = "Analyze this person's face and hair. Describe their:
1. Face shape
2. Current hair length, texture, and color
3. Skin tone
4. Facial features relevant to hairstyling
Be concise and focus on details needed for hairstyle recommendations.";

    $analysisRequest = [
        'model' => 'anthropic/claude-3-5-sonnet', // Use vision model for analysis
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $analysisPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageData]]
                ]
            ]
        ],
        'max_tokens' => 500
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openrouterApiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://clouedo.com',
            'X-Title: SalonLyft AI Consultation'
        ],
        CURLOPT_POSTFIELDS => json_encode($analysisRequest)
    ]);

    $analysisResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Analysis failed: ' . $analysisResponse);
    }

    $analysisResult = json_decode($analysisResponse, true);
    $faceAnalysis = $analysisResult['choices'][0]['message']['content'] ?? '';

    // STEP 2: Generate image with the new hairstyle
    // Create a detailed prompt combining the analysis and user's request
    $generationPrompt = "Professional salon photograph of a person with the following features: {$faceAnalysis}

Transform their hairstyle to: {$stylePrompt}

Style: Professional photography, salon quality, natural lighting, high detail, photorealistic, front-facing portrait.";

    // Use an image generation model
    $imageGenRequest = [
        'model' => $aiModel, // Use the model from .env
        'prompt' => $generationPrompt,
        'n' => 1,
        'size' => '1024x1024',
        'response_format' => 'url' // or 'b64_json' for base64
    ];

    // For models that support img2img, include the reference image
    if (strpos($aiModel, 'flux') !== false || strpos($aiModel, 'stable-diffusion') !== false) {
        $imageGenRequest['image'] = $imageData;
        $imageGenRequest['strength'] = 0.75; // How much to transform (0.0 to 1.0)
    }

    $ch = curl_init('https://openrouter.ai/api/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60, // Image generation can take time
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openrouterApiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://clouedo.com',
            'X-Title: SalonLyft AI Consultation'
        ],
        CURLOPT_POSTFIELDS => json_encode($imageGenRequest)
    ]);

    $imageResponse = curl_exec($ch);
    $imageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $endTime = microtime(true);
    $processingTime = round(($endTime - $startTime) * 1000);

    if ($imageHttpCode !== 200) {
        // If image generation fails, return text analysis only
        echo json_encode([
            'success' => true,
            'ai_response' => "Face Analysis: {$faceAnalysis}\n\nRecommendation: {$stylePrompt} would be a great choice based on your features!",
            'model_used' => $aiModel,
            'processing_time_ms' => $processingTime,
            'note' => 'Image generation unavailable - showing text analysis only'
        ]);
        exit();
    }

    $imageResult = json_decode($imageResponse, true);
    $generatedImageUrl = $imageResult['data'][0]['url'] ?? null;
    $generatedImageBase64 = $imageResult['data'][0]['b64_json'] ?? null;

    // Prepare the image for frontend
    $generatedImage = null;
    if ($generatedImageBase64) {
        $generatedImage = 'data:image/png;base64,' . $generatedImageBase64;
    } elseif ($generatedImageUrl) {
        // Download the image and convert to base64 for consistent delivery
        $imageContent = file_get_contents($generatedImageUrl);
        if ($imageContent) {
            $generatedImage = 'data:image/png;base64,' . base64_encode($imageContent);
        }
    }

    // Store in database (optional)
    // try {
    //     $stmt = $pdo->prepare("
    //         INSERT INTO ai_consultations
    //         (user_id, style_prompt, ai_response, generated_image, model_used, processing_time_ms, created_at)
    //         VALUES (?, ?, ?, ?, ?, ?, NOW())
    //     ");
    //     $stmt->execute([$userId, $stylePrompt, $faceAnalysis, $generatedImage, $aiModel, $processingTime]);
    // } catch (PDOException $e) {
    //     error_log('Failed to store AI consultation: ' . $e->getMessage());
    // }

    echo json_encode([
        'success' => true,
        'generated_image' => $generatedImage,
        'ai_response' => $faceAnalysis,
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
