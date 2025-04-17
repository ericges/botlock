<?php

namespace GES\Botlock;

class Kernel
{
    public static function boot(): static
    {
        $config = new Config();
        $jwt = new JWT($config);
        $session = new Session($config, $jwt);

        return new static($config, $session);
    }

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
    ) {}

    public function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? abort(500);
    }
    public function handleRequest(): void
    {
        if ($this->isWhitelisted()) {
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

    private function isWhitelisted(): bool
    {
        $ignoreIps = $this->config->getIgnoreIps();
        $ignoreUserAgents = $this->config->getIgnoreUserAgents();
        $ignoreUrls = $this->config->getIgnoreUrls();

        if (!empty($i))
        $ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && \in_array($ip, $ignoreIps)) {
            return true;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent && \in_array($userAgent, $ignoreUserAgents)) {
            return true;
        }

        $url = getRequestUrl();
        foreach ($ignoreUrls as $w) {
            $w = \trim($w);
            if (\strlen($w) > 0 && \str_starts_with($url, $w)) {
                return true;
            }
        }

        return false;
    }

    private function route(): void
    {
        $requestMethod = $this->getRequestMethod();

        switch ($_GET['_botlock'] ?? null)
        {
            case 'challenge':
                if ($requestMethod !== 'GET') abort(405);
                $this->handleGetChallengeRequest();

            case 'verify':
                if ($requestMethod !== 'POST') abort(405);
                $this->handlePostChallengeRequest();

            default:
                $this->handleAnyRequest();
                break;
        }
    }

    public function handleGetChallengeRequest(): never
    {
        $challenge = new Challenge($this->config);
        $data = $challenge->create();

        $this->session->set('fingerprint', getUserFingerprint());
        $this->session->write();

        sendJson(200, $data);
    }

    public function handlePostChallengeRequest(): never
    {
        $statusCode = 401;

        if ($this->session->get('fingerprint') !== getUserFingerprint()) {
            \http_response_code($statusCode);
            exit(\json_encode(['ok' => false]));
        }

        $data = \json_decode(\file_get_contents('php://input'), true);

        $challenge = new Challenge($this->config);
        if ($ok = $challenge->verify($data))
        {
            $this->session->set('access_granted', true);
            $this->session->write();
            $statusCode = 200;
        }

        sendJson($statusCode, ['ok' => $ok]);
    }

    public function handleAnyRequest(): void
    {
        if ($this->session->get('access_granted', false)
            && $this->session->get('fingerprint') === getUserFingerprint())
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
}