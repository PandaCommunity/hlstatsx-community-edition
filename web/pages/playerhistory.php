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

    // Player History
	$player = valid_request(intval($_GET['player']), true) or error('No player ID specified.');

	$db->query("
		SELECT
			hlstats_Players.lastName,
			hlstats_Players.game
		FROM
			hlstats_Players
		WHERE
			hlstats_Players.playerId = $player
	");

	if ($db->num_rows() != 1) {
		error("No such player '$player'.");
	}

	$playerdata = $db->fetch_array();
	$pl_name = $playerdata['lastName'];

    if (strlen($pl_name) > 10) {
		$pl_shortname = substr($pl_name, 0, 8) . '...';
	} else {
		$pl_shortname = $pl_name;
	}

	$pl_name = htmlspecialchars($pl_name, ENT_COMPAT);
	$pl_shortname = htmlspecialchars($pl_shortname, ENT_COMPAT);
	$game = $playerdata['game'];

    $db->query("
		SELECT
			hlstats_Games.name
		FROM
			hlstats_Games
		WHERE
			hlstats_Games.code = '$game'
	");

	if ($db->num_rows() != 1) {
		$gamename = ucfirst($game);
	} else {
		list($gamename) = $db->fetch_row();
	}

	pageHeader
	(
		array ($gamename, 'Event History', $pl_name),
		array
		(
			$gamename=>$g_options['scripturl'] . "?game=$game",
			'Player Rankings'=>$g_options['scripturl'] . "?mode=players&game=$game",
			'Player Details'=>$g_options['scripturl'] . "?mode=playerinfo&player=$player",
			'Event History'=>''
		),
		$playername = ""
	);
	flush();
	$table = new Table
	(
		array
		(
			new TableColumn
			(
				'eventTime',
				'Date',
				'width=20'
			),
			new TableColumn
			(
				'eventType',
				'Type',
				'width=10&align=center'
			),
			new TableColumn
			(
				'eventDesc',
				'Description',
				'width=40&sort=no&append=.&embedlink=yes'
			),
			new TableColumn
			(
				'serverName',
				'Server',
				'width=20'
			),
			new TableColumn
			(
				'map',
				'Map',
				'width=10'
			)
		),
		'eventTime',
		'eventTime',
		'eventType',
		false,
		50,
		'page',
		'sort',
		'sortorder'
	);
	$surl = $g_options['scripturl'];

	// Each direction is a separate branch, even when the same source row matches
	// both. The optional joins are needed for filtering or for descriptions only.
	$actionJoin = "JOIN hlstats_Actions a ON a.id = e.actionId AND a.game = '$game'";
	$teamJoin = "JOIN hlstats_Teams t ON t.code = e.team AND t.game = '$game'";
	$events = array
	(
		array('TeamBonuses', 'Team Bonus', "e.playerId = $player",
			"CONCAT('My team received a points bonus of ', e.bonus, ' for triggering \"', IFNULL(a.description,'Unknown'), '\"')", '', $actionJoin),
		array('Connects', 'Connect', "e.playerId = $player", "'I connected to the server'"),
		array('Disconnects', 'Disconnect', "e.playerId = $player", "'I left the game'"),
		array('Entries', 'Entry', "e.playerId = $player", "'I entered the game'"),
		array('Frags', 'Kill', "e.killerId = $player AND e.headshot = 0",
			"CONCAT('I killed %A%$surl?mode=playerinfo&player=', e.victimId, '%', IFNULL(p.lastName,'Unknown'), '%/A%', ' with ', e.weapon)", 'victimId'),
		array('Frags', 'Kill', "e.killerId = $player AND e.headshot = 1",
			"CONCAT('I killed %A%$surl?mode=playerinfo&player=', e.victimId, '%', IFNULL(p.lastName,'Unknown'), '%/A%', ' with a headshot from ', e.weapon)", 'victimId'),
		array('Frags', 'Death', "e.victimId = $player",
			"CONCAT('%A%$surl?mode=playerinfo&player=', e.killerId, '%', IFNULL(p.lastName,'Unknown'), '%/A%', ' killed me with ', e.weapon)", 'killerId'),
		array('Teamkills', 'Team Kill', "e.killerId = $player",
			"CONCAT('I killed teammate %A%$surl?mode=playerinfo&player=', e.victimId, '%', IFNULL(p.lastName,'Unknown'), '%/A%', ' with ', e.weapon)", 'victimId'),
		array('Teamkills', 'Friendly Fire', "e.victimId = $player",
			"CONCAT('My teammate %A%$surl?mode=playerinfo&player=', e.killerId, '%', IFNULL(p.lastName,'Unknown'), '%/A%', ' killed me with ', e.weapon)", 'killerId'),
		array('ChangeRole', 'Role', "e.playerId = $player", "CONCAT('I changed role to ', e.role)"),
		array('ChangeName', 'Name', "e.playerId = $player",
			"CONCAT('I changed my name from \"', e.oldName, '\" to \"', e.newName, '\"')"),
		array('PlayerActions', 'Action', "e.playerId = $player",
			"CONCAT('I received a points bonus of ', e.bonus, ' for triggering \"', IFNULL(a.description,'Unknown'), '\"')", '', $actionJoin),
		array('PlayerPlayerActions', 'Action', "e.playerId = $player",
			"CONCAT('I received a points bonus of ', e.bonus, ' for triggering \"', IFNULL(a.description,'Unknown'), '\" against %A%$surl?mode=playerinfo&player=', e.victimId, '%', IFNULL(p.lastName,'Unknown'), '%/A%')", 'victimId', $actionJoin),
		array('PlayerPlayerActions', 'Action', "e.victimId = $player",
			"CONCAT('%A%$surl?mode=playerinfo&player=', e.playerId, '%', IFNULL(p.lastName,'Unknown'), '%/A% triggered \"', IFNULL(a.description,'Unknown'), '\" against me')", 'playerId', $actionJoin),
		array('Suicides', 'Suicide', "e.playerId = $player", "CONCAT('I committed suicide with \"', e.weapon, '\"')"),
		array('ChangeTeam', 'Team', "e.playerId = $player",
			"IF(t.name IS NULL, CONCAT('I joined team \"', e.team, '\"'), CONCAT('I joined team \"', e.team, '\" (', t.name, ')'))", '', $teamJoin)
	);

	// Table validates the sort keys and direction. Use the SAME complete order
	// before each branch limit and after UNION ALL, including stable identity ties.
	// A row beyond offset + page size in one branch cannot reach the global page.
	$order = "$table->sort $table->sortorder, $table->sort2 $table->sortorder, branch ASC, eventId ASC";
	$candidateLimit = $table->startitem + $table->numperpage;
	$counts = array();
	$candidates = array();
	$serverJoin = 'LEFT JOIN hlstats_Servers s ON s.serverId = e.serverId';
	// Match the old temporary columns' configured collation, even if the source
	// tables still use a different charset/collation after a database upgrade.
	$serverName = "CONVERT(IFNULL(s.name, 'Unknown') USING " . DB_CHARSET . ') COLLATE ' . DB_COLLATE;
	$mapName = 'CONVERT(e.map USING ' . DB_CHARSET . ') COLLATE ' . DB_COLLATE;
	foreach ($events as $branch => $event)
	{
		list($source, $type, $where) = $event;
		$filterJoin = $event[5] ?? '';
		$from = "FROM hlstats_Events_$source e $filterJoin";
		$counts[] = "SELECT COUNT(*) AS n $from WHERE $where";
		$sortExpressions = array
		(
			'eventTime' => 'e.eventTime',
			'eventType' => "'$type' COLLATE " . DB_COLLATE,
			'serverName' => $serverName,
			'map' => $mapName
		);
		$keys = array();
		foreach (array($table->sort, $table->sort2) as $key)
		{
			$keys[] = $sortExpressions[$key] . " AS $key";
		}
		$sortJoin = $table->sort == 'serverName' ? $serverJoin : '';
		$candidates[] = '(SELECT ' . implode(', ', $keys) . ", $branch AS branch, e.id AS eventId
			$from $sortJoin WHERE $where ORDER BY $order LIMIT $candidateLimit)";
	}
	$resultCount = $db->query('SELECT SUM(n) FROM (' . implode(' UNION ALL ', $counts) . ') counts');
	list($numitems) = $db->fetch_row($resultCount);
	$page = $db->query('SELECT branch, eventId FROM (' . implode(' UNION ALL ', $candidates) . ") candidates
		ORDER BY $order LIMIT $table->startitem, $table->numperpage");
	$pageIds = array();
	while ($row = $db->fetch_row($page))
	{
		$pageIds[(int) $row[0]][] = (int) $row[1];
	}
	$db->free_result($page);

	// Only the selected page gets player/server description joins and CONCATs.
	// No named temporary table (or DROP TABLE) is needed, even on persistent
	// connections. Sort nullable timestamps natively above, then display NULL as
	// blank rather than inserting it into the old NOT NULL temporary column.
	$details = array();
	foreach ($events as $branch => $event)
	{
		list($source, $type, $where, $description) = $event;
		$playerColumn = $event[4] ?? '';
		$playerJoin = $playerColumn ? "LEFT JOIN hlstats_Players p ON p.playerId = e.$playerColumn" : '';
		$filterJoin = $event[5] ?? '';
		$ids = implode(',', $pageIds[$branch] ?? array('NULL'));
		$details[] = "SELECT IFNULL(e.eventTime, '') AS eventTime, '$type' COLLATE " . DB_COLLATE . " AS eventType,
			LEFT($description, 255) AS eventDesc, $serverName AS serverName,
			$mapName AS map, $branch AS branch, e.id AS eventId
			FROM hlstats_Events_$source e $filterJoin $serverJoin $playerJoin
			WHERE e.id IN ($ids) AND $where";
	}
	$result = $db->query(implode(' UNION ALL ', $details) . " ORDER BY $order");
?>

<div class="block">
<?php
	printSectionTitle('Player Event History (Last '.$g_options['DeleteDays'].' Days)');
	if ($numitems > 0)
	{
		$table->draw($result, $numitems, 95);
	}
?><br /><br />
	<div class="subblock">
		<div style="float:right;">
			Go to: <a href="<?php echo $g_options['scripturl'] . "?mode=playerinfo&amp;player=$player"; ?>"><?php echo $pl_name; ?>'s Statistics</a>
		</div>
	</div>
</div>
