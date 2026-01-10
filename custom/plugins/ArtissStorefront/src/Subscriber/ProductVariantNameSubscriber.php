<?php declare(strict_types=1);

namespace ArtissStorefront\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductVariantNameSubscriber implements EventSubscriberInterface
{
    private bool $isUpdating = false;

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $languageRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => ['onProductWritten', -5000],
            ProductEvents::PRODUCT_OPTION_WRITTEN_EVENT => ['onProductOptionWritten', -5000],
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if ($this->isUpdating) {
            return;
        }

        $context = $event->getContext();
        $productIds = [];

        foreach ($event->getPayloads() as $payload) {
            if (isset($payload['id'])) {
                $productIds[] = $payload['id'];
            }
        }

        if (empty($productIds)) {
            return;
        }

        $this->updateVariantsByNameIds($productIds, $context);
    }

    public function onProductOptionWritten(EntityWrittenEvent $event): void
    {
        if ($this->isUpdating) {
            return;
        }

        $context = $event->getContext();
        $productIds = [];

        foreach ($event->getPayloads() as $payload) {
            $productId = $payload['productId'] ?? $payload['product_id'] ?? null;
            if ($productId) {
                $productIds[] = $productId;
            }
        }

        if (empty($productIds)) {
            return;
        }

        $this->updateVariantsByNameIds($productIds, $context);
    }

    private function updateVariantsByNameIds(array $productIds, Context $context): void
    {
        $criteria = new Criteria($productIds);
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('parentId', null)
        ]));
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('translations');

        $products = $this->productRepository->search($criteria, $context);

        if ($products->count() === 0) {
            return;
        }

        $this->isUpdating = true;

        try {
            foreach ($products as $product) {
                $this->updateVariantName($product, $context);
            }
        } finally {
            $this->isUpdating = false;
        }
    }

    private function updateVariantName(ProductEntity $product, Context $context): void
    {
        $parentId = $product->getParentId();
        if ($parentId === null) {
            return;
        }

        $variantName = $this->generateVariantName($product, $parentId, $context);

        if ($variantName === null) {
            return;
        }

        $currentName = $product->getTranslated()['name'] ?? $product->getName();
        if ($currentName === $variantName) {
            return;
        }

        $languages = $this->languageRepository->search(new Criteria(), $context);
        $translations = [];

        foreach ($languages as $language) {
            $translations[] = [
                'languageId' => $language->getId(),
                'name' => $variantName,
            ];
        }

        if (!empty($translations)) {
            $this->productRepository->update([
                [
                    'id' => $product->getId(),
                    'translations' => $translations,
                ]
            ], $context);
        }
    }

    private function generateVariantName(ProductEntity $variant, string $parentId, Context $context): ?string
    {
        $options = $variant->getOptions();
        if ($options === null || $options->count() === 0) {
            return null;
        }

        $parentCriteria = new Criteria([$parentId]);
        $parent = $this->productRepository->search($parentCriteria, $context)->first();

        if ($parent === null) {
            $this->logger->warning('ProductVariantNameSubscriber: Parent product not found', [
                'parent_id' => $parentId,
                'variant_id' => $variant->getId()
            ]);
            return null;
        }

        $parentName = $parent->getTranslated()['name'] ?? $parent->getName();
        if ($parentName === null) {
            return null;
        }

        $optionsByGroup = [];

        foreach ($options as $option) {
            $group = $option->getGroup();
            if ($group === null) {
                continue;
            }

            $groupTranslated = $group->getTranslated();
            $groupName = $groupTranslated['name'] ?? $group->getName() ?? '';
            $groupPosition = (int) ($groupTranslated['position'] ?? $group->getPosition() ?? 0);

            $optionTranslated = $option->getTranslated();
            $optionName = $optionTranslated['name'] ?? $option->getName();
            $optionPosition = (int) ($optionTranslated['position'] ?? $option->getPosition() ?? 0);

            if ($optionName === null || $optionName === '') {
                continue;
            }

            if (!isset($optionsByGroup[$groupName])) {
                $optionsByGroup[$groupName] = [
                    'position' => $groupPosition,
                    'options' => []
                ];
            }

            $optionsByGroup[$groupName]['options'][] = [
                'name' => $optionName,
                'position' => $optionPosition
            ];
        }

        if (empty($optionsByGroup)) {
            return null;
        }

        uasort($optionsByGroup, fn($a, $b) => $a['position'] <=> $b['position']);

        $optionNames = [];
        foreach ($optionsByGroup as &$groupData) {
            usort($groupData['options'], fn($a, $b) => $a['position'] <=> $b['position']);
            foreach ($groupData['options'] as $optionData) {
                $optionNames[] = $optionData['name'];
            }
        }
        unset($groupData);

        if (empty($optionNames)) {
            return null;
        }

        $variantName = $parentName . ' – ' . implode(' – ', $optionNames);

        if (mb_strlen($variantName) > 255) {
            $variantName = mb_substr($variantName, 0, 252) . '...';
        }

        return $variantName;
    }
}
