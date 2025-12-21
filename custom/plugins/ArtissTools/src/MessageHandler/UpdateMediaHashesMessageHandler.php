<?php
declare(strict_types=1);

namespace ArtissTools\MessageHandler;

use ArtissTools\Message\UpdateMediaHashesMessage;
use ArtissTools\Service\MediaHashService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class UpdateMediaHashesMessageHandler
{
    public function __construct(
        private readonly MediaHashService $mediaHashService,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    public function __invoke(UpdateMediaHashesMessage $message): void
    {
        $result = $this->mediaHashService->updateMediaHashesBatch(
            $message->getBatchSize(),
            $message->getOffset(),
            $message->getFolderEntity(),
            Context::createDefaultContext()
        );

        // If there are more media to process, dispatch another message
        if ($result['hasMore']) {
            $nextMessage = new UpdateMediaHashesMessage(
                $message->getBatchSize(),
                $message->getOffset() + $message->getBatchSize(),
                $message->getFolderEntity()
            );

            $this->messageBus->dispatch($nextMessage);
        }
    }
}
