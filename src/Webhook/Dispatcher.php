<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity;
use App\Environment;
use App\Http\RouterInterface;
use App\Message;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class Dispatcher
{
    public function __construct(
        private readonly Environment $environment,
        private readonly Logger $logger,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
        private readonly LocalWebhookHandler $localHandler,
        private readonly ConnectorLocator $connectors,
        private readonly Entity\ApiGenerator\NowPlayingApiGenerator $nowPlayingApiGen
    ) {
    }

    /**
     * Handle event dispatch.
     *
     * @param Message\AbstractMessage $message
     */
    public function __invoke(Message\AbstractMessage $message): void
    {
        if ($message instanceof Message\DispatchWebhookMessage) {
            $this->handleDispatch($message);
        } elseif ($message instanceof Message\TestWebhookMessage) {
            $this->testDispatch($message);
        }
    }

    private function handleDispatch(Message\DispatchWebhookMessage $message): void
    {
        $station = $this->em->find(Entity\Station::class, $message->station_id);
        if (!$station instanceof Entity\Station) {
            return;
        }

        $np = $message->np;
        $triggers = $message->triggers;

        // Always dispatch the special "local" updater task.
        try {
            $this->localHandler->dispatch($station, $np);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('%s L%d: %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                [
                    'exception' => $e,
                ]
            );
        }

        if ($this->environment->isTesting()) {
            $this->logger->notice('In testing mode; no webhooks dispatched.');
            return;
        }

        /** @var Entity\StationWebhook[] $enabledWebhooks */
        $enabledWebhooks = $station->getWebhooks()->filter(
            function (Entity\StationWebhook $webhook) {
                return $webhook->getIsEnabled();
            }
        );

        $this->logger->debug('Webhook dispatch: triggering events: ' . implode(', ', $triggers));

        foreach ($enabledWebhooks as $webhook) {
            $connectorObj = $this->connectors->getConnector($webhook->getType());

            if ($connectorObj->shouldDispatch($webhook, $triggers)) {
                $this->logger->debug(sprintf('Dispatching connector "%s".', $webhook->getType()));

                try {
                    $connectorObj->dispatch($station, $webhook, $np, $triggers);
                    $webhook->updateLastSentTimestamp();
                    $this->em->persist($webhook);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        sprintf('%s L%d: %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                        [
                            'exception' => $e,
                        ]
                    );
                }
            }
        }

        $this->em->flush();
    }

    private function testDispatch(
        Message\TestWebhookMessage $message
    ): void {
        $outputPath = $message->outputPath;

        if (null !== $outputPath) {
            $logHandler = new StreamHandler($outputPath, Level::Debug, true);
            $this->logger->pushHandler($logHandler);
        }

        try {
            $webhook = $this->em->find(Entity\StationWebhook::class, $message->webhookId);
            if (!($webhook instanceof Entity\StationWebhook)) {
                return;
            }

            $station = $webhook->getStation();
            $np = $this->nowPlayingApiGen->currentOrEmpty($station);
            $np->resolveUrls($this->router->getBaseUrl());
            $np->cache = 'event';

            $connectorObj = $this->connectors->getConnector($webhook->getType());
            $connectorObj->dispatch($station, $webhook, $np, [Entity\StationWebhook::TRIGGER_ALL]);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    '%s L%d: %s',
                    $e->getFile(),
                    $e->getLine(),
                    $e->getMessage()
                ),
                [
                    'exception' => $e,
                ]
            );
        } finally {
            if (null !== $outputPath) {
                $this->logger->popHandler();
            }
        }
    }
}
