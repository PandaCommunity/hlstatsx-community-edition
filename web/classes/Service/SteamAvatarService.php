<?php

    namespace Service;

    use Repository\PlayerRepository;
    use Utils\Logger;

    class SteamAvatarService
    {
        private const CACHE_SECONDS = 86400;
        private const STEAM_ID64_BASE = 76561197960265728;

        private PlayerRepository $playerRepo;
        private Logger $logger;
        private string $apiKey;

        public function __construct(PlayerRepository $playerRepo, Logger $logger, #[\SensitiveParameter] string $apiKey)
        {
            $this->playerRepo = $playerRepo;
            $this->logger = $logger;
            $this->apiKey = $apiKey;
        }

        /**
         * Returns the player's Steam avatar URL, or null to show the default
         * avatar. Calls the Steam Web API at most once per player per cache
         * period; failures and missing profiles are cached too.
         */
        public function getAvatarUrl(int $player) : ?string
        {
            if ($this->apiKey === '') {
                return null;
            }

            $cache = $this->playerRepo->getSteamAvatarCache($player);
            if ($cache === null) {
                return null;
            }

            if (time() - (int)$cache['avatarUpdated'] < self::CACHE_SECONDS) {
                return $cache['avatarUrl'];
            }

            // Normal mode stores Steam IDs as "Y:Z" (STEAM_X:Y:Z without the
            // universe); bots (BOT...) are never looked up. Callers must not
            // pass players from NameTrack/LAN mode, whose uniqueIds are names
            // or addresses. The account ID Z*2+Y must be a non-zero uint32.
            if (!preg_match('/^([01]):(0|[1-9]\d{0,9})$/', (string)$cache['uniqueId'], $m)) {
                return null;
            }

            $accountId = (int)$m[2] * 2 + (int)$m[1];
            if ($accountId === 0 || $accountId > 0xFFFFFFFF) {
                return null;
            }

            $avatarUrl = $this->fetchAvatarUrl(self::STEAM_ID64_BASE + $accountId);
            if ($avatarUrl === false) {
                // Keep the last known avatar and retry after the cache period.
                $avatarUrl = $cache['avatarUrl'];
            }

            $this->playerRepo->saveSteamAvatarCache($player, $avatarUrl, time());

            return $avatarUrl;
        }

        /**
         * Returns the avatar URL, null when the profile or avatar does not
         * exist, or false when the request failed.
         */
        private function fetchAvatarUrl(int $steamId) : string|null|false
        {
            $curl = curl_init('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?'
                . http_build_query(['key' => $this->apiKey, 'steamids' => $steamId]));
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);

            // Never log the request URL: it contains the API key.
            $body = curl_exec($curl);
            if ($body === false) {
                $this->logger->error("Steam avatar lookup for $steamId failed: " . curl_error($curl));
                return false;
            }

            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status !== 200) {
                $this->logger->error("Steam avatar lookup for $steamId failed: HTTP $status");
                return false;
            }

            $players = json_decode($body, true)['response']['players'] ?? null;
            if (!is_array($players)) {
                $this->logger->error("Steam avatar lookup for $steamId failed: unexpected response");
                return false;
            }

            $avatarUrl = $players[0]['avatarfull'] ?? null;

            return is_string($avatarUrl) && str_starts_with($avatarUrl, 'https://') && strlen($avatarUrl) <= 255
                ? $avatarUrl
                : null;
        }
    }
