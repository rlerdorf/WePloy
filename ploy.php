<?php
/*
   Ploy
	
   Simple deployment mechanism

   R.Lerdorf WePay Inc. Nov.2010
*/
class Host {
	private $conn = NULL;
	public  $name = NULL;
		
	function __construct($host, $target, $log, $pwd=NULL) {
		$this->log = $log;
		$this->loud = $target['verbose'];
		$this->quiet = $target['quiet'];
		$this->user = $target['user'];
		$this->conn = $this->connect($host, $target, $pwd);
	}

	private function connect($host, $target, $pwd = NULL) {
		$this->name = $host;
		$this->log->verbose("Connecting to $host on port {$target['ssh_port']}");
		if(!$conn = ssh2_connect($host, $target['ssh_port'], NULL, array('disconnect',array($this,'disconnect')))) {
			$this->log->error("Unable to connect");
		}
		$this->log->verbose("Authenticating as {$target['deploy_user']} using {$target['public_key_file']}");
		if(!ssh2_auth_pubkey_file($conn, $target['deploy_user'], $target['public_key_file'], $target['private_key_file'], $pwd)) {
			$this->log->error("Unable to authenticate");
		}
		$this->log->ssh[$host] = $this;
		return $conn;
	}

	private function disconnect($reason, $message, $language) {
		$this->conn = NULL;
		$this->log->error("Server disconnected with reason code [$reason] and message: $message");
	}

	function exec($cmd) {
		$this->log->verbose("Executing remote command: $cmd");
		$stdout = ssh2_exec($this->conn, $cmd." && echo 'RETOK'", 'ansi');
		$stderr = ssh2_fetch_stream($stdout, SSH2_STREAM_STDERR);	
		stream_set_blocking($stderr, true);
		$errors = stream_get_contents($stderr);
		if($errors) $this->log->verbose("STDERR: ".trim($errors));
		stream_set_blocking($stdout, true);
		$output = stream_get_contents($stdout);
		if(!strstr($output,'RETOK')) {
			$this->log->error($output);
		} else {
			$output = substr($output,0,strpos($output,'RETOK'));
		}
		$this->log->verbose(trim($output));
		return $output;
	}

	function scp($local_file, $remote_file, $mode = 0644) {
		$this->log->verbose("scp'ing $local_file to $remote_file mode ".decoct($mode));
		$ret = ssh2_scp_send($this->conn, $local_file, $remote_file, $mode);
		return $ret;
	}

	function sftp($local_file, $remote_file) {
		$this->log->verbose("sftp'ing $local_file to $remote_file");
		$sftp = ssh2_sftp($this->conn);
		$remote = fopen("ssh2.sftp://$sftp$remote_file", 'w');
		$local = fopen($local_file,"r");
		$ret = stream_copy_to_stream($local, $remote);
		return $ret;
	}
}

class PloyLog {
	public $loud     = false;
	public $quiet    = false;
	public $buffer   = '';
	public $rollback = array();
	public $ssh      = array();  // ssh connections for rollbacks
	public $progress = 0;        // Estimated completion percentage
	private $rolling_back = false;

	function __construct($rev) {
		$this->rev = $rev;
	}
	
	function flush() {
		if(php_sapi_name()=='fpm-fcgi') {
			echo str_repeat(" ",4096); // blah - remove for non-fastcgi where flush() works
			echo "\n<script>$('#progress_bar .ui-progress').animateProgress({$this->progress},null);</script>\n";
			flush();
		} else flush();
	}

	function output($str, $buffer=true) {
		if(php_sapi_name()!="cli") $eol = "<br />\n";
		else $eol = "\n";
		if($buffer) $this->buffer .= date("M.d H:i:s").": $str\n";
		if(!$this->quiet && $str) echo $str.$eol;	
		$this->flush();
	}

	function verbose($str) {
		$this->buffer .= date("M.d H:i:s").": $str\n";
		if($this->loud) $this->output($str, false);
		else $this->flush();
	}

