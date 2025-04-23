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
            $crawlerDetect = new CrawlerDetect();
            $jwt = new JWT($config);
            $session = new Session($config, $jwt);
            $whitelist = new Whitelist($config, $crawlerDetect);

            return new static($config, $session, $whitelist);
        }
        catch (\Throwable $th)
        {
            abort(500, headers: [
                'Botlock-Error: ' . $th->getMessage(),
            ]);
        }
    }

    public function __construct(
        private Config    $config,
        private Session   $session,
        private Whitelist $whitelist,
    ) {}

    public function handleRequest(): void
    {
        if ($this->whitelist->isRequestWhitelisted()) {
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

        $pow = new ProofOfWork($this->config);
        $goodActor = true;

        if ($this->whitelist->isBot())
        {
            $goodActor = $this->whitelist->isGoodBot();
            $pow->setDifficulty($goodActor ? 0.5 : $this->config->getCrawlerFactor());
        }

        $data = $pow->create();
        $data['auto_start'] = $goodActor;

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

        $challenge = new ProofOfWork($this->config);
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
}