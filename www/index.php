<?php
/*
Yeah, it's a hack
If you are looking to change some details of the actual deploy,
change the deploy method code in ploy itself.  This file is just
a web wrapper.
*/
ignore_user_abort(true);
set_time_limit(0);
include '../ploy.php';
$ploy = new Ploy();
$log_file = __DIR__."/logs/ploy-{$ploy->rev}.txt";
$ploy->ini_file = '../ploy.ini';
$ploy->user = 'nginx';
$ploy->home = "/var/lib/nginx";
$ploy->log->loud = true;
$ploy->config();

// This is a local script that sets $vpnuser
// Substitute your own here
include './vpnuser.php';

// Create a MySQL user and password and use it here
if(!$m = mysql_connect('localhost','root')) {
	echo "Unable to connect to MySQL<br>\n";
	exit;
}
mysql_select_db('ploy');
$res = mysql_query("select * from ploys order by ts desc limit 5");
while ($row = mysql_fetch_assoc($res)) {
	$last5[] = $row;
}

function done() {
	global $ploy_id, $ploy_status, $log_file;
	if(!mysql_query("update ploys set status = '$ploy_status', log='$log_file' where id=$ploy_id")) {
		echo mysql_error();
	}
}
if(!empty($_POST['application'])) register_shutdown_function('done');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<title>Ploy</title>	
	<meta http-equiv="content-type" content="text/html;charset=UTF-8" />    
	<meta http-equiv="imagetoolbar" content="no" />
	<link rel="stylesheet" href="css/screen.css" media="screen" />
	<link rel="stylesheet" href="css/ui.progress-bar.css">
	<script src="js/jquery.js" type="text/javascript" charset="utf-8"></script>
	<script src="js/progress.js" type="text/javascript" charset="utf-8"></script>
</head>
<body>
<script>
var targets = [];
<?php
$i=0;
foreach($ploy->targets as $t=>$o) {
	$targets[$i] = $t;
	if(!$i) {
		$selected = 0;
		$app = $o['application'];
		$revision = $o['revision'];
	}
	echo "targets[{$i}] = ".json_encode(array('application'=>$o['application'],'revision'=>$o['revision'])).";\n";
	$i++;
}
if(!empty($_POST['application'])) {
	$app = $_POST['application'];
	$revision = $_POST['revision'];
	$selected = $_POST['target'];
	$loud = empty($_POST['loud']) ? 0 : 1;
}
?>
function update() {
	id = document.getElementById('target').value;
	document.getElementById('application').value = targets[id].application;
	document.getElementById('revision').value = targets[id].revision;
}
</script>
<div id="container">
		<img src="/images/wepay-logo.png" width="117" height="58" style="float:left;"/>
		<h1 style="float:right;">WePloy</h1>
		<br clear="left"/>	
		<form class="pform" action="/" method="post">	
			<fieldset>
				<p>
					<label for="target">Choose a target</label>
					<select name="target" id="target" onchange="update();">
					<?php $i=0; foreach($ploy->targets as $t=>$o) { echo "<option value=\"$i\" ".($i==$selected?"SELECTED":"").">$t</option>\n"; $i++; } ?>
					</select>
				</p>								
				<p>
					Verbose Output <input type="checkbox" name="loud" value="1" <?php if(!empty($loud)) echo "checked";?> style="width:32px;"/>
				</p>
			</fieldset>					
			<fieldset><legend>Ploy</legend>
				<p>
					<label for="application">Application</label>
					<input type="text" name="application" id="application" size="30" readonly="readonly" value="<?php echo $app?>"/>
				</p>
				<p>
					<label for="revision">Revision</label>
					<input type="text" name="revision" id="revision" size="30" value="<?php echo $revision?>"/>
				</p>
			</fieldset>
			<div style="margin-left: 20px; margin-top:-40px; float:left;">
				<h4>Last 5:</h4>
				<?php
				if(!empty($last5)) foreach($last5 as $l) {
					if($l['status']=='Failed') {
						$log = basename($l['log']);
						echo "$l[ts] $l[target] by $l[user] - $l[status] Log: <a href=\"/logs/$log\">$log</a><br />\n";
					} else {
						echo "$l[ts] $l[target] by $l[user] - $l[status]<br />\n";
					}
				}
				?>
			</div>
			<p class="submit"><button type="submit"><span style="font-size:1.5em;">Deploy</span></button></p>		
		</form>	
<?php if(isset($_POST['target'])): ?>
<div id="progress_bar" class="ui-progress-bar ui-container">
  <div class="ui-progress" style="width: 0%;">
    <span class="ui-label" style="display:none;">Deploying <b class="value">0%</b></span>
  </div>
</div>
<br />
<div style="background:#000000;color:#D0D0D0;padding:5px;-webkit-border-radius: 5px;-moz-border-radius: 5px;">
<?php 
$target = $targets[$_POST['target']];
$ploy->log->loud = empty($_POST['loud'])?0:1;
$ploy->user = $vpnuser;
if(!mysql_query("insert into ploys values(0,'$vpnuser','','','$target','$_POST[revision]','Running',NOW())")) {
	echo mysql_error();
}
$ploy_status = 'Failed';
$ploy_id = mysql_insert_id();
if(!empty($ploy->targets[$target]) && $ploy->targets[$target]['revision'] != $revision && strstr($ploy->globals['repository'],'#{revision}')) {
	$ploy->targets[$target]['repository'] = str_replace('#{revision}',$revision,$ploy->globals['repository']);
	$ploy->targets[$target]['revision'] = $revision;
}
$ploy->deploy($target);
$ploy_status = 'Success';
?>
<script>$('#progress_bar').hide();</script>
</div>
<?php endif; ?>
</div>
</body>
</html>
