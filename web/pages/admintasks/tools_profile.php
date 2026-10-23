<?php

if (!defined('IN_HLSTATS')) {
	die('Do not access this file directly.');
}

if (!$auth->ok || $auth->userdata['acclevel'] < 100) {
	http_response_code(403);
	die('Access denied!');
}

// Profiling is optional; neither table is installed by the normal schema.
$result = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('hlstats_sql_web_profile', 'hlstats_sql_daemon_profile')", false);
if (!$result || $db->num_rows($result) !== 2) {
	echo '<p>SQL profiling is not enabled. Both web and daemon profiling tables must be installed to use this tool.</p>';
	return;
}

if (isset($_POST['reset'])) {
	$db->query("delete from hlstats_sql_web_profile");
	$db->query("delete from hlstats_sql_daemon_profile");
	echo '<p>Stats reset.</p>';
}

?>
<form method="post">
	<?php echo csrf_input(); ?>
	<button type="submit" name="reset" value="1" class="submit">Reset SQL profiling statistics</button>
</form>
<?php

print("<h3>Web performance</h3>");
print("<p>top queries by # of times run<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_web_profile order by run_count desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");

print("<p>top queries by total time taken<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_web_profile order by run_time desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");

print("<p>top queries by avg runtime<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_web_profile order by avg_rt desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");


print("<hr>");

print("<h3>Daemon performance</h3>");
print("<p>top queries by # of times run<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_daemon_profile order by run_count desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");

print("<p>top queries by total time taken<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_daemon_profile order by run_time desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");

print("<p>top queries by avg runtime<table><tr><td>origin</td><td>count</td><td>total time</td><td>avg time</td></tr>");
$result = $db->query("select *, (run_time/run_count) as avg_rt from hlstats_sql_daemon_profile order by avg_rt desc limit 20");
while ($rowdata = $db->fetch_array($result)) {
	print("<tr><td>$rowdata[source]</td><td>$rowdata[run_count]</td><td>$rowdata[run_time]</td><td>$rowdata[avg_rt]</td></tr>");
	
}
print("</table>");

?>
