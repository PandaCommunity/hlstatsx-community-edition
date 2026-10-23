<?php
/*
	HLstatsX Community Edition - Real-time player and clan rankings and statistics
	Copyleft (L) 2008-20XX Nicholas Hastings (nshastings@gmail.com)
	http://www.hlxcommunity.com

	HLstatsX Community Edition is a continuation of 
	ELstatsNEO - Real-time player and clan rankings and statistics
	Copyleft (L) 2008-20XX Malte Bayer (steam@neo-soft.org)
	http://ovrsized.neo-soft.org/

	ELstatsNEO is an very improved & enhanced - so called Ultra-Humongus Edition of HLstatsX
	HLstatsX - Real-time player and clan rankings and statistics for Half-Life 2
	http://www.hlstatsx.com/
	Copyright (C) 2005-2007 Tobias Oetzel (Tobi@hlstatsx.com)

	HLstatsX is an enhanced version of HLstats made by Simon Garner
	HLstats - Real-time player and clan rankings and statistics for Half-Life
	http://sourceforge.net/projects/hlstats/
	Copyright (C) 2001  Simon Garner
				
	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

	For support and installation notes visit http://www.hlxcommunity.com
*/

	if (!defined('IN_HLSTATS')) {
		die('Do not access this file directly.');
	}

    use Config\DatabaseOptions;
    use Database\PDODriver;
    use Repository\GameRepository;
    use Utils\Logger;

    $logger = new Logger();

    // Create db class
    $dbOptions = new DatabaseOptions([
        'host'     => DB_ADDR,
        'user'     => DB_USER,
        'pass'     => DB_PASS,
        'name'     => DB_NAME,
        'pconnect' => DB_PCONNECT,
        'charset'  => DB_CHARSET,
    ]);

    $pdoClass = new PDODriver($dbOptions, $logger);
    $pdo = $pdoClass->getPDO();

    $gameRepo = new GameRepository($pdo, $logger);
    $allowedGames = $gameRepo->getGameCodes();

	$checkGame = isset($game) ? $game : '';
	$errorMsg = "";
	if (!checkValidGame($checkGame, $allowedGames, $errorMsg)) {
		error("{$errorMsg}");
		die("{$errorMsg}");
	}

	// Global Server Chat History
	$showserver = filter_input(INPUT_GET, 'server_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);
	if ($showserver === false) {
		$showserver = 0;
	}

	$showserver = (int)$showserver;

	$gameSafeHtml = htmlspecialchars($checkGame, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$scriptUrlSafeHtml = eHtml($g_options['scripturl']);
	$fullUrl = $g_options['scripturl'] . '?game=' . urlencode($checkGame);

	$gamename = getGameNameByCode($db, $checkGame);
	if ($gamename === false) {
		error("No such game '{$gameSafeHtml}'.");
	}

	pageHeader
	(
		array ($gamename, 'Server Chat Statistics'),
		array ($gamename => "%s?game={$gameSafeHtml}", 'Server Chat Statistics' => '')
	);

	$servername = "(All Servers)";
	if ($showserver != 0) {
		$servername = getServerNameById($db, $showserver);
		$servername = ($servername !== null) ? "({$servername})" : "(Unknown Server)";
	}

	$delaySql = "";
	$delayChat = (int)CHAT_DELAY_TIME;

	if ($delayChat > 0 && $delayChat <= CHAT_DELAY_MAX_TIME) {
		$delayChatSafeSql = $db->escape($delayChat);
		$delaySql = "AND `eventTime` <= (NOW() - INTERVAL {$delayChatSafeSql} MINUTE)";
	}

	$serversList = getServersByGame($db, $checkGame);
	$filter = getChatFilterParam();

	$deleteDaysSafe = isset($g_options['DeleteDays']) ? (int)$g_options['DeleteDays'] : 30;
	$pageTitle = sprintf('%s %s Server Chat Log (Last %d Days)', $gamename, $servername, $deleteDaysSafe);

	$columns = getChatColumns($showserver);
	$table = new Table(
		$columns,
		'playerId',
		'eventTime',
		'lastName',
		false,
		50,
		"page",
		"sort",
		"sortorder"
	);

	$whereclause2 = buildSearchSqlSafe($db, $filter);

	// Only Date is sortable on this page. Select a bounded, narrow prefix from
	// each eligible server before merging, so other games and display columns
	// are not scanned/sorted for every page. Offsets remain supported.
	$serverIds = [];
	foreach ($serversList as $server) {
		$serverId = (int)$server['serverId'];
		if ($showserver != 0 && $showserver != $serverId) {
			continue;
		}
		$serverIds[] = $serverId;
	}
	// An unknown server or a server belonging to another game has no rows.
	$whereclause = $serverIds
		? 'hlstats_Events_Chat.serverId IN (' . implode(',', $serverIds) . ')'
		: '0 = 1';
	$playerExists = "EXISTS (SELECT 1 FROM hlstats_Players
		WHERE hlstats_Players.playerId = hlstats_Events_Chat.playerId)";
	$sqlCount = "SELECT count(*) FROM hlstats_Events_Chat
		WHERE {$whereclause} AND {$playerExists} {$whereclause2} {$delaySql}";
	$db->query($sqlCount);
	list($numitems) = $db->fetch_row();
	$db->free_result();

	$candidateScopes = [$whereclause];
	// Long searches use boolean FULLTEXT in buildSearchSqlSafe. Run that search
	// once, not once per server; its matches still need a date sort. Short LIKE
	// searches cannot use FULLTEXT without changing their meaning. For deep
	// offsets, avoid merging prefixes as large as the entire matching set.
	if (count($serverIds) > 1 && mb_strlen($filter, 'UTF-8') <= 3
		&& ($table->startitem + $table->numperpage) * count($serverIds) < $numitems) {
		$candidateScopes = [];
		foreach ($serverIds as $serverId) {
			$candidateScopes[] = "serverId = {$serverId}";
		}
	}
	$candidateLimit = count($candidateScopes) === 1
		? "{$table->startitem}, {$table->numperpage}"
		: (string)($table->startitem + $table->numperpage);
	$candidates = [];
	foreach ($candidateScopes as $candidateScope) {
		$candidates[] = "SELECT id, eventTime FROM hlstats_Events_Chat
			WHERE {$candidateScope} AND {$playerExists}
			{$whereclause2} {$delaySql}
			ORDER BY eventTime {$table->sortorder}, id {$table->sortorder}
			LIMIT {$candidateLimit}";
	}
	$pageSql = $candidates[0];
	if (count($candidates) > 1) {
		$candidateSql = '(' . implode(') UNION ALL (', $candidates) . ')';
		$pageSql = "SELECT id, eventTime FROM ({$candidateSql}) AS candidates
			ORDER BY eventTime {$table->sortorder}, id {$table->sortorder}
			LIMIT {$table->startitem}, {$table->numperpage}";
	}

	$selectSql = "
		SELECT SQL_NO_CACHE
			hlstats_Events_Chat.eventTime,
			hlstats_Players.lastName as lastName,
			IF(hlstats_Events_Chat.message_mode=2, CONCAT('(Team) ', hlstats_Events_Chat.message), 
			IF(hlstats_Events_Chat.message_mode=3, CONCAT('(Squad) ', hlstats_Events_Chat.message), hlstats_Events_Chat.message)) AS message,
			hlstats_Servers.name AS serverName,
			hlstats_Events_Chat.playerId,
			hlstats_Players.flag,
			hlstats_Events_Chat.map
		FROM
			({$pageSql}) AS page
		INNER JOIN hlstats_Events_Chat ON hlstats_Events_Chat.id = page.id
		INNER JOIN hlstats_Players ON hlstats_Players.playerId = hlstats_Events_Chat.playerId
		INNER JOIN hlstats_Servers ON hlstats_Servers.serverId = hlstats_Events_Chat.serverId
		ORDER BY page.eventTime {$table->sortorder}, page.id {$table->sortorder}
	";

	$resultMsgs = $db->query($selectSql, true, false);

	// Functions
	// Old limit 50
	function getGameNameByCode($db, $game)
	{
		$gameEscape = $db->escape($game);
		$sqlGames = "
			SELECT
				hlstats_Games.name
			FROM
				hlstats_Games
			WHERE
				hlstats_Games.code = '{$gameEscape}'
		";

		$dbResult = $db->query($sqlGames);

		if (!$dbResult ||$db->num_rows($dbResult) < 1) {
			return null;
		}

		list($name) = $db->fetch_row($dbResult);
		$db->free_result();

		return $name;
	}

	function getServersByGame($db, $game, $limit = 0)
	{
		$sqlLimit = "";
		$limit = (int)$limit;
		if ($limit > 0) {
			$sqlLimit = "LIMIT {$limit}";
		}

		$game = $db->escape($game);
		$serversSql = "
			SELECT
				hlstats_Servers.serverId,
				hlstats_Servers.name
			FROM
				hlstats_Servers
			WHERE
				hlstats_Servers.game = '{$game}'
			ORDER BY
				hlstats_Servers.sortorder,
				hlstats_Servers.name,
				hlstats_Servers.serverId ASC
			{$sqlLimit}
		";

		$servers = [];
		$dbResult = $db->query($serversSql);
		if (!$dbResult) {
			return $servers;
		}

		while ($row = $db->fetch_array($dbResult)) {
			$servers[] = [
				'serverId' => (int)$row['serverId'],
				'name'     => $row['name']
			];
		}

		$db->free_result($dbResult);
		return $servers;
	}

	function getServerNameById($db, $showServerId)
	{
		$showServerId = (int)$showServerId;
		$serversSql = "
			SELECT
				hlstats_Servers.name
			FROM
				hlstats_Servers
			WHERE
				hlstats_Servers.serverId = {$showServerId}
		";

		$dbResult = $db->query($serversSql);
		if ($dbResult && $db->num_rows($dbResult) > 0) {
			$row = $db->fetch_array($dbResult);

			return $row['name'];
		}

		return null;
	}

	function getChatColumns($showserver) {
		if ($showserver == 0) {
			return [
				new TableColumn('eventTime', 'Date', 'width=16'),
				new TableColumn('lastName', 'Player', 'width=17&sort=no&flag=1&link=' . urlencode('mode=playerinfo&amp;player=%k')),
				new TableColumn('message', 'Message', 'width=34&sort=no&embedlink=yes'),
				new TableColumn('serverName', 'Server', 'width=23&sort=no'),
				new TableColumn('map', 'Map', 'width=10&sort=no')
			];
		}

		return [
			new TableColumn('eventTime', 'Date', 'width=16'),
			new TableColumn('lastName', 'Player', 'width=24&sort=no&flag=1&link=' . urlencode('mode=playerinfo&amp;player=%k')),
			new TableColumn('message', 'Message', 'width=44&sort=no&embedlink=yes'),
			new TableColumn('map', 'Map', 'width=16&sort=no')
		];
	}
?>

<div class="block">
	<span class="fHeading">
		&nbsp;<img src="<?=TITLE_IMAGE;?>" alt="">
		&nbsp;<?=eHtml($pageTitle);?>
	</span>
	<br><br>

	<div class="subblock">
		<div style="float:left;">
			<span>
				<form method="get" action="<?=eHtml($g_options['scripturl']);?>" style="margin:0px;padding:0px;">
					<input type="hidden" name="mode" value="chat" />
					<input type="hidden" name="game" value="<?=$gameSafeHtml;?>">

					<strong>&#8226;</strong> Show Chat from

					<select name="server_id">
						<option value="0">All Servers</option>

						<?php foreach($serversList as $srv) : ?>
							<?php $selected = ($showserver == $srv['serverId']) ? 'selected' : ''; ?>

							<option value="<?=eHtml($srv['serverId']);?>" <?=$selected;?>>
								<?=eHtml($srv['name']);?>
							</option>
						<?php endforeach; ?>
					</select>

					Filter: <input type="text" name="filter" value="<?=eHtml($filter);?>"> 

					<input type="submit" value="View" class="smallsubmit">
					<input type="button" value="Clear" class="smallsubmit" onclick="window.location.href='?mode=chat&game=<?= urlencode($checkGame); ?>';">
				</form>
			</span>

			<?php if (!empty($delaySql)) : ?>
				<div style="font-size:0.9em; color:#8d90a3; margin-top:10px;">
					*Messages are delayed by <?=eHtml($delayChat);?> minutes to prevent real-time tracking.
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div style="clear:both;padding-top:10px;"></div>

	<?php $table->draw($resultMsgs, $numitems, 95);?>
	<br><br>

	<div class="subblock">
		<div style="float:right;">
			Go to: <a href="<?=eHtml($fullUrl);?>"><?=eHtml($gamename);?></a>
		</div>
	</div>
</div>
