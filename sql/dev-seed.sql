-- Development-only sample data for local and orb environments.
-- Import after sql/install.sql. Do not use this on a production installation.
-- Uses MariaDB's sequence engine for the hourly history rows.

INSERT IGNORE INTO `hlstats_Servers`
  (`serverId`, `address`, `port`, `name`, `sortorder`, `game`, `publicaddress`,
   `kills`, `players`, `rounds`, `suicides`, `headshots`, `act_players`,
   `max_players`, `act_map`, `last_event`)
VALUES
  (1, '127.0.0.1', 27015, 'Orb Dev TF2 Server', 1, 'tf', '',
   240, 3, 12, 4, 35, 3, 24, 'cp_badlands', UNIX_TIMESTAMP());

INSERT IGNORE INTO `hlstats_Players`
  (`playerId`, `last_event`, `connection_time`, `lastName`, `lastAddress`,
   `country`, `flag`, `kills`, `deaths`, `suicides`, `skill`, `shots`, `hits`,
   `game`, `hideranking`, `headshots`, `last_skill_change`, `createdate`)
VALUES
  (1, UNIX_TIMESTAMP(), 7200, 'Alice', '192.0.2.11', 'United States', 'us',
   120, 40, 1, 1250, 900, 320, 'tf', 0, 24, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() - 2592000),
  (2, UNIX_TIMESTAMP(), 5400, 'Bob', '192.0.2.12', 'Germany', 'de',
   80, 60, 2, 1100, 700, 240, 'tf', 0, 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() - 1728000),
  (3, UNIX_TIMESTAMP(), 3600, 'Carol', '192.0.2.13', 'Canada', 'ca',
   40, 80, 1, 1000, 500, 150, 'tf', 0, 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() - 864000);

INSERT IGNORE INTO `hlstats_PlayerNames`
  (`playerId`, `name`, `lastuse`, `connection_time`, `numuses`,
   `kills`, `deaths`, `suicides`, `headshots`, `shots`, `hits`)
VALUES
  (1, 'Alice', NOW(), 7200, 4, 120, 40, 1, 24, 900, 320),
  (2, 'Bob', NOW(), 5400, 3, 80, 60, 2, 10, 700, 240),
  (3, 'Carol', NOW(), 3600, 2, 40, 80, 1, 5, 500, 150);

INSERT IGNORE INTO `hlstats_Livestats`
  (`player_id`, `server_id`, `cli_address`, `cli_country`, `cli_flag`, `name`,
   `team`, `kills`, `deaths`, `headshots`, `shots`, `hits`, `ping`,
   `connected`, `skill_change`, `skill`)
VALUES
  (1, 1, '192.0.2.11', 'United States', 'us', 'Alice', 'red', 12, 4, 3, 90, 32, 42, UNIX_TIMESTAMP() - 1800, 2, 1250),
  (2, 1, '192.0.2.12', 'Germany', 'de', 'Bob', 'blue', 8, 6, 1, 70, 24, 55, UNIX_TIMESTAMP() - 1500, -2, 1100),
  (3, 1, '192.0.2.13', 'Canada', 'ca', 'Carol', 'blue', 4, 8, 0, 50, 15, 38, UNIX_TIMESTAMP() - 1200, 0, 1000);

-- One baseline row older than 24 hours plus 24 hourly rows for the load graph
-- and the "last 24h" counters.
INSERT INTO `hlstats_Trend`
  (`timestamp`, `game`, `players`, `kills`, `headshots`, `servers`, `act_slots`, `max_slots`)
VALUES
  (UNIX_TIMESTAMP() - 90000, 'tf', 1, 50, 8, 1, 1, 24);

INSERT INTO `hlstats_Trend`
  (`timestamp`, `game`, `players`, `kills`, `headshots`, `servers`, `act_slots`, `max_slots`)
SELECT UNIX_TIMESTAMP() - (23 - seq) * 3600, 'tf', 1 + (seq % 4), 80 + seq * 4, 10 + (seq % 6), 1, 1 + (seq % 4), 24
FROM seq_0_to_23;

-- Server load history for the graph on the game page.
INSERT INTO `hlstats_server_load`
  (`server_id`, `timestamp`, `act_players`, `min_players`, `max_players`, `map`, `uptime`, `fps`)
VALUES
  (1, UNIX_TIMESTAMP() - 90000, 1, 0, 24, 'cp_badlands', '3600', '60');

INSERT INTO `hlstats_server_load`
  (`server_id`, `timestamp`, `act_players`, `min_players`, `max_players`, `map`, `uptime`, `fps`)
SELECT 1, UNIX_TIMESTAMP() - (23 - seq) * 3600, 1 + (seq % 4), 0, 24, 'cp_badlands', '3600', '60'
FROM seq_0_to_23;

INSERT INTO `hlstats_Events_Frags`
  (`eventTime`, `serverId`, `map`, `killerId`, `victimId`, `weapon`,
   `headshot`, `killerRole`, `victimRole`)
VALUES
  (NOW() - INTERVAL 2 MINUTE, 1, 'cp_badlands', 1, 2, 'scattergun', 0, 'scout', 'soldier'),
  (NOW() - INTERVAL 3 MINUTE, 2, 'cp_badlands', 2, 3, 'tf_projectile_rocket', 0, 'soldier', 'medic'),
  (NOW() - INTERVAL 4 MINUTE, 1, 'cp_badlands', 1, 3, 'scattergun', 1, 'scout', 'medic'),
  (NOW() - INTERVAL 6 MINUTE, 3, 'cp_badlands', 3, 2, 'syringegun_medic', 0, 'medic', 'soldier'),
  (NOW() - INTERVAL 8 MINUTE, 2, 'cp_badlands', 2, 1, 'tf_projectile_rocket', 0, 'soldier', 'scout');

INSERT INTO `hlstats_Events_Connects`
  (`eventTime`, `serverId`, `map`, `playerId`, `ipAddress`, `hostname`,
   `hostgroup`, `eventTime_Disconnect`)
VALUES
  (NOW() - INTERVAL 1 HOUR, 1, 'cp_badlands', 1, '192.0.2.11', '', '', NOW() - INTERVAL 30 MINUTE),
  (NOW() - INTERVAL 55 MINUTE, 1, 'cp_badlands', 2, '192.0.2.12', '', '', NOW() - INTERVAL 25 MINUTE),
  (NOW() - INTERVAL 50 MINUTE, 1, 'cp_badlands', 3, '192.0.2.13', '', '', NOW() - INTERVAL 20 MINUTE);

INSERT INTO `hlstats_Events_Chat`
  (`eventTime`, `serverId`, `map`, `playerId`, `message_mode`, `message`)
VALUES
  (NOW() - INTERVAL 10 MINUTE, 1, 'cp_badlands', 1, 0, 'gg'),
  (NOW() - INTERVAL 9 MINUTE, 1, 'cp_badlands', 2, 0, 'nice shot'),
  (NOW() - INTERVAL 8 MINUTE, 1, 'cp_badlands', 3, 0, 'one more round');

-- Development environments have no Google Maps API key, so hide that panel.
UPDATE `hlstats_Options` SET `value` = '0' WHERE `keyname` = 'show_google_map';
