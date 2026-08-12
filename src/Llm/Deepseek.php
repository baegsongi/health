<?php
declare(strict_types=1);

namespace Health\Llm;

use Health\App;

/**
 * DeepSeek chat completions. OpenAI 와 같은 형식이다.
 * 키와 모델 이름은 .env 에 둔다(저장소에 올리지 않는다).
 */
final class Deepseek
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        private readonly string $key = '',
        private readonly string $model = '',
        private readonly int $timeout = 30,
    ) {
    }

    private function key(): string
    {
        return $this->key !== '' ? $this->key : App::env('DEEPSEEK_KEY');
    }

    private function model(): string
    {
        return $this->model !== '' ? $this->model : App::env('LLM_MODEL', 'deepseek-chat');
    }

    /** 키가 없으면 AI 기능 전체를 끈다. */
    public function isReady(): bool
    {
        return $this->key() !== '';
    }

    /**
     * 한 번 물어보고 답 글자만 돌려준다.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @throws \RuntimeException 호출이 실패하면
     */
    public function chat(array $messages, float $temperature = 0.8, int $maxTokens = 1500): string
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('DEEPSEEK_KEY 가 없습니다.');
        }

        $body = [
            'model'       => $this->model(),
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
            'stream'      => false,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->key(),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("deepseek: 연결 실패 — $err");
        }
        $data = json_decode((string) $raw, true);

        if ($code !== 200) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new \RuntimeException("deepseek: HTTP $code" . ($msg !== '' ? " — $msg" : ''));
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            // 추론 모델은 max_tokens 를 생각하는 데 다 쓰면 답이 비어서 온다.
            $reason = $data['choices'][0]['finish_reason'] ?? '?';
            throw new \RuntimeException("deepseek: 빈 답을 받았습니다 (finish_reason: $reason)");
        }
        return trim($text);
    }
}
