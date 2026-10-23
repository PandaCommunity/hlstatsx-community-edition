#!/usr/bin/perl
# HLstatsX Community Edition - Real-time player and clan rankings and statistics
# Copyleft (L) 2008-20XX Nicholas Hastings (nshastings@gmail.com)
# http://www.hlxcommunity.com
#
# HLstatsX Community Edition is a continuation of 
# ELstatsNEO - Real-time player and clan rankings and statistics
# Copyleft (L) 2008-20XX Malte Bayer (steam@neo-soft.org)
# http://ovrsized.neo-soft.org/
# 
# ELstatsNEO is an very improved & enhanced - so called Ultra-Humongus Edition of HLstatsX
# HLstatsX - Real-time player and clan rankings and statistics for Half-Life 2
# http://www.hlstatsx.com/
# Copyright (C) 2005-2007 Tobias Oetzel (Tobi@hlstatsx.com)
#
# HLstatsX is an enhanced version of HLstats made by Simon Garner
# HLstats - Real-time player and clan rankings and statistics for Half-Life
# http://sourceforge.net/projects/hlstats/
# Copyright (C) 2001  Simon Garner
#             
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
# 
# For support and installation notes visit http://www.hlxcommunity.com


##
## Settings
##

# $opt_configfile - Absolute path and filename of configuration file.
my $opt_configfile = "./hlstats.conf";

# $opt_libdir - Directory to look in for local required files
#               (our *.plib, *.pm files).
my $opt_libdir = "./";


##
##
################################################################################
## No need to edit below this line
##


use Getopt::Long;
use DBI;
use Encode;
use Syntax::Keyword::Try;

require "$opt_libdir/ConfigReaderSimple.pm";
do "$opt_libdir/HLstats.plib";

$|=1;
Getopt::Long::Configure ("bundling");


binmode STDIN, ":utf8";
binmode STDOUT, ":utf8";

##
## MAIN
##

# Options

my $opt_help = 0;
my $opt_version = 0;
my $opt_numdays = 1;
my $opt_player_activity = 0;
my $opt_awards = 0;
my $opt_ribbons = 0;
my $opt_geoip = 0;
my $opt_prune = 0;
my $opt_optimize = 0;
my $opt_verbose = 0;
our $opt_cpanelhack = 0;

our $db_host = "localhost";
our $db_user = "";
our $db_pass = "";
our $db_name = "hlstats";

my $date_ubase="";
my $date_base="CURRENT_DATE()";


my $usage = <<EOT
Usage: hlstats-awards.pl [OPTION]...
Generate awards from Half-Life server statistics.
	
 Actions (specify none or more, default -iarp):
  -i, --inactive                  Calculate player activity
                                    and deactivate inactive players
  -a, --awards                    Process daily awards (see --date descr for more info)
  -r, --ribbons                   Process ribbons
  -g, --geoip                     Attempt to lookup and store locations for players with
                                    unknown/no location. (run after updating geoip data)
  -p, --prune                     Prune old events and sessions
  -o, --optimize                  Optimize all db tables

 Other options:
  -h, --help                      display this help and exit
  -v, --version                   output version information and exit
      --numdays                   number of days in period for awards
      --date=YYYY-MM-DD           day after date to calculate awards for (defaults to today) 
                                    If you specify a date like 2008-01-04 it will do awards
                                    based on 2008-01-03 stats
      --db-host=HOST              database ip:port
      --db-name=DATABASE          database name
      --db-password=PASSWORD      database password (WARNING: specifying the
                                    password on the command line is insecure.
                                    Use the configuration file instead.)
      --db-username=USERNAME      database username
  -c, --configfile                Specific configfile to use, settings in this file can't
                                  be overided with commandline settings.

Long options can be abbreviated, where such abbreviation is not ambiguous.

Most options can be specified in the configuration file:
  $opt_configfile
Note: Options set on the command line take precedence over options set in the
configuration file.

Database privileges: awards require CREATE TEMPORARY TABLES. Ribbons also require
CREATE, DROP and ALTER (for RENAME TABLE), in addition to normal SELECT/INSERT
access. Ribbon rebuilds reserve hlstats_Players_Ribbons_build and
hlstats_Players_Ribbons_old, and publish the completed table atomically. A failed
build leaves live ribbons intact; rerun to recover. Do not run administrative
resets or schema maintenance concurrently with a rebuild.

HLstatsX:CE: http://www.hlxce.com
EOT
;