	function error($str) {
		if(php_sapi_name()!="cli") $eol = "<br />\n";
		else $eol = "\n";
		$this->buffer .= date("M.d H:i:s").": $str\n";
		error_log("Connection Error: $str");
		if(!$this->rolling_back) {
			echo "Deploy failed - rolling back".$eol;
			$this->flush();
			$this->rollback_exec();
			if(empty($GLOBALS['log_file'])) $GLOBALS['log_file'] = "/tmp/ploy-{$this->rev}.txt";
			file_put_contents($GLOBALS['log_file'], $this->buffer);
			echo "deploy log written to $GLOBALS[log_file]".$eol;
		}
		$this->flush();
		exit(1);	
	}

	function rollback_set($cmd, $ip='local') {
		$this->rollback[$ip] = array($cmd);		
	}

	function rollback_add($cmd, $ip='local') {
		$this->rollback[$ip][] = $cmd;		
	}

	function rollback_exec($ip=NULL) {
		if(!count($this->rollback)) {
			$this->output("Nothing to roll back\n");
		}
		$this->rolling_back = true;

		foreach($this->rollback as $ip=>$cmds) {
			// Roll back in reverse order
			foreach(array_reverse($cmds) as $cmd) {
				if($ip=='local') {
					$this->output("Local: $cmd");
					`$cmd`;
				} else {
					$this->ssh[$ip]->exec($cmd);
				}
			}
		}
	}
}

class Ploy {
	public $ini_file = './ploy.ini'; 
	public $tmpdir   = '/tmp'; 
	public $defaults = array('scm'=>'svn', 'tz'=>'UTC', 'ssh_port'=>22, 'keep'=>10);
	public $prompt   = false;
	public $quiet     = false;
	public $loud      = false;
	public $rev       = '';

	function __construct($argv=NULL) {
		date_default_timezone_set('America/Los_Angeles');
		$this->rev = date("YmdHis");
		$this->log = new PloyLog($this->rev);
		if(php_sapi_name()=='cli') {
			$this->options($argv);
			$this->config();
			$this->deploy($argv[1]);
		}
	}

	static function usage() {
		echo <<<EOB
Ploy is a dirt-simple deployment tool

Usage: ploy <options> <target>

Options: -c <config_file>  read configuration from this file - default ./ploy.ini
         -u <user>         run as this local user
         -q                quiet mode
         -v                extra verbose mode
         -h                this help
         -p                prompt for private key password
         -t <tmp_dir>      path to tmp dir - default /tmp
EOB;
	}

