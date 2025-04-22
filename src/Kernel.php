<?php

namespace GES\Botlock;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

readonly class Kernel
{
    public static function boot(): static
    {
        try
        {
            $config = new Config();
            $jwt = new JWT($config);
            $session = new Session($config, $jwt);

            return new static($config, $session);
        }
        catch (\Throwable $th)
        {
            abort(500, headers: [
                'Botlock-Error: ' . $th->getMessage(),
            ]);
        }
    }

    public function __construct(
        private Config  $config,
        private Session $session,
    ) {}

    public function handleRequest(): void
    {
        if ($this->isRequestWhitelisted()) {
            return;
        }

        try
        {
            $this->route();
        }
        catch (\Throwable $e)
        {
            sendJson(500, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isRequestWhitelisted(): bool
    {
        $ip = getReliableClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if ($ip && ($ignoreIps = $this->config->getIgnoreIps()) && \in_array($ip, $ignoreIps)) {
            return true;
        }

        if ($userAgent && $ignoreUserAgents = $this->config->getIgnoreUserAgents())
        {
            foreach ($ignoreUserAgents as $iua)
            {
                if (!\str_contains($userAgent, $iua)) {
                    continue;
                }

                if ($this->config->getDnsChecks() && \str_contains($iua, 'Google')) {
                    if ($this->verifyGooglebot($ip)) {
                        return true;
                    }
                    // else -> user agent is probably spoofed
                } else {
                    return true;
                }
            }
        }

        if ($ignoreUrls = $this->config->getIgnoreUrls())
        {
            $url = getRequestUrl();
            foreach ($ignoreUrls as $w) {
                $w = \trim($w);
                if (\strlen($w) > 0 && \str_starts_with($url, $w)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function route(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? abort(500);
        $requestMethod = \strtoupper($requestMethod);

        switch ($_GET['_botlock'] ?? null)
        {
            case 'challenge':
                if ($requestMethod !== 'GET') abort(405);
                $this->handleGetChallengeRequest();

            case 'verify':
                if ($requestMethod !== 'POST') abort(405);
                $this->handlePostChallengeRequest();

            case 'reset':
                if ($requestMethod !== 'POST') abort(405);
                $this->handleResetRequest();

            default:
                $this->handleAnyRequest();
                break;
        }
    }

    public function handleGetChallengeRequest(): never
    {
        if (!($nonce = $_SERVER['HTTP_BOTLOCK_NONCE'] ?? null)
            || !\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $nonce))
        {
            sendJson(400, [
                'ok' => false,
                'error' => 'Invalid nonce',
            ]);
        }

        $crawlerDetect = new CrawlerDetect();
        $factor = null;

        if ($crawlerDetect->isCrawler())
        {
            $match = \strtolower($crawlerDetect->getMatches() ?: '');
            $goodBots = \array_map(
                static fn($bot): string => \trim(\strtolower((string) $bot)),
                $this->config->getGoodBots()
            );

            if (!$match || !\array_filter($goodBots, static fn($bot): bool => \str_contains($match, $bot)))
            {
                $factor = $this->config->getCrawlerFactor();
            }
        }

        $challenge = new Challenge($this->config);
        $data = $challenge->create($factor);

        $this->session->set('nh', \password_hash($nonce, \PASSWORD_DEFAULT));
        $this->session->write();

        sendJson(200, $data);
    }

    public function handlePostChallengeRequest(): never
    {
        if (!$nonceHash = $this->session->get('nh')) {
            sendJson(400, [
                'ok' => false,
                'error' => 'Invalid session data',
            ]);
        }

        if (!($nonce = $_SERVER['HTTP_BOTLOCK_NONCE'] ?? null) || !\password_verify($nonce, $nonceHash)) {
            sendJson(400, [
                'ok' => false,
                'error' => 'Invalid nonce',
            ]);
        }

        $data = \json_decode(\file_get_contents('php://input'), true);

        if (!\is_array($data)) {
            sendJson(400, [
                'ok' => false,
                'error' => 'Invalid data',
            ]);
        }

        unset($data['nonce']);

        $statusCode = 401;

        $challenge = new Challenge($this->config);
        if ($ok = $challenge->verify($data))
        {
            $this->session->set('grant', true);
            $this->session->remove('nh');
            $this->session->write();
            $statusCode = 200;
        }

        sendJson($statusCode, ['ok' => $ok]);
    }

    public function handleResetRequest(): never
    {
        $this->session->clear();
        $this->session->write();

        if (($location = $_POST['location'] ?? null)
            && \filter_var($location = getRequestUrl($location), \FILTER_VALIDATE_URL))
        {
            respond(303, headers: [
                'Location: ' . $location,
            ]);
        }

        sendJson(200, [
            'ok' => true,
            'message' => 'Session cleared',
        ]);
    }

    public function handleAnyRequest(): void
    {
        if ($this->session->get('grant', false))
        {
            return;
        }

        $this->session->write();
        \http_response_code(401);

        if ($fp = \fopen(__DIR__ . '/../assets/challenge.html', 'r'))
        {
            while (($buffer = \fgets($fp, 4096)) !== false)
            {
                echo $buffer;
            }
            \fclose($fp);
        }

        exit;
    }

    private function verifyGooglebot(string $ip): bool
    {
        $hostname = @\gethostbyaddr($ip);
        if (!$hostname) {
            return false;
        }

        $hostname = \strtolower($hostname);
        if (!\str_ends_with($hostname, '.google.com') && !\str_ends_with($hostname, '.googlebot.com')) {
            return false;
        }

        $ips = @\dns_get_record($hostname, \DNS_A + \DNS_AAAA);
        if (!$ips) {
            return false;
        }

        $foundOriginalIp = false;
        foreach ($ips as $record) {
            $resolvedIp = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($resolvedIp === $ip) {
                $foundOriginalIp = true;
                break;
            }
        }

        if (!$foundOriginalIp) {
            return false;
        }

        return true;
    }
}