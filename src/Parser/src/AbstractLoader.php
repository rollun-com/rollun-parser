<?php
/**
 * @copyright Copyright © 2014 Rollun LC (http://rollun.com/)
 * @license LICENSE.md New BSD License
 */

namespace rollun\parser;

use Faker\Factory;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use rollun\callback\Callback\Interrupter\QueueFiller;
use rollun\callback\Queues\Message;
use rollun\callback\Queues\QueueInterface;
use rollun\datastore\DataStore\Interfaces\DataStoresInterface;
use rollun\dic\InsideConstruct;
use Zend\Validator\ValidatorInterface;

abstract class AbstractLoader
{
    const MAX_ATTEMPTS = 10;

    const RANDOM_PROXY_ID = '0.0.0.0';

    const STORAGE_DIR = 'documents';

    /**
     * @var DataStoresInterface
     */
    protected $proxyDataStore;

    /**
     * @var QueueInterface
     */
    protected $documentQueue;

    /**
     * @var array
     */
    protected $clientConfig;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ValidatorInterface
     */
    protected $responseValidator;

    /**
     * AbstractLoader constructor.
     * @param DataStoresInterface $proxyDataStore
     * @param QueueInterface $documentQueue
     * @param array $clientConfig
     * @param ValidatorInterface $validator
     * @param LoggerInterface|null $logger
     * @throws \ReflectionException
     */
    public function __construct(
        DataStoresInterface $proxyDataStore,
        QueueInterface $documentQueue,
        array $clientConfig,
        ValidatorInterface $validator,
        LoggerInterface $logger = null
    ) {
        $this->responseValidator = $validator;
        $this->proxyDataStore = $proxyDataStore;
        $this->documentQueue = $documentQueue;
        $this->clientConfig = $clientConfig;
        InsideConstruct::setConstructParams(['logger' => LoggerInterface::class]);
    }

    /**
     * @param ServerRequestInterface $request
     * @throws GuzzleException
     */
    public function __invoke(ServerRequestInterface $request)
    {
        $startTime = new \DateTime();
        $proxy = $this->proxyDataStore->read(self::RANDOM_PROXY_ID);

        if (!$proxy) {
            throw new \RuntimeException("Can't fetch proxies");
        }

        try {
            $this->logger->debug('Sent http request using Guzzlehttp', [
                'uri' => $request->getUri()->__toString(),
                'proxy' => $proxy,
                'start_time' => date('d.m H:i:s'),
            ]);

            $request = $this->withUserAgent($request);
            $response = $this->getClient()->send($request, ['proxy' => $proxy['proxy']]);

            $this->logger->debug('Fetching http response using Guzzlehttp', [
                'uri' => $request->getUri()->__toString(),
                'proxy' => $proxy,
                'end_time' => date('d.m H:i:s'),
            ]);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $this->logger->warning('Failed to fetch http response using Guzzlehttp', [
                'exception' => $e,
                'uri' => $request->getUri()->__toString(),
                'proxy' => $proxy,
            ]);
        }

        $endTime = new \DateTime();
        $proxy['rating'] = $this->createRating($response, $startTime, $endTime);
        $this->proxyDataStore->update($proxy);

        if ($this->responseValidator->isValid($response)) {
            $this->saveDocument($response, $request);
        } else {
            throw new \RuntimeException("Response is not valid. {$this->responseValidator->getMessages()}");
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param string|null $userAgent
     * @return ServerRequestInterface
     */
    protected function withUserAgent(ServerRequestInterface $request, ?string $userAgent = null): ServerRequestInterface
    {
        if (!$userAgent) {
            $userAgent = Factory::create()->userAgent;
        }

        $request = $request->withHeader('User-Agent', $userAgent);

        return $request;
    }

    protected function saveDocument(ResponseInterface $response, ServerRequestInterface $request)
    {
        try {
            $data = $response->getBody()->getContents();
            $filename = $this->createFilename();
            file_put_contents($filename, $data);
            $message = Message::createInstance(QueueFiller::serializeMessage(['filepath' => $filename]));
            $this->documentQueue->addMessage($message);
        } catch (\Throwable $t) {
            throw new \RuntimeException("Error when trying save document", 0, $t);
        }
    }

    protected function getClient(): Client
    {
        if ($this->client == null) {
            $this->client = new Client($this->clientConfig);
        }

        return $this->client;
    }

    /**
     * Create filepath 'tmp-directory-in-your-system/documents/some-hash' directory of OS
     *
     * Example for linux:
     * '/tmp/documents/queue-name/ec28346356cd2e430f58d523bcf937a05c5954d731c83'
     *
     * @return string
     */
    protected function createFilename()
    {
        $storageDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::STORAGE_DIR;

        if (!file_exists($storageDir)) {
            throw new \RuntimeException("Directory '$storageDir' doesn't exist");
        }

        $dirName = $storageDir . DIRECTORY_SEPARATOR . $this->documentQueue->getName();

        if (!file_exists($dirName)) {
            mkdir($dirName);
        }

        $filename = microtime(true);

        return $dirName . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Create rating about proxy from 1 to 10
     *
     * @param ResponseInterface $response
     * @param \DateTime $startTime
     * @param \DateTime $endTime
     * @return int
     */
    abstract protected function createRating(
        ?ResponseInterface $response,
        \DateTime $startTime,
        \DateTime $endTime
    ): int;

    public function __sleep()
    {
        return ['proxyDataStore', 'documentQueue', 'clientConfig', 'responseValidator'];
    }

    public function __wakeup()
    {
        InsideConstruct::initWakeup(['logger' => LoggerInterface::class]);
    }
}
