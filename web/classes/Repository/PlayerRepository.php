<?php
	namespace Repository;

	use PDO;
    use PDOException;
	use Utils\Logger;
    use Service\OptionService;

	class PlayerRepository
	{
		private PDO $pdo;
		private Logger $logger;
		private OptionService $optionService;

		public function __construct(PDO $pdo, Logger $logger, OptionService $optionService)
		{
			$this->pdo = $pdo;
			$this->logger = $logger;
            $this->optionService = $optionService;
		}

        public function getPlayerSkillHistory(int $player, int $limit = 30) : ?array
        {
            if ($player < 1) {
                $this->logger->error("Invalid player index {$player} passed to function getPlayerSkillHistory.");
                return null;
            }

            $limit = max(1, $limit);

            $sql = "
                SELECT
                    UNIX_TIMESTAMP(eventTime)
                AS
                    ts, skill, skill_change
                FROM
                    hlstats_Players_History
                WHERE
                    playerId = :player
                ORDER BY eventTime DESC
                LIMIT :limit
            ";

            try {
                $stmt = $this->pdo->prepare($sql);

                $stmt->bindValue(':player', $player, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $this->logger->error('Failed to get player skill history: ' . $e->getMessage());
                return null;
            }
        }

        /**
         * Returns up to $limit players whose last game event is within the last
         * $activeWithin seconds but more than $activeAfter seconds ago (0: any
         * time since, including future timestamps), and whose avatar was last
         * refreshed more than $refreshAfter seconds ago, most recently active
         * first, as rows of playerId, blockavatar and uniqueId (one row per
         * uniqueId; null when the player has none). Walks the (last_event,
         * avatarUpdated, playerId) index backwards and stops after $limit due
         * players, so it reads at most that window's index entries.
         * Throws PDOException.
         */
        public function getSteamAvatarCandidates(int $activeWithin, int $activeAfter, int $refreshAfter, int $limit) : array
        {
            $sql = "
                SELECT
                    due.playerId,
                    hlstats_Players.blockavatar,
                    hlstats_PlayerUniqueIds.uniqueId
                FROM
                    (
                        SELECT
                            playerId,
                            last_event
                        FROM
                            hlstats_Players
                        WHERE
                            last_event >= UNIX_TIMESTAMP() - :activeWithin
                            " . ($activeAfter > 0 ? 'AND last_event < UNIX_TIMESTAMP() - :activeAfter' : '') . "
                            AND avatarUpdated < UNIX_TIMESTAMP() - :refreshAfter
                        ORDER BY
                            last_event DESC
                        LIMIT :limit
                    ) AS due
                INNER JOIN
                    hlstats_Players
                ON
                    hlstats_Players.playerId = due.playerId
                LEFT JOIN
                    hlstats_PlayerUniqueIds
                ON
                    hlstats_PlayerUniqueIds.playerId = due.playerId
                ORDER BY
                    due.last_event DESC,
                    due.playerId
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':activeWithin', $activeWithin, PDO::PARAM_INT);
            if ($activeAfter > 0) {
                $stmt->bindValue(':activeAfter', $activeAfter, PDO::PARAM_INT);
            }
            $stmt->bindValue(':refreshAfter', $refreshAfter, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * Records a Steam lookup for every player with this uniqueId, in every
         * game. False keeps each player's cached URL. Throws PDOException.
         */
        public function saveSteamAvatar(string $uniqueId, string|null|false $avatarUrl) : void
        {
            $sql = "
                UPDATE
                    hlstats_Players
                INNER JOIN
                    hlstats_PlayerUniqueIds
                ON
                    hlstats_PlayerUniqueIds.playerId = hlstats_Players.playerId
                SET
                    hlstats_Players.avatarUrl = IF(:keep, hlstats_Players.avatarUrl, :avatarUrl),
                    hlstats_Players.avatarUpdated = UNIX_TIMESTAMP()
                WHERE
                    hlstats_PlayerUniqueIds.uniqueId = :uniqueId
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':keep', $avatarUrl === false ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':avatarUrl', $avatarUrl ?: null, is_string($avatarUrl) ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':uniqueId', $uniqueId, PDO::PARAM_STR);
            $stmt->execute();
        }

        /**
         * Marks players that cannot be looked up (bots, invalid IDs, blocked
         * avatars) as checked now, so they leave the refresh queue for a
         * refresh period. Throws PDOException.
         */
        public function markSteamAvatarsChecked(array $playerIds) : void
        {
            if ($playerIds === []) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
            $stmt = $this->pdo->prepare("UPDATE hlstats_Players SET avatarUpdated = UNIX_TIMESTAMP() WHERE playerId IN ($placeholders)");
            $stmt->execute(array_map('intval', array_values($playerIds)));
        }

		public function getPlayerSuggestions(string $game, string $search, int $limit = 30) : ?array
		{
			$limit = max(1, $limit);

			$sql = "
				SELECT DISTINCT
					hlstats_PlayerNames.name 
				FROM 
					hlstats_PlayerNames 
				INNER JOIN 
					hlstats_Players 
				ON 
					hlstats_PlayerNames.playerId = hlstats_Players.playerId 
				WHERE 
					game = :game
				AND 
					name LIKE :search
				LIMIT :limit
			";

			try {
				$stmt = $this->pdo->prepare($sql);

                $stmt->bindValue(':game', $game, PDO::PARAM_STR);
                $stmt->bindValue(':search', $search . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_COLUMN);
			} catch (PDOException $e) {
				$this->logger->error('PDO Exception in getPlayerSuggestions: ' . $e->getMessage());
				return null;
			}
		}

        public function getPlayerRank(string $game, string $rankingType, int $playerPoints, int $playerKills, int $playerDeaths) : ?int
        {
            $allowedRankingType = $this->optionService->getRankingTypeChoices();
            if (empty($allowedRankingType)) {
                $allowedRankingType = ['kills', 'skill'];
            }

            if (!in_array($rankingType, $allowedRankingType, true)) {
                return null;
            }

			$tempDeaths = $playerDeaths ?: 1;
            $kpd = $playerKills / $tempDeaths;

            $sql = "
                SELECT
                    COUNT(*) + 1
                FROM
                    hlstats_Players
                WHERE
                    game = :game
                AND 
                    hideranking = 0
                AND 
                    kills >= 1
                AND (
                    {$rankingType} > :points1
                    OR (
                        {$rankingType} = :points2
                        AND (kills / IF(deaths = 0, 1, deaths) > :kpd)
                    )
                )
            ";

            try {
                $stmt = $this->pdo->prepare($sql);

                $stmt->execute([
                    'game'  => $game,
                    'points1' => $playerPoints,
                    'points2' => $playerPoints,
                    'kpd'   => $kpd,
                ]);

                $count = $stmt->fetchColumn();
                if ($count === false) {
                    return null;
                }

                return (int)$count;
            } catch (PDOException $e) {
                $this->logger->error("Failed to get player rank: " . $e->getMessage());
                return null;
            }
        }
	}
