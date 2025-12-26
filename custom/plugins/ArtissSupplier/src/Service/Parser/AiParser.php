<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use Shopware\Core\Content\Media\MediaEntity;

/**
 * AI-based parser for unstructured data (PDF, images, scanned documents)
 *
 * PLACEHOLDER for future implementation
 *
 * Potential integrations:
 * - Claude API (Anthropic) - send image/PDF, get structured data
 * - OpenAI GPT-4 Vision API
 * - Tesseract OCR (open-source)
 * - AWS Textract
 * - Google Cloud Vision API
 *
 * Implementation approach:
 * 1. Convert PDF/image to base64 or upload to AI service
 * 2. Send with prompt: "Extract price list table with columns: code, name, price"
 * 3. Parse AI response (JSON/structured format)
 * 4. Normalize to standard format
 */
class AiParser extends AbstractPriceParser
{
    public function supports(MediaEntity $media): bool
    {
        // Currently disabled - return false
        // Enable when AI integration is implemented
        return false;

        // Future implementation:
        // $extension = $this->getFileExtension($media);
        // return in_array($extension, $this->getSupportedExtensions(), true);
    }

    public function parse(MediaEntity $media, array $config): array
    {
        throw new \RuntimeException('AI Parser is not yet implemented. Coming soon!');

        /*
         * Future implementation example:
         *
         * $filePath = $this->getMediaFilePath($media);
         * $this->validateFile($filePath);
         *
         * // Convert file to base64 for AI API
         * $base64 = base64_encode(file_get_contents($filePath));
         *
         * // Call AI service (e.g., Claude API)
         * $prompt = "Extract price list data from this document. Return JSON array with fields: code, name, price_1, price_2";
         * $response = $this->callAiService($base64, $prompt);
         *
         * // Parse and normalize response
         * $data = json_decode($response, true);
         *
         * return $this->normalizeAiResponse($data);
         */
    }

    public function preview(MediaEntity $media, int $previewRows = 5): array
    {
        throw new \RuntimeException('AI Parser preview is not yet implemented. Coming soon!');

        /*
         * Future implementation:
         * - Extract first page/portion of document
         * - Get AI to identify column structure
         * - Return suggested mapping
         */
    }

    public function getName(): string
    {
        return 'AI Parser (Coming Soon)';
    }

    public function getSupportedExtensions(): array
    {
        // Will support in the future
        return ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
    }

    /**
     * Placeholder for AI service integration
     */
    private function callAiService(string $fileData, string $prompt): string
    {
        // Example for Claude API:
        /*
        $apiKey = getenv('ANTHROPIC_API_KEY');
        $client = new \GuzzleHttp\Client();

        $response = $client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'image/jpeg',
                                    'data' => $fileData,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true)['content'][0]['text'];
        */

        throw new \RuntimeException('AI service not configured');
    }
}
