<?php

declare(strict_types=1);

namespace App\Webhook\Connector;

use App\Entity;
use App\Service\GuzzleFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Monolog\Logger;

final class Twitter extends AbstractConnector
{
    public const NAME = 'twitter';

    public function __construct(
        Logger $logger,
        Client $httpClient,
        private readonly GuzzleFactory $guzzleFactory,
    ) {
        parent::__construct($logger, $httpClient);
    }

    protected function getRateLimitTime(Entity\StationWebhook $webhook): ?int
    {
        $config = $webhook->getConfig();
        $rateLimitSeconds = (int)($config['rate_limit'] ?? 0);

        return max(10, $rateLimitSeconds);
    }

    /**
     * @inheritDoc
     */
    public function dispatch(
        Entity\Station $station,
        Entity\StationWebhook $webhook,
        Entity\Api\NowPlaying\NowPlaying $np,
        array $triggers
    ): void {
        $config = $webhook->getConfig();

        if (
            empty($config['consumer_key'])
            || empty($config['consumer_secret'])
            || empty($config['token'])
            || empty($config['token_secret'])
        ) {
            throw $this->incompleteConfigException(self::NAME);
        }

        // Set up Twitter OAuth
        $stack = clone $this->guzzleFactory->getHandlerStack();

        $middleware = new Oauth1(
            [
                'consumer_key' => trim($config['consumer_key']),
                'consumer_secret' => trim($config['consumer_secret']),
                'token' => trim($config['token']),
                'token_secret' => trim($config['token_secret']),
            ]
        );
        $stack->push($middleware);

        $raw_vars = [
            'message' => $config['message'] ?? '',
        ];

        $vars = $this->replaceVariables($raw_vars, $np);

        // Dispatch webhook
        $this->logger->debug('Posting to Twitter...');

        $response = $this->httpClient->request(
            'POST',
            'https://api.twitter.com/1.1/statuses/update.json',
            [
                'auth' => 'oauth',
                'handler' => $stack,
                'form_params' => [
                    'status' => $vars['message'],
                ],
            ]
        );

        $this->logger->debug(
            sprintf('Twitter returned code %d', $response->getStatusCode()),
            ['response_body' => $response->getBody()->getContents()]
        );
    }
}
