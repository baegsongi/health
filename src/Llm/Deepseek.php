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
     * JSON 을 받고 싶으면 프롬프트로 부탁한다. response_format(json_object)은 쓰지 않는다 —
     * 추론 모델(deepseek-v4-pro)에서 재보니 생각한 내용을 그대로 답에 쏟거나
     * 시킨 항목을 빼먹었다. 그냥 부탁하면 3~4초에 깔끔하게 온다.
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
        $text   = $data['choices'][0]['message']['content'] ?? null;
        $reason = (string) ($data['choices'][0]['finish_reason'] ?? '?');

        // 추론 모델은 max_tokens 를 생각하는 데 다 쓰기 쉽다. 그러면 답이 비어서 오거나
        // 쓰다 만 채로 온다. 잘린 글을 그대로 넘기면 엉뚱한 데서 터지므로 여기서 끊는다.
        if ($reason === 'length') {
            throw new \RuntimeException(
                "deepseek: max_tokens($maxTokens) 안에서 답을 못 끝냈습니다 — 한도를 늘리세요."
            );
        }
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException("deepseek: 빈 답을 받았습니다 (finish_reason: $reason)");
        }
        return trim($text);
    }
}
