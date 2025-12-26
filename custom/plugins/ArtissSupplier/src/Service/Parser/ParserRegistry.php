<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use Shopware\Core\Content\Media\MediaEntity;

/**
 * Registry for managing and selecting appropriate price list parsers
 * Implements Chain of Responsibility pattern
 */
class ParserRegistry
{
    /**
     * @var PriceParserInterface[]
     */
    private array $parsers = [];

    public function __construct(iterable $parsers)
    {
        foreach ($parsers as $parser) {
            $this->addParser($parser);
        }
    }

    /**
     * Add parser to registry
     */
    public function addParser(PriceParserInterface $parser): void
    {
        $this->parsers[] = $parser;
    }

    /**
     * Get all registered parsers
     *
     * @return PriceParserInterface[]
     */
    public function getAllParsers(): array
    {
        return $this->parsers;
    }

    /**
     * Find appropriate parser for given media file
     *
     * @param MediaEntity $media Media file to parse
     * @return PriceParserInterface|null Parser instance or null if no parser supports this file
     */
    public function getParser(MediaEntity $media): ?PriceParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($media)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Get parser by name
     *
     * @param string $name Parser name
     * @return PriceParserInterface|null
     */
    public function getParserByName(string $name): ?PriceParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->getName() === $name) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Check if any parser supports the given media file
     */
    public function supportsMedia(MediaEntity $media): bool
    {
        return $this->getParser($media) !== null;
    }

    /**
     * Parse media file using appropriate parser
     *
     * @param MediaEntity $media Media file to parse
     * @param array $config Parser configuration
     * @return array Normalized price data
     * @throws \RuntimeException If no parser supports this file type
     */
    public function parse(MediaEntity $media, array $config): array
    {
        $parser = $this->getParser($media);

        if ($parser === null) {
            $extension = $media->getFileExtension() ?? 'unknown';
            throw new \RuntimeException(
                "No parser available for file type: {$extension}. " .
                "Supported types: " . $this->getSupportedExtensionsString()
            );
        }

        return $parser->parse($media, $config);
    }

    /**
     * Get preview of media file using appropriate parser
     *
     * @param MediaEntity $media Media file to preview
     * @param int $previewRows Number of rows to preview
     * @return array Preview data
     * @throws \RuntimeException If no parser supports this file type
     */
    public function preview(MediaEntity $media, int $previewRows = 5): array
    {
        $parser = $this->getParser($media);

        if ($parser === null) {
            $extension = $media->getFileExtension() ?? 'unknown';
            throw new \RuntimeException(
                "No parser available for file type: {$extension}. " .
                "Supported types: " . $this->getSupportedExtensionsString()
            );
        }

        return $parser->preview($media, $previewRows);
    }

    /**
     * Get list of all supported file extensions
     *
     * @return array
     */
    public function getSupportedExtensions(): array
    {
        $extensions = [];

        foreach ($this->parsers as $parser) {
            $extensions = array_merge($extensions, $parser->getSupportedExtensions());
        }

        return array_unique($extensions);
    }

    /**
     * Get supported extensions as human-readable string
     */
    public function getSupportedExtensionsString(): string
    {
        $extensions = $this->getSupportedExtensions();
        return implode(', ', array_map(fn($ext) => strtoupper($ext), $extensions));
    }

    /**
     * Get parser information for UI
     *
     * @return array Array of parser info: [['name' => '...', 'extensions' => [...]], ...]
     */
    public function getParserInfo(): array
    {
        $info = [];

        foreach ($this->parsers as $parser) {
            $info[] = [
                'name' => $parser->getName(),
                'extensions' => $parser->getSupportedExtensions(),
            ];
        }

        return $info;
    }
}
