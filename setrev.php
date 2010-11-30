<?php
if($_SERVER['REMOTE_ADDR']=='127.0.0.1'):
	$key = $_SERVER['SERVER_NAME'] . '_deploy_version';
	if(!empty($_GET['rev'])) {
		$rev = (isset($_GET['rev'])) ? (int)$_GET['rev'] : '';
		apc_clear_cache();
		apc_clear_cache('user');
		apc_store($key,$rev);

		// Send a deploy notice if this is a production push
		if(gethostname()=='one_of_yours_hosts.example.com' && $_ENV["SERVER_NAME"]=='www.example.com') {
			$ts = date("M.j Y H:i:s");
			mail('dev@wepay.com','Deploy Notice',"$ts: {$_GET['user']} pushed revision {$_GET['rel']} to production version {$_GET['rev']}\n","From: {$_GET['user']}@example.com\r\nContent-type: text/plain; charset=utf-8\r\n");
		}
	} else {
		echo apc_fetch($key);
	}
endif;
