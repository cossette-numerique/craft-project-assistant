<?php

namespace cossette\projectassistant\tickets;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use yii\base\Exception;

/**
 * POSTs the handoff as JSON to a URL. With a secret, the body is signed:
 * `X-Project-Assistant-Signature: sha256=<hex HMAC of the raw body>`.
 *
 * Expects a 2xx response; reads `reference`/`id`, `url` and `status` from a
 * JSON body when present.
 */
class WebhookProvider implements TicketProviderInterface
{
    public string $url = '';
    public string $secret = '';
    public string $name = 'Webhook';
    /** @var array<string, string> extra headers, values may be env vars */
    public array $headers = [];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function getName(): string
    {
        return (string)App::parseEnv($this->name);
    }

    public function isConnected(): bool
    {
        return (string)App::parseEnv($this->url) !== '';
    }

    public function createTicket(array $handoff): TicketResult
    {
        $url = (string)App::parseEnv($this->url);
        if ($url === '') {
            throw new Exception('The ticket webhook URL is not configured.');
        }

        $body = Json::encode($handoff);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach ($this->headers as $header => $value) {
            $headers[$header] = (string)App::parseEnv($value);
        }
        $secret = (string)App::parseEnv($this->secret);
        if ($secret !== '') {
            $headers['X-Project-Assistant-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = Craft::createGuzzleClient(['timeout' => 20])->post($url, [
            'headers' => $headers,
            'body' => $body,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new Exception("The ticket system answered HTTP $status.");
        }

        $data = Json::decodeIfJson((string)$response->getBody());
        $data = is_array($data) ? $data : [];

        return new TicketResult(
            reference: (string)($data['reference'] ?? $data['id'] ?? $data['key'] ?? 'sent'),
            url: isset($data['url']) ? (string)$data['url'] : null,
            status: (string)($data['status'] ?? 'submitted'),
        );
    }
}