# Read Config File

my %conf_directives = (
	"DBHost",			"db_host",
	"DBUsername",		"db_user",
	"DBPassword",		"db_pass",
	"DBName",			"db_name",
	"CpanelHack",		"opt_cpanelhack"
);

if (-r $opt_configfile)
{
	$conf = ConfigReaderSimple->new($opt_configfile);
	$conf->parse();
	&doConf($conf, %conf_directives);
}
else
{
	print "-- Warning: unable to open configuration file $opt_configfile\n";
}

# Read Command Line Arguments

GetOptions(
	"help|h"			=> \$opt_help,
	"version|v"			=> \$opt_version,
	"numdays=i"			=> \$opt_numdays,
	"date=s"			=> \$date_ubase,
	"inactive|i"		=> \$opt_player_activity,
	"awards|a"			=> \$opt_awards,
	"ribbons|r"			=> \$opt_ribbons,
	"geoip|g"			=> \$opt_geoip,
	"clans|t"			=> \$opt_clans,
	"prune|p"			=> \$opt_prune,
	"optimize|o"		=> \$opt_optimize,
	"db-host=s"			=> \$db_host,
	"db-name=s"			=> \$db_name,
	"db-password=s"		=> \$db_pass,
	"db-username=s"		=> \$db_user,
	"configfile|c=s"	=> \$configfile,
	"verbose"			=> \$opt_verbose
) or die($usage);

if ($opt_help)
{
	print $usage;
	exit(0);
}

if ($configfile && -r $configfile) {
	$conf = '';
	$conf = ConfigReaderSimple->new($configfile);
	$conf->parse();
	&doConf($conf, %conf_directives);
}

print "-- Connecting to MySQL database '$db_name' on '$db_host' as user '$db_user' ... ";

&doConnect;

print "connected OK\n";

