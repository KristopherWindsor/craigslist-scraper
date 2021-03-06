<?php
namespace Client;

use GuzzleHttp;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use SimpleXMLElement;
use function GuzzleHttp\Promise\settle;

class Program
{
    protected $args;
    protected $clientId;
    protected $hibernate;
    protected $endpoint;
    protected $logger;
    protected $curlMultiHandle;
    protected $lock;

    public function __construct(Args $args)
    {
        $this->args      = $args;
        $this->clientId  = new ClientId($this->args->getArg('clientId'));
        $this->hibernate = new Hibernate($this->clientId);

        if ($this->hibernate->shouldHibernate())
            die();
        $this->dieIfOtherProcessRunning();

        $this->endpoint  = new Endpoint($this->clientId, $this->args->getArg('server'));
        $this->logger    = new VerboseLogger($this->clientId);

        $this->run();
    }

    public function run()
    {
        $instructions = $this->getInstructions();

        $repeatForSeconds = $instructions->repeatForSeconds ?? 1800;
        $exitTime = new \DateTime('+' . $repeatForSeconds . ' seconds');

        for (;
            $this->processInstructions($instructions), new \DateTime() < $exitTime;
            $instructions = $this->getInstructions()
        );
    }

    protected function dieIfOtherProcessRunning()
    {
        $filename = __DIR__ . '/../' . $this->clientId . '-lock.dat';
        if (!file_exists($filename))
            file_put_contents($filename, 'xyz');

        $this->lock = fopen($filename, 'r+');
        if (!$this->lock)
            die('x');
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            die('x');
        }
    }

    protected function getInstructions() : array
    {
        $client = new GuzzleHttp\Client();
        $response = $client->get($this->endpoint . 'instructions');
        $instructions = @json_decode($response->getBody(), true);
        $this->logger->log(
            'got instructions',
            [
                'endpoint' => $this->endpoint . 'instructions',
                'instructions' => $instructions,
                'httpCode' => $response->getStatusCode(),
            ]
        );
        if (!$instructions) {
            // Safe place to quit
            $this->hibernate->hibernateUntil(time() + 120);
            $this->quitPerNetworkError();
        }
        return $instructions;
    }

    protected function processInstructions(array $instructions)
    {
        $sleepDuration = 600;

        switch ($instructions['action'] ?? null) {
            case 'getPages':
                $this->doGetPages($instructions);
                break;
            case 'getRSS':
                $this->doGetRSS($instructions);
                break;
            case 'hibernate':
                $sleepDuration = $instructions['seconds'] ?? $sleepDuration;
            default:
                $this->hibernate->hibernateFor($sleepDuration);
                die();
        }
    }

    protected function doGetPages(array $instructions)
    {
        $startTime = time();

        $guzzle          = $this->getGuzzleClientForCraigslist();
        $craigslistProxy = $this->getProxySettingsFrom($instructions);
        $continueWhenForbidden = !empty($instructions['clientRules']['continueWhenForbidden']);

        $promises = [];
        foreach ($instructions['urls'] as $url) {
            // Scrape Craigslist $url
            $request = new Request('GET', $url);
            try {
                $response = $guzzle->send($request, $craigslistProxy);
                $httpCode = $response->getStatusCode();
            } catch (BadResponseException $e) {
                $response = $e->getResponse();
                $httpCode = $response->getStatusCode();
            } catch (RequestException $e) {
                $response = null;
                $httpCode = 0;
                sleep(1); // likely that proxy server is broken or trying to throttle; so slow down
            }
            if ($httpCode != 200 && $httpCode != 404) {
                if ($continueWhenForbidden) {
                    // Forbidden -- retrying a 403 usually gives another 403, so we move on
                    // Timeouts -- if we retry one page we risk a timeout before getting to the other pages; do not retry
                    $this->logger->log('error, skipping page', ['url' => $url, 'httpCode' => $httpCode, 'response' => $response ? $response->getBody() . '' : null, 'ex' => is_object($e) ? get_class($e) : '']);
                    continue;
                }
                if ($httpCode == 403) {
                    $this->reportForbiddenAndQuit($url);
                }
                $this->logger->log('error getting page', ['url' => $url, 'httpCode' => $httpCode]);
                $this->quitPerNetworkError();
            } else
                $this->logger->log('success getting page', ['url' => $url, 'httpCode' => $httpCode]);

            // Send page to server async
            $promises[] = $guzzle->postAsync(
                $this->endpoint . 'newPage',
                [
                    'body'    => (string) $response->getBody(),
                    'headers' => [
                        'Content-Type'       => 'text/html',
                        'X-SOURCE-URL'       => $url,
                        'X-SOURCE-HTTP-CODE' => $httpCode,
                        'X-CLIENT-ID'        => (string) $this->clientId,
                        'X-CLIENT-VERSION'   => Endpoint::CLIENT_VERSION,
                    ],
                ]
            );

            $this->curlMultiHandle->tick();

            if (time() - $startTime + 1 >= $instructions['timeLimit'])
                break;

            // If we sleep for a long time between ticks, we cannot wait until the end to finish the requests
            // because they will time out
            if ($instructions['sleepDurationMicrosec'] > 1000 * 1000) {
                foreach (settle($promises)->wait() as $result) {
                    if ($result['state'] != 'fulfilled') {
                        $httpCode = $result['reason']->getCode();
                        $this->logger->log('error sending page to server', ['httpCode' => $httpCode, 'info' => print_r($result, true)]);
                        $this->quitPerNetworkError();
                    }
                }
                $promises = [];
            }

            usleep($instructions['sleepDurationMicrosec']);
        }

        foreach (settle($promises)->wait() as $result) {
            if ($result['state'] != 'fulfilled') {
                $httpCode = $result['reason']->getCode();
                $this->logger->log('error sending page to server', ['httpCode' => $httpCode, 'info' => print_r($result, true)]);
                $this->quitPerNetworkError();
            }
        }

        $this->logger->log('finished getting pages', ['howMany' => count($instructions['urls'])]);
    }

    protected function getProxySettingsFrom(array $instructions)
    {
        if (!empty($instructions['clientRules']['proxyIp']) && !empty($instructions['clientRules']['proxyPort'])) {
            $userPassword = '';
            if (!empty($instructions['clientRules']['proxyUser']) && !empty($instructions['clientRules']['proxyPassword'])) {
                $userPassword = $instructions['clientRules']['proxyUser'] . ':' . $instructions['clientRules']['proxyPassword'] . '@';
            }
            return ['proxy' => $userPassword . $instructions['clientRules']['proxyIp'] . ':' . $instructions['clientRules']['proxyPort']];
        }
        return [];
    }

    protected function getGuzzleClientForCraigslist()
    {
        $this->curlMultiHandle = new \GuzzleHttp\Handler\CurlMultiHandler();

        $timeLimit = 5;
        $handler   = \GuzzleHttp\HandlerStack::create($this->curlMultiHandle);

        return new GuzzleHttp\Client([
            'connect_timeout' => $timeLimit,
            'read_timeout'    => $timeLimit,
            'timeout'         => $timeLimit,
            'handler'         => $handler,
            'headers'         => [],
        ]);
    }

    protected function doGetRSS(array $instructions)
    {
        $loopUntil = new \DateTime($instructions['loopUntil']);
        $offset = $instructions['initialOffset'] ?? 0;

        $guzzle          = $this->getGuzzleClientForCraigslist();
        $craigslistProxy = $this->getProxySettingsFrom($instructions);
        $scrapeAttempts  = empty($instructions['clientRules']['continueWhenForbidden']) ? 1 : 200;
        $newestDateFound = null;

        do {
            $url = $instructions['url'] . $offset;

            $offset += 25;
            $isThisTheLastPage = ($offset >= $instructions['maxCount']); // Want to get all results but need to stop at some point

            for ($i = 0; $i < $scrapeAttempts; $i++) {
                try {
                    $response = $guzzle->get($url, $craigslistProxy);
                    $httpCode = $response->getStatusCode();
                    break;
                } catch (BadResponseException $e) {
                    $response = $e->getResponse();
                    $httpCode = $response->getStatusCode();
                } catch (RequestException $e) {
                    $response = null;
                    $httpCode = 0;
                    sleep(1); // likely that proxy server is broken or trying to throttle; so slow down
                }

                if ($scrapeAttempts > 1) {
                    $this->logger->log('retriable error getting rss', ['attempt' => $i, 'attemptLimit' => $scrapeAttempts, 'httpCode' => $httpCode, 'url' => $url]);
                    usleep(100000);
                }
            }
            $rssContent = $response ? (string) $response->getBody() : '';

            $this->logger->log('got RSS page', [
                'url' => $url,
                'strlen' => strlen($rssContent),
                'httpCode' => $httpCode,
                'proxyPort' => $instructions['proxyPort'],
                'proxyIp' => $instructions['proxyIp'],
            ]);
            if ($scrapeAttempts > 1 && $httpCode != 200) {
                $this->logger->log('rss failure -- giving up', ['url' => $url]);
                return;
            }
            if ($httpCode == 403)
                $this->reportForbiddenAndQuit($url);
            if (!$rssContent || $httpCode != 200)
                $this->quitPerNetworkError();
            $rssContent = preg_replace('/[[:^print:]]/', '', $rssContent);

            $pages   = [];
            $results = new SimpleXMLElement($rssContent);
            foreach ($results->item as $item) {
                $dateArray = $item->xpath('dc:date');
                $date = (string) $dateArray[0];

                if (!$newestDateFound || new \DateTime($date) > new \DateTime($newestDateFound)) {
                    $newestDateFound = $date;
                }

                // We got to items that have already been found
                if (new \DateTime($date) <= $loopUntil) {
                    $isThisTheLastPage = true;
                    break;
                }
                $pages[] = [(string) $item->link, $date];
            }
            // Nothing else to get
            if (!$pages)
                $isThisTheLastPage = true;

            // Send pages[] back to server
            try {
                $response = $guzzle->post(
                    $this->endpoint . 'rssResults',
                    [
                        'headers' => [
                            'Content-Type'     => 'application/json',
                            'X-SOURCE-RSS'     => $instructions['url'],
                            'X-CLIENT-ID'      => (string) $this->clientId,
                            'X-CLIENT-VERSION' => Endpoint::CLIENT_VERSION,
                            'X-JOB-COMPLETE'   => ($isThisTheLastPage ? 1 : 0),
                        ] + ($isThisTheLastPage && $newestDateFound ? ['X-NEWEST-ITEM' => $newestDateFound] : []),
                        'body' => json_encode($pages)
                    ]
                );
            } catch (BadResponseException $e) {
                $response = $e->getResponse();
            }
            $output = (string) $response->getBody();

            $this->logger->log('sent RSS results to server', ['url' => $this->endpoint . 'rssResults', 'complete' => $isThisTheLastPage, 'pages' => count($pages), 'result' => $output]);

            sleep(1);
        } while (!$isThisTheLastPage);
    }

    protected function reportForbiddenAndQuit($url)
    {
        $instructions = @json_decode(file_get_contents($this->endpoint . 'reportError'));
        $this->logger->log('reported error', ['403' => 'encountered', 'url' => $url, 'instructions' => $instructions]);
        $this->hibernate->hibernateUntil(time() + ($instructions->hibernateSeconds ?? 600));
        die();
    }

    protected function quitPerNetworkError()
    {
        $this->logger->log('quitting due to network error', []);
        die();
    }
}
