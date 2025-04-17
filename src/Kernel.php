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
        try
        {
            $this->route();
        }
        catch (\Throwable $e)
        {
            \http_response_code(500);
            exit(\json_encode(['error' => $e->getMessage()]));
        }
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

        \header('Content-Type: application/json');
        exit(\json_encode($data));
    }

    public function handlePostChallengeRequest(): never
    {
        $statusCode = 401;

        $data = \json_decode(\file_get_contents('php://input'), true);

        $challenge = new Challenge($this->config);
        if ($ok = $challenge->verify($data))
        {
            $this->session->set('access_granted', true);
            $this->session->write();
            $statusCode = 200;
        }

        \http_response_code($statusCode);
        exit(\json_encode(['ok' => $ok]));
    }

    public function handleAnyRequest(): void
    {
        if ($this->session->get('access_granted', false)) {
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