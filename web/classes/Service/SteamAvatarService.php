<?php

    namespace Service;

    use PDOException;
    use Repository\PlayerRepository;
    use Repository\SteamAvatarGateRepository;
    use Utils\Logger;

    /**
     * Refreshes cached Steam avatars after a page has been sent. Pages only
     * read hlstats_Players.avatarUrl. At most one request per interval, across
     * all web servers, wins the shared gate and makes one GetPlayerSummaries
     * call for up to 100 recently active Steam accounts. Everyone else skips.
     */
    class SteamAvatarService
    {
        private const STEAM_ID64_BASE = 76561197960265728;
        private const DAY = 86400;
        private const BATCH_SIZE = 100;  // Steam's maximum per GetPlayerSummaries call
        private const WEEKLY_SLOTS = 20;  // reserved for the weekly tier; unused slots go to the daily tier
        private const CANDIDATES_PER_TIER = 300;  // players read per tier, before merging their games into accounts
        private const LEASE_SECONDS = 60;
        private const SAVE_DEADLINE = 45;  // seconds after the claim; later saves wait for the next batch
        private const MAX_BODY_BYTES = 1048576;  // 100 player summaries are about 50 KB
        private const CONNECT_TIMEOUT = 2;
        private const TIMEOUT = 5;
        private const BACKOFF_FIRST = 900;  // 15 minutes, doubled per consecutive failure
        private const BACKOFF_MAX = 21600;  // 6 hours
        private const RETRY_AFTER_MAX = self::DAY;
        private const AUTH_FAILURE_PAUSE = self::DAY;

        private PlayerRepository $playerRepo;
        private SteamAvatarGateRepository $gate;
        private Logger $logger;
        private string $apiKey;
        private int $interval;
        private int $dailyDays;
        private int $weeklyDays;

        public function __construct(
            PlayerRepository $playerRepo,
            SteamAvatarGateRepository $gate,
            Logger $logger,
            #[\SensitiveParameter] string $apiKey,
            int $interval,
            int $dailyDays,
            int $weeklyDays
        ) {
            $this->playerRepo = $playerRepo;
            $this->gate = $gate;
            $this->logger = $logger;
            $this->apiKey = trim($apiKey);
            $this->interval = max(60, $interval);
            $this->dailyDays = min(3650, max(0, $dailyDays));
            $this->weeklyDays = min(3650, max($this->dailyDays, $weeklyDays));
        }

        /**
         * Call at the very end of a page, after all output and session writes.
         * Sends the response, then tries the gate. Does nothing without an API
         * key or without PHP-FPM's fastcgi_finish_request(), so the visitor
         * never waits for Steam.
         */
        public function refreshAfterResponse() : void
        {
            if ($this->apiKey === '' || !function_exists('fastcgi_finish_request')) {
                return;
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if (!fastcgi_finish_request()) {
                return;
            }

            try {
                // A consistent read of one InnoDB row: never waits for the claim's row lock.
                if (!$this->gate->isOpen()) {
                    return;
                }
            } catch (PDOException $e) {
                $this->logger->error('Steam avatar refresh skipped: ' . $e->getMessage());
                return;
            }

            $this->gate->limitSqlTime(true);
            try {
                $this->refreshBatch();
            } catch (PDOException $e) {
                $this->logger->error('Steam avatar refresh skipped: ' . $e->getMessage());
            } finally {
                $this->gate->limitSqlTime(false);
            }
        }

        private function refreshBatch() : void
        {
            $owner = bin2hex(random_bytes(16));
            $deadline = time() + self::SAVE_DEADLINE;
            $claim = $this->gate->claim($owner, $this->interval + random_int(0, intdiv($this->interval, 10)), self::LEASE_SECONDS);
            if ($claim === null) {
                return;
            }

            $status = 'error';
            $called = false;
            $succeeded = false;
            $delay = 0;

            try {
                [$accounts, $skipped] = $this->selectAccounts();
                $this->playerRepo->markSteamAvatarsChecked($skipped);

                if ($accounts === []) {
                    $status = 'nothing due';
                    return;
                }

                $called = true;
                $result = $this->fetchAvatarUrls(array_keys($accounts));
                $status = $result['status'];

                if ($result['avatars'] === null) {
                    $failures = (int)$claim['failures'] + 1;
                    $delay = $this->failureDelay($result['httpCode'], $result['retryAfter'], $failures);
                    $this->logger->error(sprintf(
                        'Steam avatar refresh failed: %s for %d accounts in %d ms (call %d, %d consecutive failures); next attempt in %d s%s',
                        $status, count($accounts), $result['ms'], (int)$claim['calls'] + 1, $failures, $delay, $result['detail']
                    ));
                    return;
                }

                $succeeded = true;
                foreach ($accounts as $steamId => $uniqueId) {
                    if (time() > $deadline) {
                        break;
                    }
                    // Steam omits accounts it cannot return; keep their cached URL.
                    $this->playerRepo->saveSteamAvatar(
                        $uniqueId,
                        array_key_exists($steamId, $result['avatars']) ? $result['avatars'][$steamId] : false
                    );
                }
            } finally {
                $this->gate->finish($owner, $status, $called, $succeeded, $delay);
            }
        }

        /**
         * Picks up to 100 distinct Steam accounts: players active within
         * dailyDays whose avatar is over a day old, and players active within
         * weeklyDays whose avatar is over a week old, most recently active
         * first. Returns [steamId64 => uniqueId, playerIds to mark checked].
         */
        private function selectAccounts() : array
        {
            $skipped = [];
            $daily = $this->accountsOf(
                $this->playerRepo->getSteamAvatarCandidates($this->dailyDays * self::DAY, 0, self::DAY, self::CANDIDATES_PER_TIER),
                $skipped
            );
            $weekly = $this->accountsOf(
                $this->playerRepo->getSteamAvatarCandidates($this->weeklyDays * self::DAY, $this->dailyDays * self::DAY, 7 * self::DAY, self::CANDIDATES_PER_TIER),
                $skipped
            );

            $accounts = [];
            foreach ([array_slice($weekly, 0, self::WEEKLY_SLOTS, true), $daily, $weekly] as $tier) {
                foreach ($tier as $steamId => $uniqueId) {
                    if (count($accounts) >= self::BATCH_SIZE) {
                        break 2;
                    }
                    $accounts[$steamId] ??= $uniqueId;
                }
            }

            return [$accounts, $skipped];
        }

        /**
         * Groups candidate rows by Steam account, keeping their order. Adds
         * players without a valid Steam ID or with a blocked avatar to $skipped.
         */
        private function accountsOf(array $rows, array &$skipped) : array
        {
            $accounts = [];
            $valid = [];
            foreach ($rows as $row) {
                $steamId = $row['blockavatar'] ? null : self::steamId64((string)$row['uniqueId']);
                if ($steamId !== null) {
                    $accounts[$steamId] ??= $row['uniqueId'];
                    $valid[$row['playerId']] = true;
                }
            }

            foreach ($rows as $row) {
                if (!isset($valid[$row['playerId']])) {
                    $skipped[$row['playerId']] = $row['playerId'];
                }
            }

            return $accounts;
        }

        /**
         * Normal mode stores Steam IDs as "Y:Z" (STEAM_X:Y:Z without the
         * universe). Bots, pending/LAN IDs and other strings return null. The
         * account ID Z*2+Y must be a non-zero uint32.
         */
        private static function steamId64(string $uniqueId) : ?int
        {
            if (!preg_match('/^([01]):(0|[1-9]\d{0,9})$/', $uniqueId, $m)) {
                return null;
            }

            $accountId = (int)$m[2] * 2 + (int)$m[1];

            return $accountId === 0 || $accountId > 0xFFFFFFFF ? null : self::STEAM_ID64_BASE + $accountId;
        }

        /**
         * Makes one GetPlayerSummaries call. 'avatars' maps each returned
         * steamId64 to its avatarfull URL (null when it has none), or is null
         * when the call failed.
         */
        private function fetchAvatarUrls(array $steamIds) : array
        {
            $headers = [];
            $body = '';
            $curl = curl_init('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?'
                . http_build_query(['key' => $this->apiKey, 'steamids' => implode(',', $steamIds)]));
            curl_setopt_array($curl, [
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers) : int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
                // Returning less than the chunk length aborts an oversized response.
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body) : int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            // Never log the request URL: it contains the API key.
            $start = hrtime(true);
            $ok = curl_exec($curl);
            $result = [
                'avatars' => null,
                'httpCode' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'retryAfter' => self::parseRetryAfter($headers['retry-after'] ?? null),
                'ms' => intdiv(hrtime(true) - $start, 1000000),
                'detail' => '',
            ];

            if ($ok === false) {
                $result['status'] = 'cURL error: ' . curl_error($curl);
                return $result;
            }

            $result['status'] = 'HTTP ' . $result['httpCode'];
            unset($headers['set-cookie']);
            // A proxy or error page may echo the request URL: scrub the key before truncating.
            $headerText = str_replace($this->apiKey, '[key]', implode('; ', array_map(fn($name, $value) => "$name: $value", array_keys($headers), $headers)));
            $bodyText = preg_replace('/\s+/', ' ', substr(str_replace($this->apiKey, '[key]', $body), 0, 300));

            if ($result['httpCode'] !== 200) {
                $result['detail'] = '; headers: ' . substr($headerText, 0, 1000) . '; body: ' . $bodyText;
                return $result;
            }

            $players = json_decode($body, true)['response']['players'] ?? null;
            if (!is_array($players)) {
                $result['status'] = 'HTTP 200 unexpected response';
                $result['detail'] = '; body: ' . $bodyText;
                return $result;
            }

            $avatars = [];
            foreach ($players as $player) {
                if (!isset($player['steamid']) || !ctype_digit((string)$player['steamid'])) {
                    continue;
                }
                $url = $player['avatarfull'] ?? null;
                $avatars[(int)$player['steamid']] = is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= 255
                    ? $url
                    : null;
            }

            $result['avatars'] = $avatars;
            $result['status'] = sprintf('HTTP 200, %d of %d accounts', count(array_intersect_key($avatars, array_flip($steamIds))), count($steamIds));

            return $result;
        }

        /**
         * Seconds until the next attempt after a failed call. 401/403 mean the
         * key is rejected, so pause for a day. Otherwise honour Retry-After, or
         * back off 15 min, 30 min, 1 h ... up to 6 h, with up to 10% jitter.
         */
        private function failureDelay(int $httpCode, ?int $retryAfter, int $failures) : int
        {
            if ($httpCode === 401 || $httpCode === 403) {
                return self::AUTH_FAILURE_PAUSE;
            }

            if ($retryAfter !== null) {
                return min(self::RETRY_AFTER_MAX, max($this->interval, $retryAfter));
            }

            $delay = min(self::BACKOFF_MAX, self::BACKOFF_FIRST * 2 ** min($failures - 1, 5));

            return min(self::BACKOFF_MAX, $delay + random_int(0, intdiv($delay, 10)));
        }

        /**
         * Accepts delay-seconds or an HTTP date; returns null when absent or invalid.
         */
        private static function parseRetryAfter(?string $value) : ?int
        {
            if ($value === null || $value === '') {
                return null;
            }

            if (ctype_digit($value)) {
                return (int)$value;
            }

            $time = strtotime($value);

            return $time === false ? null : max(0, $time - time());
        }
    }
