<?php

    namespace Repository;

    use PDO;
    use PDOException;

    /**
     * The single hlstats_SteamAvatarGate row that every web server shares to
     * decide who may call Steam next. All times are Unix times from the
     * database clock. Methods throw PDOException.
     */
    class SteamAvatarGateRepository
    {
        private PDO $pdo;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        /**
         * Bounds lock waits and statement run time on this connection, or
         * resets them to the server defaults. MariaDB and MySQL name the
         * statement limit differently, so the unknown one is skipped.
         */
        public function limitSqlTime(bool $limit) : void
        {
            $settings = [
                'lock_wait_timeout' => 2,  // MyISAM table locks
                'innodb_lock_wait_timeout' => 2,  // the gate row
                'max_statement_time' => 2,  // MariaDB, seconds
                'max_execution_time' => 2000,  // MySQL, milliseconds (SELECT only)
            ];

            foreach ($settings as $name => $value) {
                try {
                    $this->pdo->exec("SET SESSION $name = " . ($limit ? $value : 'DEFAULT'));
                } catch (PDOException) {
                    // Not a variable on this server.
                }
            }
        }

        /**
         * Cheap non-locking check so most requests skip the claim UPDATE.
         */
        public function isOpen() : bool
        {
            return (bool)$this->pdo->query("
                SELECT
                    next_allowed_at <= UNIX_TIMESTAMP() AND lease_until <= UNIX_TIMESTAMP()
                FROM
                    hlstats_SteamAvatarGate
                WHERE
                    id = 1
            ")->fetchColumn();
        }

        /**
         * Atomically claims the next attempt: only one caller changes the row
         * while it is open. The claim moves next_allowed_at forward by
         * $interval whatever the attempt's outcome, and holds a lease for
         * $lease seconds that expires by itself if the worker dies. Returns
         * the consecutive failure count and total Steam calls so far, or null
         * when another caller won.
         */
        public function claim(string $owner, int $interval, int $lease) : ?array
        {
            $stmt = $this->pdo->prepare("
                UPDATE
                    hlstats_SteamAvatarGate
                SET
                    next_allowed_at = UNIX_TIMESTAMP() + :interval,
                    lease_owner = :owner,
                    lease_until = UNIX_TIMESTAMP() + :lease,
                    last_attempt_at = UNIX_TIMESTAMP()
                WHERE
                    id = 1
                    AND next_allowed_at <= UNIX_TIMESTAMP()
                    AND lease_until <= UNIX_TIMESTAMP()
            ");
            $stmt->bindValue(':interval', $interval, PDO::PARAM_INT);
            $stmt->bindValue(':owner', $owner, PDO::PARAM_STR);
            $stmt->bindValue(':lease', $lease, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() !== 1) {
                return null;
            }

            return $this->pdo->query("SELECT failures, calls FROM hlstats_SteamAvatarGate WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        }

        /**
         * Records the attempt's outcome and releases the lease if $owner still
         * holds it. $called says whether Steam was called and $succeeded
         * whether that call worked; a failure counts towards the backoff. A
         * $delay pushes the next attempt to at least that many seconds from
         * now, even when the lease has expired meanwhile.
         */
        public function finish(string $owner, string $status, bool $called, bool $succeeded, int $delay) : void
        {
            // MySQL applies SET assignments left to right, so the lease_owner
            // comparisons must come before lease_owner is cleared.
            $stmt = $this->pdo->prepare("
                UPDATE
                    hlstats_SteamAvatarGate
                SET
                    lease_until = IF(lease_owner = :owner1, 0, lease_until),
                    lease_owner = IF(lease_owner = :owner2, '', lease_owner),
                    next_allowed_at = GREATEST(next_allowed_at, UNIX_TIMESTAMP() + :delay),
                    last_status = :status,
                    calls = calls + :called,
                    failures = IF(:called2, IF(:succeeded, 0, failures + 1), failures),
                    last_success_at = IF(:succeeded2, UNIX_TIMESTAMP(), last_success_at)
                WHERE
                    id = 1
            ");
            $stmt->bindValue(':owner1', $owner, PDO::PARAM_STR);
            $stmt->bindValue(':owner2', $owner, PDO::PARAM_STR);
            $stmt->bindValue(':delay', $delay, PDO::PARAM_INT);
            $stmt->bindValue(':status', substr($status, 0, 64), PDO::PARAM_STR);
            $stmt->bindValue(':called', (int)$called, PDO::PARAM_INT);
            $stmt->bindValue(':called2', (int)$called, PDO::PARAM_INT);
            $stmt->bindValue(':succeeded', (int)$succeeded, PDO::PARAM_INT);
            $stmt->bindValue(':succeeded2', (int)$succeeded, PDO::PARAM_INT);
            $stmt->execute();
        }
    }
