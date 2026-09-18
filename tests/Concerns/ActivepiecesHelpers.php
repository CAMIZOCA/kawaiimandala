<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * For tests that fake the Activepieces webhook: the token and secret of a request only
 * exist in the payload sent to the flow, so they are read back from the recorded calls.
 * Expects `$this->book` (App\Models\Book).
 */
trait ActivepiecesHelpers
{
    /**
     * Token and secret of the latest request sent for a page position.
     *
     * @return array{token: string, secret: string}
     */
    protected function issued(int $position): array
    {
        $found = null;

        foreach (Http::recorded() as [$request]) {
            $data = $request->data();

            if (($data['position'] ?? null) === $position && ($data['book_uuid'] ?? null) === $this->book->uuid) {
                $found = ['token' => $data['request_token'], 'secret' => $data['callback_secret']];
            }
        }

        $this->assertNotNull($found, "No request was sent to Activepieces for position {$position}.");

        return $found;
    }

    /**
     * POST the callback of a page. $secret: false = the secret issued for that page
     * (the default), null = send none, string = send that one.
     */
    protected function postCallback(int $position, array $body, string|false|null $secret = false)
    {
        $headers = match (true) {
            $secret === false => ['X-Callback-Secret' => $this->issued($position)['secret']],
            $secret === null => [],
            default => ['X-Callback-Secret' => $secret],
        };

        return $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas/{$position}", $body, $headers);
    }

    /** Callback body carrying the issued token. */
    protected function withIssuedToken(int $position, array $body): array
    {
        return ['request_token' => $this->issued($position)['token']] + $body;
    }
}
