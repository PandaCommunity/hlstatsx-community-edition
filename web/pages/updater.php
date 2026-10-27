<?php

	if (!defined('IN_HLSTATS')) {
		die('Do not access this file directly.');
	}
	
	if (!file_exists("./updater")) {
		die('Updater directory is missing.');
	}
	
	define('IN_UPDATER', true);
	
	pageHeader
	(
		array ($gamename, 'Updater')
	);
	echo "<div class=\"warning\">\n" .
	"<span id=\"warning-header\"><strong>HLX:CE Database Updater log</span></strong><br /><br />\n";
	require_once './updater/functions.php';
	$startingVersion = (int) $g_options['dbversion'];
	if ($startingVersion < 82) {
		hlstatsUpgradeStop('This fork upgrades working version-82 installations. Stage older installations to a verified version-82 schema first; see sql/migrations/README.md.');
	}
	echo "Currently on database version $startingVersion<br />\n";
	$upgradeLock = 'hlstats:upgrade:' . md5($db->db_name);
	$result = $db->query("SELECT GET_LOCK('$upgradeLock', 0)");
	if ($db->fetch_row($result)[0] != 1) {
		hlstatsUpgradeStop('Another database upgrade is running.');
	}
	try {
		if ($startingVersion < 89) {
			echo "Checking schema, indexes and collation conflicts before changing the database...<br />\n";
			$upgradeTables = hlstatsUpgradePreflight($db, $startingVersion);
		}
		$i = $startingVersion + 1;
		while (file_exists("./updater/$i.php")) {
			echo "<br /><em>Running database update $i</em><br />\n";
			include("./updater/$i.php");
			echo "<em>Database update for DB Version $i complete.</em><br />";
			$i++;
		}
		if ($i == $startingVersion + 1) {
			echo "<strong>Your database is already up to date ($startingVersion)</strong>\n";
		} else {
			echo "<br /><strong>Successfully updated to database version " . ($i - 1) . "!</strong>\n";
		}
	} finally {
		$db->query("SELECT RELEASE_LOCK('$upgradeLock')");
	}
	
	echo "<br /><br /><img src=\"".IMAGE_PATH."/warning.gif\" alt=\"Warning\"> <span class=\"warning-header\">You <strong>must delete</strong> the \"updater\" folder from your web site before your site will be operational.</span>\n</div>\n";
?>
