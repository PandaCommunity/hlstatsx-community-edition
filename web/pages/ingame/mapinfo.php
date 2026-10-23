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

	// Map Details

	$map = valid_request($_GET['map'], false) or error('No map specified.');
	
	$db->query("SELECT name FROM hlstats_Games WHERE code='$game'");
	if ($db->num_rows() != 1) {
		error('Invalid or no game specified.');
	} else {
		list($gamename) = $db->fetch_row();
	}

	$table = new Table(
		array(
			new TableColumn(
				'killerName',
				'Player',
				'width=60&align=left&flag=1&link=' . urlencode('mode=statsme&amp;player=%k') 
			),
			new TableColumn(
				'frags',
				'Kills on $map',
				'width=15&align=right'
			),
			new TableColumn(
				'headshots',
				'Headshots',
				'width=15&align=right'
			),
			new TableColumn(
				'hpk',
				'Hpk',
				'width=5&align=right'
			),
		),
		'killerId', // keycol
		'frags', // sort_default
		'killerName', // sort_default2
		true, // showranking
		50 // numperpage
	);
	
	$result = $db->query("
		SELECT
			f.killerId,
			hlstats_Players.lastName AS killerName,
			hlstats_Players.flag as flag,
			f.frags,
			f.headshots,
			IFNULL(f.headshots / f.frags, '-') AS hpk
		FROM
			(SELECT killerId, COUNT(*) AS frags, SUM(headshot=1) AS headshots
			 FROM hlstats_Events_Frags WHERE map='$map' GROUP BY killerId) f
		JOIN hlstats_Players ON hlstats_Players.playerId = f.killerId
		WHERE
			hlstats_Players.game='$game'
			AND hlstats_Players.hideranking<>'1'
		ORDER BY
			$table->sort $table->sortorder,
			$table->sort2 $table->sortorder
		LIMIT $table->startitem,$table->numperpage
	");
	
	// Page counts follow visible players; headline totals still follow server game.
	$resultCount = $db->query("
		SELECT
			(SELECT COUNT(DISTINCT f.killerId)
			 FROM hlstats_Events_Frags f
			 JOIN hlstats_Players p ON p.playerId = f.killerId
			 WHERE f.map='$map' AND p.game='$game' AND p.hideranking<>1),
			SUM(hlstats_Events_Frags.map='$map')
		FROM
			hlstats_Events_Frags,
			hlstats_Servers
		WHERE
			hlstats_Servers.serverId = hlstats_Events_Frags.serverId
			AND hlstats_Events_Frags.map='$map'
			AND hlstats_Servers.game='$game'
	");
	
	list($numitems, $totalkills) = $db->fetch_row($resultCount);
?>


<?php
	$table->draw($result, $numitems, 100, 'center');
?>
