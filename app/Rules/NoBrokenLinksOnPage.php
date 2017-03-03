<?php

namespace App\Rules;

use App\Crawler;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class NoBrokenLinksOnPage extends Rule
{
    protected $statusCode;

    protected $msg;

    /** @var ClientInterface */
    protected $client;

    /**
     * Check for broken links on the page.
     *
     * @param ClientInterface $client
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
        logger($client->getConfig('headers'));
    }

    /**
     * {@inheritdoc}
     */
    public function check(Crawler $crawler, ResponseInterface $response, UriInterface $uri)
    {
        $requests = [];

        foreach ($crawler->filter('a')->links() as $link) {
            $uri = $link->getUri();

            // Get HEAD to check if exists
            $request = new Request('HEAD', $uri);

            // Strip fragment
            $uri = $request->getUri()->withFragment('');
            $request = $request->withUri($uri);

            if (in_array($uri->getScheme(), ['http', 'https'], true)) {
                $requests[(string) $request->getUri()] = $request;
            }
        }

        if (empty($requests)) {
            $this->msg = 'No links found.';

            return true;
        }

        $ok = 0;
        $fail = [];
        $pool = new Pool($this->client, $requests, [
            'concurrency' => 5,
            'fulfilled' => function () use (&$ok) {
                ++$ok;
            },
            'rejected' => function (RequestException $e) use (&$ok, &$fail) {
                if ($e->getCode() !== 403) {
                    // Retry the request as HEAD, as not every host supports HEAD
                    $retryRequest = $e->getRequest()->withMethod('GET');

                    try {
                        $this->client->send($retryRequest);
                        ++$ok;

                        return;
                    } catch (RequestException $retryException) {
                        // Failed again
                    }
                }

                if ($response = $e->getResponse()) {
                    $result = "* `{$response->getStatusCode()} {$response->getReasonPhrase()}` - ";
                } else {
                    $result = '* `UNKNOWN ERROR` - ';
                }

                $result .= $e->getRequest()->getUri();
                $fail[] = $result;
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->wait();

        $this->msg = $fail ?
                'Found **'.count($fail).'** broken '.str_plural('link', count($fail)).':'.PHP_EOL.PHP_EOL.implode(PHP_EOL, $fail) :
                "All $ok ".str_plural('link', $ok).' on the page are working.';

        return !$fail;
    }

    /**
     * {@inheritdoc}
     */
    public function passedMessage()
    {
        return $this->msg;
    }

    /**
     * {@inheritdoc}
     */
    public function failedMessage()
    {
        return $this->msg;
    }

    /**
     * {@inheritdoc}
     */
    public function helpMessage()
    {
        return <<<'MSG'
Make sure all links on your page are working, kupo!
MSG;
    }
}
