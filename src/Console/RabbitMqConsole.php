<?php

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\Core\Console;

use EventLoop\EventLoop;
use Rx\Scheduler;
use Rxnet\RabbitMq\Client;
use Rxnet\RabbitMq\Message;
use RxThunder\Core\Router\RabbitMq\Adapter;
use RxThunder\Core\Router\Router;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class RabbitMqConsole extends AbstractConsole
{
    public static $expression = 'rabbit:listen:broker queue [connection] [--middlewares=]* [--timeout=] [--max-retry=] [--max-retry-routing-key=]';
    public static $description = 'RabbitMq consumer to send command to saga process manager';
    public static $argumentsAndOptions = [
        'queue' => 'Name of the queue to connect to',
        'connection' => 'RabbitMq instance to connect to',
        '--timeout' => 'If the timeout is reached, the message will be nacked (use -1 for no timeout)',
        '--max-retry' => 'The max retried number of times (-1 for no max retry)',
        '--max-retry-routing-key' => 'If the max-retry option is activated, the name of the routing key where to put the failing message',
    ];

    public static $defaults = [
        'timeout' => 10000,
        'max-retry' => -1,
        'max-retry-routing-key' => '/failed-message',
    ];

    private $parameterBag;
    private $router;
    private $adapter;

    public function __construct(
        ParameterBagInterface $parameterBag,
        Router $router,
        Adapter $adapter
    ) {
        $this->parameterBag = $parameterBag;
        $this->router = $router;
        $this->adapter = $adapter;
    }

    public function __invoke(
        string $queue,
        string $connection,
        array $middlewares,
        int $timeout,
        int $maxRetry,
        string $maxRetryRoutingKey
    ) {
        if (-1 !== $timeout) {
            $this->adapter->setTimeout($timeout);
        }

        if (-1 !== $maxRetry) {
            $this->adapter->rejectToBottomInsteadOfNacking();
        }

        // You only need to set the default scheduler once
        Scheduler::setDefaultFactory(
            function () {
                // The getLoop method auto start loop
                return new Scheduler\EventLoopScheduler(EventLoop::getLoop());
            }
        );

        // Middlewares declared in config/middleware.php
//        foreach ($middlewares as $middleware) {
//            $this->middlewareProvider->append($this->container->get("middleware.{$middleware}"));
//        }

        $bunny = $this->getBunny($connection);

//        $this->log->info('Connect to eventStore');

        $bunny
            ->consume($queue, 1)
            ->flatMap(function (Message $message) use ($connection, $maxRetry, $maxRetryRoutingKey) {
                if (-1 !== $maxRetry) {
                    $tried = (int) $message->getHeader(Message::HEADER_TRIED, 0);

                    if ($tried >= $maxRetry) {
                        $message->headers = array_merge($message->headers, ['Failed-message-routing-key' => $message->getRoutingKey()]);

                        return $this->getBunny($connection)
                            ->produce($message->getData(), $maxRetryRoutingKey, 'amq.direct', $message->headers)
                            ->doOnCompleted(
                                function () use ($message, $maxRetryRoutingKey) {
                                    echo "Message {$message->getRoutingKey()} was send to {$maxRetryRoutingKey}";

                                    $message->ack();
                                }
                            );
                    }
                }

                return ($this->adapter)($message)
                    ->do(function ($subject) {
                        ($this->router)($subject);
                    });
            })->subscribe(
                null,
                function ($e) {
                    var_dump($e);
                }
            );

//        $this->log->info("Connected to stream {$stream} as group {$group}");

//        $adapter = $this->container->make(
//            AcknowledgeableJsonAdapter::class,
//            ['timeout' => $timeout]
//        );

//        $middlewareSequence = array_merge(
//            $this->middlewareProvider->beforeAdapter(), [$adapter], $this->middlewareProvider->beforeRoute()
//        );

//        $observable = $this->eventStore->persistentSubscription($stream, $group);

//        foreach ($middlewareSequence as $selector) {
//            $observable = $observable->flatMap($selector);
//        }

//        $observable
//            ->subscribe(
//                new CallbackObserver(
////                    [$this->router, 'onNext'],
//                    null,
//                    function (\Exception $e) {
//                        die('Error in persistent subscription crash : '.$e->getMessage());
//                    }
//                ),
//                new EventLoopScheduler($this->loop)
//            );
    }

    private function getBunny($connection)
    {
        return new Client(EventLoop::getLoop(), [
            'host' => $this->parameterBag->get("rabbit.{$connection}.host"),
            'port' => $this->parameterBag->get("rabbit.{$connection}.port"),
            'vhost' => $this->parameterBag->get("rabbit.{$connection}.vhost"),
            'user' => $this->parameterBag->get("rabbit.{$connection}.user"),
            'password' => $this->parameterBag->get("rabbit.{$connection}.password"),
        ]);
    }
}
