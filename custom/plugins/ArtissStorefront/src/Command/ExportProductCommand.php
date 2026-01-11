<?php declare(strict_types=1);

namespace ArtissStorefront\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:product:save',
    description: 'Get product by ID and save it without changes to trigger events'
)]
class ExportProductCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('product-id', InputArgument::REQUIRED, 'Product ID to save');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $productId = $input->getArgument('product-id');

        $io->title('Product Save (Trigger Events)');

        // Просто проверяем, что товар существует
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if ($product === null) {
            $io->error(sprintf('Product with ID "%s" not found', $productId));
            return Command::FAILURE;
        }

        $io->info(sprintf('Found product: %s', $product->getProductNumber()));

        // Просто сохраняем товар с ID - Shopware вызовет все события
        $this->productRepository->update([['id' => $productId]], $context);

        $io->success(sprintf(
            'Product "%s" saved successfully. All events have been triggered.',
            $product->getProductNumber()
        ));

        $io->table(
            ['Field', 'Value'],
            [
                ['ID', $product->getId()],
                ['Product Number', $product->getProductNumber()],
                ['Name', $product->getTranslated()['name'] ?? $product->getName() ?? 'N/A'],
                ['Active', $product->getActive() ? 'Yes' : 'No'],
                ['Stock', (string) $product->getStock()],
            ]
        );

        return Command::SUCCESS;
    }
}