	private function options(&$argv) {
		// Code borrowed from mpartap at gmx dot net via http://php.net/getopt
		$parameters = array('v' => 'verbose', 'q' => 'quiet', 'h' => 'help', 'c:' => 'config:', 'u:' => 'user', 'p' => 'prompt', 't:' => 'tmpdir');
		$options = getopt(implode('', array_keys($parameters)), $parameters);
		$pruneargv = array();
		foreach ($options as $option => $value) {
			foreach ($argv as $key => $chunk) {
				$regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
				if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk)) {
					array_push($pruneargv, $key);
				}
			}
		}
		while ($key = array_pop($pruneargv)) unset($argv[$key]);
		$argv = array_values($argv); // Re-number
		if(isset($options['h']) || isset($options['help']) || empty($argv[1])) { Ploy::usage(); exit; }
		if(isset($options['q']) || isset($options['quiet'])) $this->log->quiet = true;
		if(isset($options['v']) || isset($options['verbose'])) $this->log->loud = true;
		if(isset($options['p']) || isset($options['prompt'])) $this->prompt = true;
		if(isset($options['t'])) $this->tmpdir = $options['t'];
		if(isset($options['tmpdir'])) $this->tmpdir = $options['tmpdir'];
		if(isset($options['c'])) $this->ini_file = $options['c'];
		if(isset($options['config'])) $this->ini_file = $options['config'];
		else if($tmp = getenv('PLOYINI')) $this->ini_file = $tmp;
		if(!is_readable($this->ini_file)) {
			if(is_readable('/home/sites/wepay.com/current/deploy/ploy/ploy.ini')) {
				$this->ini_file = '/home/sites/wepay.com/current/deploy/ploy/ploy.ini';
			} else {
				$this->log->error("Unable to load Ploy configuration from {$this->ini_file}. Also tried /home/sites/wepay.com/current/deploy/ploy/ploy.ini");
			}
		}
		if(isset($options['u'])) $this->user = $options['u'];
		else if(isset($options['user'])) $this->user = $options['user'];
		else if(!empty($_SERVER['SUDO_USER'])) $this->user = $_SERVER['SUDO_USER'];
		else if(!empty($_SERVER['USER'])) $this->user = $_SERVER['USER'];
		else {
			$this->log->error("Unable to determine local user - please use -u <user>");
		}
		if(!empty($_SERVER['HOME'])) {
			if(basename($_SERVER['HOME'])==$this->user) $this->home = $_SERVER['HOME'];
			else $this->home = dirname($_SERVER['HOME']).'/'.$this->user;
		} else $this->home = "/home/".$this->user;
	}

	public function config() {
		$config = parse_ini_file($this->ini_file, true);	
		$globals = array('quiet'=>$this->log->quiet, 'verbose'=>$this->log->loud, 'ini_file'=>$this->ini_file, 'user'=>$this->user);
		foreach($config as $key=>$val) {
			if(strtolower(substr($key,0,7)) == 'target ') {
				list($junk,$target) = explode(' ',$key,2);
				$this->targets[$target] = $val;
				foreach($globals as $kk=>$vv) {
					if(!isset($this->targets[$target][$kk])) {
						if(strstr($vv,"#{")) {
							$this->targets[$target][$kk] = preg_replace('/(.*?)#{(.*?)}(.*?)/e',"'\\1'.\$this->targets[\$target]['\\2'].'\\3'",$vv);
						} else  $this->targets[$target][$kk] = $vv;
						if(strstr($kk,'_file') && strstr($this->targets[$target][$kk],'~')) {
							$this->targets[$target][$kk] = str_replace('~', $this->home, $this->targets[$target][$kk]);
						}
					}	
				}
				foreach($this->defaults as $kk=>$vv) {
					if(!isset($this->targets[$target][$kk])) $this->targets[$target][$kk] = $vv;
				}
			} else {
				$globals[$key] = $val;
			}
		}
		$this->globals = $globals;
	}

	// Modify this method to change the deploy strategy
	function deploy($target) {
		if(empty($this->targets[$target])) {
			$this->log->error("$target target does not exist");
		}
		$targ = $this->targets[$target];
		$dir = $this->rev;
		$pwd = NULL;
		if($this->prompt) {
			echo "Key Password: ";
			$savetty= shell_exec('stty -g');
			shell_exec('stty -echo');
			$pwd = rtrim(fgets(STDIN), "\n");
			shell_exec('stty ' . $savetty);
			echo "\n";
		}
		$this->log->progress = 5;
		$this->log->verbose("Getting {$targ['scm']} info for {$targ['repository']}");
		$this->log->output("Deploying the following to $target");	
		$cwd = getcwd();
		chdir($this->tmpdir);
		`{$targ['scm']} info --username {$targ['scm.user']} --password {$targ['scm.passwd']} --no-auth-cache --non-interactive --trust-server-cert {$targ['repository']} > $dir.info`;
		$this->log->progress = 10;
		if(php_sapi_name()=='cli') $this->log->output(trim(file_get_contents("$dir.info")));
		else $this->log->output(nl2br(trim(file_get_contents("$dir.info"))));
		if(!is_dir($this->rev)) {
			$this->log->output("Exporting {$targ['repository']} to $dir");
			`{$targ['scm']} export -q --username {$targ['scm.user']} --password {$targ['scm.passwd']} --no-auth-cache --non-interactive --trust-server-cert {$targ['repository']} $dir`;
			$this->log->rollback_set("rm -rf $dir.info $dir");
		}
		$this->log->progress += (int)(100/(count($targ['hosts'])+1));

		// Clean up targets we don't need before pushing the code
		if(is_dir("$dir/deploy/targets")) {
			foreach(glob("$dir/deploy/targets/*") as $t) {
				if(basename($t)!=$target) `rm -rf $t`;
			}
		}
		$this->log->progress += 10;
		`tar czf $dir.tar.gz $dir`;
		$this->log->verbose("tar file $dir.tar.gz created");
		$this->log->rollback_add("rm $dir.tar.gz");
		$md5 = md5_file("$dir.tar.gz");
		$this->log->progress += 5;
		$this->log->verbose("md5 checksum is $md5");

		// Now push the tarball to each host
		$each_progress = (int)((100-$this->log->progress)/(count($targ['hosts'])+1));
		foreach($targ['hosts'] as $ip) {
			$host = new Host($ip, $targ, $this->log, $pwd);
			$targ['ssh'][$ip] = $host;
			$host->exec("mkdir -p {$targ['deploy_to']}/releases");
			if($target=='dev') $host->sftp("$dir.tar.gz", "{$targ['deploy_to']}/releases/$dir.tar.gz");
			else $host->scp("$dir.tar.gz", "{$targ['deploy_to']}/releases/$dir.tar.gz");
			$this->log->progress += $each_progress;
			$this->log->rollback_add("rm -f {$targ['deploy_to']}/releases/$dir.tar.gz", $ip);
			// Make sure the file got there uncorrupted
			$result = $host->exec("md5sum -b {$targ['deploy_to']}/releases/$dir.tar.gz");
			list($remote_md5,$junk) = explode(' ',$result,2);
			if($md5 != $remote_md5) $this->log->error("Local checksum $md5 does not match remote checksum $remote_md5");
			$this->log->verbose("File uploaded and checksum matched");
		}

		// Multiple loops to do these almost in parallel 
		$each_progress = (int)((100-$this->log->progress)/(count($targ['hosts'])+1));
		foreach($targ['ssh'] as $ip=>$host) {
			$this->log->progress += $each_progress;
			$host->exec("cd {$targ['deploy_to']}/releases && tar zxf $dir.tar.gz && rm $dir.tar.gz && cd $dir && REVISION=$dir make $target");
			if(strlen(trim($dir))) $this->log->rollback_add("rm -rf {$targ['deploy_to']}/releases/$dir", $ip);
		}

		// Sanity check
		$each_progress = (int)((100-$this->log->progress)/(count($targ['hosts'])+1));
		foreach($targ['ssh'] as $ip=>$host) {
			$this->log->progress += $each_progress;
			$current_version[$ip] = $host->exec("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php'");
			if($current_version[$ip]) $this->log->rollback_add("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel=rollback&rev={$current_version[$ip]}'", $ip);
		}

		// Move the symlink into place and hit the local setrev script
		$each_progress = (int)((100-$this->log->progress)/(count($targ['hosts'])+1));
		foreach($targ['ssh'] as $ip=>$host) {
			$this->log->progress += $each_progress;
			$this->log->output("Moving symlink from {$current_version[$ip]} to $dir on {$host->name}");
			$host->exec("ln -s {$targ['deploy_to']}/releases/$dir {$targ['deploy_to']}/new_current && mv -Tf {$targ['deploy_to']}/new_current {$targ['deploy_to']}/current");
			if($current_version[$ip]) $this->log->rollback_add("ln -s {$targ['deploy_to']}/releases/{$current_version[$ip]} {$targ['deploy_to']}/new_current && mv -Tf {$targ['deploy_to']}/new_current {$targ['deploy_to']}/current");
			$this->log->output("Symlink moved, version $dir is now active");
			$host->exec("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel={$targ['repository']}&rev=$dir'");
			$this->log->rollback_add("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel={$targ['repository']}&rev={$current_version[$ip]}'", $ip);
		}

		// Deploy was good - non-critical cleanup after this point
		$this->log->rollback_set('');

		// Delete previous targets, but keep $targ[keep] of them around
		$each_progress = (int)((100-$this->log->progress)/(count($targ['hosts'])));
		foreach($targ['ssh'] as $ip=>$host) {
			$this->log->progress += $each_progress;
			$keep = $targ['keep'] + 1;  // The number of old revisions to keep around on the server
			$host->exec("cd {$targ['deploy_to']}/releases && j=0; for i in `ls -d1at ./20????????????`; do j=`expr \$j + 1`; if [ \"\$j\" -ge $keep ]; then rm -rf \$i; fi; done");
		}

		// And get rid of the local installation files
		`rm -rf $dir.info $dir.tar.gz $dir`;
		chdir($cwd);
		$this->log->output("SUCCESS!");
	}
}

if(php_sapi_name()=='cli') {
	$ploy = new Ploy($argv);
	exit(0);
}