$result = &doQuery("
	SELECT
		value
	FROM
		hlstats_Options
	WHERE
		keyname='version'
");

if ($result->rows > 0) {
	$g_version = $result->fetchrow_array;
}

if ($opt_version)
{
	print "\nhlstats-awards.pl (HLX:CE Awards Script) Version $g_version\n"
		. "Real-time player and clan rankings and statistics for Half-Life\n\n"
		. "Copyright (C) 2001  Simon Garner\n"
		. "Modified & Enhanced in 2005 by Tobias Oetzel (Tobi@gameme.de)\n\n";

	print "\nThis is free software; see the source for copying conditions.  There is NO\n"
		. "warranty; not even for MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.\n\n";

	exit(0);
}

if ($date_ubase)
{
	$date_base = "'" . $date_ubase . "'";
}

if (0 == ($opt_player_activity + $opt_awards + $opt_ribbons + $opt_geoip + $opt_clans + $opt_prune + $opt_optimize))
{
	$opt_player_activity = 1;
	$opt_awards = 1;
	$opt_ribbons = 1;
	$opt_prune = 1;
}

# Startup

print "++ HLstatsX:CE Awards & Maintenance script version $g_version starting...\n\n";

DoPruning() if ($opt_prune);
DoOptimize() if ($opt_optimize);
DoInactive() if ($opt_player_activity);
DoAwards() if ($opt_awards);
DoRibbons() if ($opt_ribbons);
DoGeoIP() if ($opt_geoip);
DoClans() if ($opt_clans);

print "\n++ HLstatsX:CE Awards & Maintenance script finished.\n\n";

sub DoInactive
{
	print "++ Updating player activity... ";
	$g_minactivity = 2419200;
	# Inactive Players
	my $result = &doQuery("
		SELECT
			value
		FROM
			hlstats_Options
		WHERE
			keyname = 'MinActivity'
	");

	if ($result->rows > 0) {
		my ($tempminact) = $result->fetchrow_array;
		$g_minactivity = $tempminact * 86400;
	}
	
	if ($g_minactivity > 0)
	{
		$g_timestamp = 0;
	
		$result = &doQuery("
			SELECT
				value
			FROM
				hlstats_Options
			WHERE
				keyname = 'UseTimestamp'
		");
		
		
		if ($result->rows > 0) {
			($g_timestamp) = $result->fetchrow_array;
		}
		
		%last_events = ();
		
		if ($g_timestamp > 0)
		{
			$result = &doQuery("
				SELECT
					game,
					MAX(last_event)
				FROM
					hlstats_Servers
				GROUP BY
					game
			");
			my %last_events = ();
	
	
			while ( my($game, $last) = $result->fetchrow_array) {
				$last_events{$game} = $last
			}
			while ( my($game, $last) = each(%last_events))
			{
				&execNonQuery("
					UPDATE
						hlstats_Players
					SET
						hlstats_Players.activity = IF(($g_minactivity > $last - hlstats_Players.last_event), ((100 / $g_minactivity) * ($g_minactivity - ($last - hlstats_Players.last_event))), -1)
					WHERE
						hlstats_Players.game = '".&quoteSQL($game)."'
				");
			}
		}
		else
		{
			&execNonQuery("
				UPDATE
					hlstats_Players
				SET
					hlstats_Players.activity = IF(($g_minactivity > UNIX_TIMESTAMP() - hlstats_Players.last_event), ((100 / $g_minactivity) * ($g_minactivity - (UNIX_TIMESTAMP() - hlstats_Players.last_event))), -1)
			");
		}
	
		&execNonQuery("
			UPDATE
				hlstats_Players
			SET
				hideranking = 3
			WHERE
				hideranking = 0
				AND activity < 0
		");
	}
	
	print "done\n";
}

# Run-local summaries deliberately use retained events, not lifetime counters.
# NULL daily totals mean no events in the strict daily interval, not a zero score.
sub BuildAwardAggregates
{
	my $daily = "eventTime < $date_base AND eventTime > DATE_SUB($date_base, INTERVAL $opt_numdays DAY)";
	&doQuery("
		CREATE TEMPORARY TABLE hlstats_award_totals (
			kind CHAR(1), game VARCHAR(32), code VARCHAR(128), playerId INT UNSIGNED,
			globalValue DECIMAL(30,0), dailyValue DECIMAL(30,0),
			INDEX (kind, game, code)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
	");
	&doQuery("
		CREATE TEMPORARY TABLE hlstats_award_frags AS
		SELECT p.game, e.killerId AS playerId, e.weapon, e.headshot,
			COUNT(*) AS total, SUM(IF($daily, 1, NULL)) AS daily
		FROM hlstats_Events_Frags e
		INNER JOIN hlstats_Players p ON p.playerId=e.killerId AND p.hideranking=0
		GROUP BY p.game, e.killerId, e.weapon, e.headshot
	");
	for my $metric (
		["W", "weapon", "1=1"],
		["E", "'headshot'", "headshot=1"],
		["E", "'allsentrykills'", "weapon LIKE 'obj_sentrygun%'"]
	) {
		my ($kind, $code, $where) = @$metric;
		&doQuery("
			INSERT INTO hlstats_award_totals
			SELECT '$kind', game, $code, playerId, SUM(total), SUM(daily)
			FROM hlstats_award_frags WHERE $where GROUP BY game, $code, playerId
		");
	}
	&doQuery("
		CREATE TEMPORARY TABLE hlstats_award_actions AS
		SELECT 'O' AS kind, playerId, 0 AS victimId, serverId, actionId,
			COUNT(*) AS total, SUM(IF($daily, 1, NULL)) AS daily,
			SUM(bonus) AS bonus, SUM(IF($daily, bonus, NULL)) AS dailyBonus
		FROM hlstats_Events_PlayerActions GROUP BY playerId, serverId, actionId
		UNION ALL
		SELECT 'P', playerId, victimId, serverId, actionId,
			COUNT(*), SUM(IF($daily, 1, NULL)),
			SUM(bonus), SUM(IF($daily, bonus, NULL))
		FROM hlstats_Events_PlayerPlayerActions GROUP BY playerId, victimId, serverId, actionId
	");
	&doQuery("
		INSERT INTO hlstats_award_totals
		SELECT e.kind, p.game, a.code, p.playerId, SUM(e.total), SUM(e.daily)
		FROM hlstats_award_actions e
		INNER JOIN hlstats_Players p ON p.playerId=e.playerId AND p.hideranking=0
		INNER JOIN hlstats_Actions a ON a.id=e.actionId
		GROUP BY e.kind, p.game, a.code, p.playerId
	");
	&doQuery("
		INSERT INTO hlstats_award_totals
		SELECT 'V', p.game, a.code, p.playerId, SUM(e.total), SUM(e.daily)
		FROM hlstats_award_actions e
		INNER JOIN hlstats_Players p ON p.playerId=e.victimId AND p.hideranking=0
		INNER JOIN hlstats_Actions a ON a.id=e.actionId
		WHERE e.kind='P' GROUP BY p.game, a.code, p.playerId
	");
	# Bonus and latency attribution is by server game, unlike weapon/action awards.
	&doQuery("
		INSERT INTO hlstats_award_totals
		SELECT 'E', s.game, 'bonuspoints', p.playerId, SUM(e.bonus), SUM(e.dailyBonus)
		FROM hlstats_award_actions e
		INNER JOIN hlstats_Players p ON p.playerId=e.playerId AND p.hideranking=0
		INNER JOIN hlstats_Servers s ON s.serverId=e.serverId
		GROUP BY s.game, p.playerId
	");
	&doQuery("
		INSERT INTO hlstats_award_totals
		SELECT 'E', s.game, 'latency', p.playerId,
			ROUND(ROUND(SUM(ping)/COUNT(ping), 0)/2, 0),
			ROUND(ROUND(SUM(IF($daily, ping, NULL))/NULLIF(COUNT(IF($daily, ping, NULL)), 0), 0)/2, 0)
		FROM hlstats_Events_Latency e
		INNER JOIN hlstats_Players p ON p.playerId=e.playerId AND p.hideranking=0
		INNER JOIN hlstats_Servers s ON s.serverId=e.serverId
		GROUP BY s.game, p.playerId
	");
	&doQuery("DROP TEMPORARY TABLE hlstats_award_frags, hlstats_award_actions");
}

sub AwardEventWinners
{
	my ($kind, $game, $code) = @_;
	my $order = $code eq 'latency' ? 'ASC' : 'DESC';
	my $tie = $kind eq 'E' && ($code eq 'latency' || $code eq 'bonuspoints') ? '' : ', p.skill DESC';
	my @results;
	for my $period ('dailyValue', 'globalValue') {
		push @results, &doQuery("
			SELECT t.playerId, t.$period FROM hlstats_award_totals t
			INNER JOIN hlstats_Players p ON p.playerId=t.playerId
			WHERE t.kind='".&quoteSQL($kind)."' AND t.game='".&quoteSQL($game)."'
				AND t.code='".&quoteSQL($code)."' AND t.$period IS NOT NULL
			ORDER BY t.$period $order $tie LIMIT 1
		");
	}
	return @results;
}

sub DoAwards
{
	print "++ Processing awards... ";
	
	my $resultAwards = &doQuery("
		SELECT
			hlstats_Awards.awardId,
			hlstats_Awards.game,
			hlstats_Awards.awardType,
			hlstats_Awards.code
		FROM
			hlstats_Awards
		LEFT JOIN hlstats_Games ON
			hlstats_Games.code = hlstats_Awards.game
		WHERE
			hlstats_Games.hidden='0'
		ORDER BY
			hlstats_Awards.game,
			hlstats_Awards.awardType
	");

	my $result = &doQuery("
		SELECT
			value,
			DATE_SUB($date_base, INTERVAL 1 DAY)
		FROM
			hlstats_Options
		WHERE
			keyname = 'awards_d_date'
	");

	if ($result->rows > 0)
	{
		($awards_d_date, $awards_d_date_new) = $result->fetchrow_array;
		
		&execNonQuery("
			UPDATE
				hlstats_Options
			SET
				value='$awards_d_date_new'
			WHERE
				keyname='awards_d_date'
		");
		
		print "(generating awards for $awards_d_date_new (previous: $awards_d_date))... ";
	}
	else
	{
		&execNonQuery("
			INSERT INTO
				hlstats_Options
				(
					keyname,
					value,
					opttype
				)
			VALUES
			(
				'awards_d_date',
				DATE_SUB($date_base, INTERVAL 1 DAY),
				2
			)
		");
	}

	&execNonQuery("
		REPLACE INTO
			hlstats_Options
			(
				keyname,
				value,
				opttype
			)
		VALUES
		(
			'awards_numdays',
			$opt_numdays,
			2
		)
	");

	BuildAwardAggregates();
	while( ($awardId, $game, $awardType, $code) = $resultAwards->fetchrow_array )
	{
		if ($code eq "latency") {
			($resultDaily, $resultGlobal) = AwardEventWinners('E', $game, $code);
		} elsif ($code eq "mostkills") {
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.kills
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND	hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					kills DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					kills
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					kills DESC
				LIMIT 1
			");
		}
		elsif ($code eq "suicide") {
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.suicides
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					suicides DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					suicides
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					suicides DESC
				LIMIT 1
			");
		} elsif ($code eq "teamkills") {
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.teamkills
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					teamkills DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					teamkills
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					teamkills DESC
				LIMIT 1
			");
		} elsif ($code eq "bonuspoints" || $code eq "allsentrykills") {
			($resultDaily, $resultGlobal) = AwardEventWinners('E', $game, $code);
		} elsif ($code eq "connectiontime") {
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.connection_time
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					connection_time DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					connection_time
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					connection_time DESC
				LIMIT 1
			");
		} elsif ($code eq "killstreak") {
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.kill_streak
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					kill_streak DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					kill_streak
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					kill_streak DESC
				LIMIT 1
			");
		} elsif ($code eq "deathstreak") {
		print "in deathstreak";
			$resultDaily = &doQuery("
				SELECT
					hlstats_Players_History.playerId,
					hlstats_Players_History.death_streak
				FROM
					hlstats_Players_History,
					hlstats_Players
				WHERE
					hlstats_Players_History.game='".&quoteSQL($game)."'
					AND hlstats_Players.playerId = hlstats_Players_History.playerId
					AND hlstats_Players.hideranking=0
					AND eventTime = DATE_SUB($date_base, INTERVAL $opt_numdays DAY)
				ORDER BY
					death_streak DESC
				LIMIT 1
			");
			$resultGlobal = &doQuery("
				SELECT
					playerId,
					death_streak
				FROM
					hlstats_Players
				WHERE
					hlstats_Players.game='".&quoteSQL($game)."'
					AND hlstats_Players.hideranking=0
				ORDER BY
					death_streak DESC
				LIMIT 1
			");
		} else {
			my $kind = $awardType eq 'W' && $code eq 'headshot' ? 'E' : $awardType;
			($resultDaily, $resultGlobal) = AwardEventWinners($kind, $game, $code);
		}
		
		($d_winner_id, $d_winner_count) = $resultDaily->fetchrow_array;
		($g_winner_id, $g_winner_count) = $resultGlobal->fetchrow_array;
		
		if (!$d_winner_id || $d_winner_count < 1)
		{
			$d_winner_id = "NULL";
			$d_winner_count = "NULL";
		}
		if (!$g_winner_id || $g_winner_count < 1)
		{
			$g_winner_id = "NULL";
			$g_winner_count = "NULL";
		}
		
		if ($opt_verbose)
		{
			print "  - $d_winner_id ($d_winner_count)\n";
			print "  - $g_winner_id ($g_winner_count)\n";
		}
		
		&execNonQuery("
			UPDATE
				hlstats_Awards
			SET
				d_winner_id=$d_winner_id,
				d_winner_count=$d_winner_count,
				g_winner_id=$g_winner_id,
				g_winner_count=$g_winner_count
			WHERE
				awardId=$awardId
		");
	}


	&execNonQuery("
		INSERT IGNORE INTO 
			hlstats_Players_Awards 
		SELECT 
			value, awardId, d_winner_id, d_winner_count, game 
		FROM 
			hlstats_Options INNER JOIN hlstats_Awards 
		WHERE 
			keyname='awards_d_date' AND NOT ISNULL(d_winner_id);
		");

	&doQuery("DROP TEMPORARY TABLE hlstats_award_totals");
	print "done\n";
}

sub DoRibbons
{
	print "++ Processing ribbons... ";

	# Serialize rebuilds and recover staging/old tables left by an interrupted run.
	# Use checked queries throughout: a failed build must never publish partial rows.
	# Do not use the reconnecting query helpers: reconnecting would lose our lock.
	local $db_conn->{RaiseError} = 1;
	local $db_conn->{mysql_auto_reconnect} = 0;
	my $lock = 'hlstats:ribbons:' . $db_conn->selectrow_array("SELECT MD5(DATABASE())");
	my ($locked) = $db_conn->selectrow_array("SELECT GET_LOCK(?, 0)", undef, $lock);
	die "Another ribbon rebuild is running\n" unless $locked;
	$db_conn->do("DROP TABLE IF EXISTS hlstats_Players_Ribbons_build, hlstats_Players_Ribbons_old");
	$db_conn->do("CREATE TABLE hlstats_Players_Ribbons_build LIKE hlstats_Players_Ribbons");
	# The old loop left rows for games absent from hlstats_Games untouched.
	$db_conn->do("
		INSERT INTO hlstats_Players_Ribbons_build (playerId, ribbonId, game)
		SELECT pr.playerId, pr.ribbonId, pr.game FROM hlstats_Players_Ribbons pr
		LEFT JOIN hlstats_Games g ON g.code=pr.game WHERE g.code IS NULL
	");
	$db_conn->do("
		CREATE TEMPORARY TABLE hlstats_ribbon_counts AS
		SELECT pa.game, a.code, pa.playerId, COUNT(*) AS occurrences
		FROM hlstats_Players_Awards pa
		INNER JOIN hlstats_Awards a ON a.awardId=pa.awardId AND a.game=pa.game
		INNER JOIN hlstats_Players p ON p.playerId=pa.playerId AND p.hideranking=0
		INNER JOIN hlstats_Games g ON g.code=pa.game
		WHERE a.awardType<>'V' GROUP BY pa.game, a.code, pa.playerId
	");
	$db_conn->do("
		INSERT INTO hlstats_Players_Ribbons_build (playerId, ribbonId, game)
		SELECT c.playerId, r.ribbonId, r.game FROM hlstats_ribbon_counts c
		INNER JOIN hlstats_Ribbons r ON r.game=c.game AND r.awardCode=c.code
			AND r.special=0 AND c.occurrences>=r.awardCount
	");
	$db_conn->do("
		INSERT INTO hlstats_Players_Ribbons_build (playerId, ribbonId, game)
		SELECT p.playerId, r.ribbonId, r.game FROM hlstats_Players p
		INNER JOIN hlstats_Ribbons r ON r.game=p.game AND r.special=2
			AND (p.connection_time/3600)>=r.awardCount
		INNER JOIN hlstats_Games g ON g.code=r.game WHERE p.hideranking=0
	");
	# MyISAM cannot transact DELETE + INSERT. Readers see the complete old or new
	# table through this single atomic rename; no engine/schema migration is needed.
	$db_conn->do("RENAME TABLE hlstats_Players_Ribbons TO hlstats_Players_Ribbons_old,
		hlstats_Players_Ribbons_build TO hlstats_Players_Ribbons");
	$db_conn->do("DROP TABLE hlstats_Players_Ribbons_old");
	$db_conn->do("DROP TEMPORARY TABLE hlstats_ribbon_counts");
	$db_conn->do("SELECT RELEASE_LOCK(?)", undef, $lock);
	print "done\n";
}

sub DoGeoIP
{
	print "++ Looking up missing player locations... ";
	
	my $useGeoIPBinary = 0;
	my $gi = undef;
	my $dogeo = 0;
	my $cnt = 0;
	
	# Sanity checks to see if we can do geolocation updates
	$result = &doQuery("
		SELECT
			value
		FROM
			hlstats_Options
		WHERE
			keyname='UseGeoIPBinary'
			AND value > '0'
		LIMIT 1
	");
	
	if ($result->rows > 0)
	{
		$useGeoIPBinary = 1;
		$geoipfile = "$opt_libdir/GeoLiteCity/GeoLite2-City.mmdb";
	}
	else
	{
		$useGeoIPBinary = 0;
	}
	
	if ($useGeoIPBinary == 0)
	{
		my $result = &doQuery("SELECT locId FROM geoLiteCity_Blocks LIMIT 1;");
		if ($result->rows > 0)
		{
			$dogeo = 1;
		}
		else
		{
			&printEvent("ERROR", "GeoIP method set to database but geoLiteCity tables are empty.", 1);
		}
	}
	elsif ($useGeoIPBinary == 1 && -r $geoipfile)
	{
		if ($opt_cpanelhack) {
			my $home_dir = $ENV{ HOME };
			my $base_module_dir = (-d "$home_dir/perl" ? "$home_dir/perl" : ( getpwuid($>) )[7] . '/perl/');
			unshift @INC, map { $base_module_dir . $_ } @INC;
		}

		eval "use GeoIP2::Database::Reader";
		
		$gi = GeoIP2::Database::Reader->new(
			file    => $geoipfile,
			locales => [ 'en' ]
		);

		if ($gi) 
		{
			$dogeo = 1;
		}
		else
		{
			&printEvent("ERROR", "GeoIP method set to binary file lookup but $geoipfile errored while opening.", 1);
			$gi = undef;
		}
	}
	else
	{
		&printEvent("ERROR", "GeoIP method set to binary file lookup but $geoipfile NOT FOUND", 1);
	}

		
	if ($dogeo) {
		sub ip2number {
			my ($ipstr) = @_;
			my @ip = split(/\./, $ipstr);
			my $number = ($ip[0]*16777216) + ($ip[1]*65536) + ($ip[2]*256) + $ip[3];

			return $number;
		}

		sub trim {
			my $string = shift;
			$string =~ s/^\s+|\s+$//g;
			return $string;
		}
		$result = &doQuery("SELECT playerId, lastAddress, lastName FROM hlstats_Players WHERE flag='' AND lastAddress<>'';");
				
		while (my($pid, $address, $name) = $result->fetchrow_array) {
			$address = trim($address);
			next if ($address !~ /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/);
			if ($opt_verbose)
			{
				print "Attempting to find location for: ".$name." (".$address.")\n";
			}
			my $number = ip2number($address);
			my $update = 0;
			my $foundflag = "";
			my $foundcountry = "";
			my $foundcity = "";
			my $foundstate = "";
			my $foundlat = 0;
			my $foundlng = 0;
			if ($useGeoIPBinary > 0) {
				if ($opt_verbose)
				{
					print "2 ".$pid." ".$address."\n";
				}

				my $country_code = undef;
				my $country_name = undef;
				my $region = undef;
				my $city = undef;
				my $lat = undef;
				my $lng = undef;

				my $geoCity = undef;
		
				try { $geoCity = $gi->city( ip => $address ); }
				catch { $geoCity = undef; }

				if ($geoCity) {

					my $geoCityRec = $geoCity->city();
					my $geoCountryRec = $geoCity->country();
					my $geoLocationRec = $geoCity->location();
					my $geoPostalRec = $geoCity->postal();
					my $geoMostSpecificSubdivision = $geoCity->most_specific_subdivision();

					$country_code = $geoCountryRec->iso_code();
					$country_name = $geoCountryRec->name();
					$region = $geoMostSpecificSubdivision->name();
					$city = $geoCityRec->name();
					$postal_code = $geoPostalRec->code();
					$lat = $geoLocationRec->latitude();
					$lng = $geoLocationRec->longitude();
				}	

				if ($lng) {

					$foundflag = encode("utf8",$country_code);
					$foundcountry = encode("utf8",$country_name);
					$foundcity = encode("utf8",$city);
					$foundstate = encode("utf8",$region);
					$foundlat = $lat;
					$foundlng = $lng;
					$update++;
				}
			}
			else
			{
				$result2 = &doQuery("SELECT locId FROM geoLiteCity_Blocks WHERE startIpNum<=".$number." AND endIpNum>=".$number." LIMIT 1;");
				if ($result2->rows > 0) {
					my ($locid) = $result2->fetchrow_array;
					$data = &doQuery("SELECT city, region AS state, name AS country, country AS flag, latitude AS lat, longitude AS lng FROM geoLiteCity_Location a  inner join hlstats_Countries b ON a.country=b.flag WHERE locId=".$locid." LIMIT 1;");
					if ($data->rows > 0) {
						($foundcity, $foundstate, $foundcountry, $foundflag, $foundlat, $foundlng) = $data->fetchrow_array;
						$update++;
					}
				}
			}
			if ($update > 0)
			{
				&execNonQuery("
					UPDATE
						hlstats_Players
					SET
						flag='".&quoteSQL($foundflag)."',
						country='".&quoteSQL($foundcountry)."',
						lat='".(($foundlat ne "")?$foundlat:undef)."',
						lng='".(($foundlng ne "")?$foundlng:undef)."',
						city='".&quoteSQL($foundcity)."',
						state='".&quoteSQL($foundstate)."'
					WHERE
						playerId=".$pid
				);
				$cnt++;
			}
		}
	}
	printf ("done%s\n", (($cnt>0)?" (updated $cnt players)":""));
}

sub DoClans
{
	print "++ Reparsing player names to recalculate clan affiliations... ";
	
	my @clanpatterns = ();
	my $result = &doQuery("
		SELECT
			pattern,
			position,
			LENGTH(pattern) AS pattern_length
		FROM
			hlstats_ClanTags
		ORDER BY
			pattern_length DESC,
			id
	");
	
	while ( my($pattern, $position) = $result->fetchrow_array) {
		my $regpattern = quotemeta($pattern);
		$regpattern =~ s/([A-Za-z0-9]+[A-Za-z0-9_-]*)/\($1\)/; # to find clan name from tag
		$regpattern =~ s/A/./g;
		$regpattern =~ s/X/.?/g;
		if ($position eq "START") {
			push(@clanpatterns, "^($regpattern).+");
		} elsif ($position eq "END") {
			push(@clanpatterns, ".+($regpattern)\$");
		} elsif ($position eq "EITHER") {
			push(@clanpatterns, "^($regpattern).+");
			push(@clanpatterns, ".+($regpattern)\$");
		}
	}
	
	$result = &doQuery("
		SELECT
			playerId, lastName, game
		FROM
			hlstats_Players
	");
	
	while ( my($playerId, $name, $game) = $result->fetchrow_array)
	{
		my $clanTag = "";
		my $clanId = 0;
		foreach (@clanpatterns)
		{
			$clanTag = "";
			if ($name =~ /$_/i)
			{
				$clanTag  = $1;
				$clanName = $2;
				last;
			}			
		}
		if (!$clanTag)
		{
			&execCached("playerclan_clear", "UPDATE hlstats_Players SET clan=0 WHERE playerId=?", $playerId);
			next;
		}
		
		my $query = "
			SELECT
				clanId
			FROM
				hlstats_Clans
			WHERE
				tag=? AND
				game=?
		";
		my $clanresult = &execCached("clan_select", $query, $clanTag, $game);

		if ($clanresult->rows) {
			my ($id) = $clanresult->fetchrow_array;
			$clanresult->finish;
			$clanId = $id;
		} else {
			# The clan doesn't exist yet, so we create it.
			$query = "
				REPLACE INTO
					hlstats_Clans
					(
						tag,
						name,
						game
					)
				VALUES
				(
					?,?,?
				)
			";
			&execCached("clan_insertupdate", $query, $clanTag, $clanName, $game);
			
			$clanId = $db_conn->{'mysql_insertid'};
		}
		&execCached("playerclan_update", "UPDATE hlstats_Players SET clan=? WHERE playerId=?", $clanId, $playerId);
	}
	
	print "done\n";
}

sub DoPruning
{
	# Freeze all cutoffs once: a long backlog must not advance the expiry window.
	# Pruning precedes awards, including historical --date/--numdays requests.
	# Keep the boundary day for DATE-valued player history as well as event times.
	my $event_cutoff = "DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL value DAY)";
	if ($opt_awards) {
		$event_cutoff = "LEAST($event_cutoff, DATE_SUB($date_base, INTERVAL $opt_numdays DAY))";
	}
	my $cutoffs = &doQuery("
		SELECT $event_cutoff, UNIX_TIMESTAMP() - 172800,
			UNIX_TIMESTAMP(CURRENT_TIMESTAMP() - INTERVAL 1 YEAR)
		FROM hlstats_Options WHERE keyname='DeleteDays'
	");
	my ($events_before, $trend_before, $load_before) = $cutoffs->fetchrow_array;
	my @tables = (
		(map { ["hlstats_Events_$_", "eventTime", $events_before] } sort keys %g_eventTables),
		["hlstats_Players_History", "eventTime", $events_before],
		["hlstats_Trend", "timestamp", $trend_before],
		["hlstats_server_load", "timestamp", $load_before]
	);

	# Each statement releases MyISAM's write lock. Yield between full batches
	# so ingestion can run; the time-leading indexes avoid repeated table scans.
	my $batch_size = 1000;
	foreach my $entry (@tables) {
		my ($table, $column, $cutoff) = @$entry;
		my $quoted_cutoff = $db_conn->quote($cutoff);
		print "++ Cleaning up $table: deleting $column < $quoted_cutoff... ";
		my $deleted;
		do {
			my $batch = &doQuery("
				DELETE FROM $table WHERE $column < $quoted_cutoff
				ORDER BY $column LIMIT $batch_size
			");
			$deleted = $batch->rows;
			$batch->finish;
			select(undef, undef, undef, 0.01) if ($deleted == $batch_size);
		} while ($deleted == $batch_size);
		print "done\n";
	}
}

sub DoOptimize
{	
	print "++ Optimizing all tables... ";

	$result = &doQuery("SHOW TABLES");
	while ( ($row) = $result->fetchrow_array ) {
		push(@g_allTables, $row);
	}
	foreach $table (@g_allTables) {
		&execNonQuery("
			OPTIMIZE TABLE $table
		");
	}
	print "done\n";
}
