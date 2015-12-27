<?php
// We want all the reporting we can get...  Just not on stdout.
ini_set('error_log', dirname(__FILE__) . '/error_log');
ini_set('display_errors', 'off');
ini_set('log_errors', 'on');
error_reporting(E_ALL);

error_log( time() . ': ' . __FILE__ . ' startup' . PHP_EOL, 3, dirname( __FILE__ ) . '/error_log' );

if (isset($_GET['ping']) && !empty($_GET['ping'])) {
	echo 'Pong';
	exit;
}

$installer = new Remove($_POST);
exit;

/**
 * Removal Payload
 *
 * This is called via curl. The settings are passed in the headers and the process is run
 * on the remote server.
 *
 * @subpackage Controller.Component.assets
 *
 * @copyright SimpleScripts.com, 8 May, 2012
 **/

/**
 * Define DocBlock
 **/

class Remove {

/**
 * Debug Storage
 *
 * @var array $debug
 */
	public $debug = array();

/**
 * Common Config Files
 *
 * @var string
 **/
	public $commonConfigFiles = array(
		'wp-config.php',				// wordpress
		'configuration.php',			// joomla
		'sites/default/settings.php',	// drupal
	);

/**
 * Settings
 *
 * @var array $settings
 */
	public $settings = array(
		'ss_site_url' => null,
		'ss_dbhost' => 'localhost',
		'ss_dbname' => null,
		'ss_dbpass' => null,
		'install_directory' => null,
		'file_manifest' => null,
		'filelist' => array(),
		'panel_username' => null,
		'panel_password' => null,
		'panel_type' => 'other',
	);

/**
 * Class Constructor
 *
 * The $_POST will be sent to this method and merged into the $settings defaults.
 *
 **/
	public function __construct($settings = null) {
		if (!$settings) {
			return false;
		}
		$this->debug['remove'][] = 'Configuring settings.';
		$this->settings = array_merge($this->settings, $settings);
		$this->settings['os'] = strtolower(substr(PHP_OS, 0, 3));
		$this->settings['passthru'] = function_exists('passthru') ? true : false;
		$this->settings['root_directory'] = dirname(__FILE__);
		$this->settings['install_directory'] = $this->settings['root_directory'];
		if (
			!empty($this->settings['file_manifest'])
			&& is_readable( dirname( __FILE__ ) . '/' . $this->settings['file_manifest'] )
		) {
			$fname = dirname( __FILE__ ) . '/' . $this->settings['file_manifest'];
			$fm = fopen( $fname, 'r' );
			$data = fread( $fm, filesize( $fname ) );
			fclose( $fm );
			$data = @json_decode( $data, true );
			if ($data) {
				$this->settings['filelist'] = $data;
			} else {
				$this->error['badManifest'] = 'The manifest file could not be read.';
			}
		} else {
			$this->error['noManifest'] = 'Could not find removal file manifest';
		}
		if (in_array( $this->settings['panel_type'], array( 'cpanel', 'bluehost' ) )) {
			$this->debug['remove'][] = 'Configuring for cpanel.';
			if (!$this->cpanelDetectBinary() || !$this->binary) {
				$this->error['cpanelNotDetected'] = 'could not find nor detect cpanel binary.';
				$this->error['extra'] = $this->settings['panel_username'] . ' for file ' . $this->binary;
				$this->errorDie();
			}
			$fileStructure = explode('/', $this->settings['root_directory']);
			$this->settings['panel_username'] = $fileStructure[2];
		}
		$this->debug['remove'][] = 'Starting process.';
		// remove the filelist recursively from the install directory
		$this->settings['install_directory'] = rtrim($this->settings['install_directory'], '/');
		$this->settings['install_directory'] = rtrim($this->settings['install_directory'], '\\');
		$this->settings['install_directory'] .= '/';

		if (is_dir($this->settings['install_directory'])) {
			$this->debug['remove'][] = 'Removing files from installation directory.';
			$directories = array();
			foreach ($this->commonConfigFiles as $configFile) {
				$this->settings['filelist'][] = $configFile;
			}
			//$this->settings['filelist'][] = 'wp-config.php';
			foreach ($this->settings['filelist'] as $name) {
				$this->debug['remove']['file'][] = $this->settings['install_directory'] . $name;

				if (empty($name)) {
					continue;
				}

				if (is_dir($this->settings['install_directory'] . $name)) {
					//$this->removeDirectoryRecursive($this->settings['install_directory'] . $name);
					$directories[] = $this->settings['install_directory'] . $name;
				}

				if (is_file($this->settings['install_directory'] . $name)) {
					unlink($this->settings['install_directory'] . $name);
				}
			}
			foreach ($directories as $directory) {
				//@rmdir($directory);
				$this->removeDirectoryRecursive($directory);
			}
			$this->debug['remove'][] = 'Files were removed from the directory.';
		}

		// check to see if we can remove the install directory
		$directoryFileCount = 0;
		if (is_dir($this->settings['install_directory'])) {
			$this->debug['remove'][] = 'Checking for installation directory removal.';
			if ($handle = opendir($this->settings['install_directory'])) {
				while (false !== ($entry = readdir($handle))) {
					if ($entry != "." && $entry != "..") {
						$this->debug['remove']['remaining_files'][] = $entry;
						$directoryFileCount++;
					}
				}
				closedir($handle);
				$this->debug['remove'][] = $directoryFileCount . ' files remaining in ' . $this->settings['install_directory'];
				if ($directoryFileCount == 0) {
					if (rmdir($this->settings['install_directory'])) {
						$this->debug['remove'][] = 'Install directory was removed from the server ' . $this->settings['install_directory'];
					} else {
						$this->debug['remove'][] = 'Directory contained additional files and could not be removed.';
					}
				} else {
					$this->debug['remove'][] = 'Directory contained additional files and could not be removed.';
				}
			}
		}

		// try to remove the database
		if (isset($this->settings['remove_database']) && $this->settings['remove_database'] == 1) {
			$this->debug['process'][] = 'Removing database';
			if ($this->removeDatabase()) {
				$this->debug['process'][] = 'Database removed successfully!';
			} else {
				$this->debug['process'][] = 'Database could not be removed!';
			}
		} else {
			$this->debug['process'][] = 'Leaving database in place';
		}
		$this->debug['status'] = 'success';
		echo json_encode($this->debug);
	}

/**
 * Remove Database
 *
 * Method called to remove a database. This will call the correct method based on the panel type.
 *
 * @return void
 **/
	public function removeDatabase() {
		switch($this->settings['panel_type']) {
			case 'bluehost':
			case 'cpanel':
				$this->cpanelRemoveDatabase();
				break;

			case 'plesk':
				$this->pleskRemoveDatabase();
				break;
		}
		return true;
	}

/**
 * Detect the cPanel Bindary for MySQL
 *
 * Check which path contains the binary for database functions in cPanel
 *
 *
 * @return void
 **/
	public function cpanelDetectBinary() {
		$this->binary = null;
		if (file_exists('/usr/local/cpanel/bin/cpmysqlwrap')) {
			$this->binary = '/usr/local/cpanel/bin/cpmysqlwrap';
		} elseif (file_exists('/usr/local/cpanel/bin/mysqlwrap')) {
			$this->binary = '/usr/local/cpanel/bin/mysqlwrap';
		} else {
			return false;
		}
		return true;
	}

/**
 * cPanel Remove Database
 *
 * Removing databases through databases.
 *
 * @return void
 **/
	public function cpanelRemoveDatabase() {
		$this->settings['ss_dbuser'] = $this->settings['ss_dbname'];
		$this->settings['ss_dbname'] = $this->settings['ss_dbuser'];
		$this->settings['ss_dbid'] = '';
		$output = $this->runDatabaseCommand($this->binary . ' LISTDBS');
		if (!in_array($this->settings['ss_dbname'], explode("\n", $output))) {
			$this->debug['error'] = 'The database ' . $this->settings['ss_dbname'] . ' could not be found in the database list.';
			$this->debug['extra'] = var_export($output, true);
		} else {
			$this->debug['DELDB'] = $this->runCommand($this->binary . ' DELDB ' . escapeshellarg($this->settings['ss_dbname']), true);
		}
		$this->debug['DELUSER'] = $this->runCommand($this->binary . ' DELUSER ' . escapeshellarg($this->settings['ss_dbuser']), true);
		$host = trim($this->runCommand($this->binary . ' GETHOST'));
		$this->settings['ss_dbhost'] = (empty($this->settings['ss_dbhost']) || empty($host)) ? 'localhost' : $host;
		$output = $this->runCommand($this->binary . ' LISTDBS');

		if ($output == '') {
			usleep(1000);
			$output = $this->runCommand($this->binary . ' LISTDBS');
		}
		if ($output == '') {
			$this->error['LISTDBS'] = $this->runCommand($this->binary . ' LISTDBS', true);
			$this->error['dbListingTimeout'] = 'could not retrieve list of databases.';
		}
		if (in_array($this->settings['ss_dbname'], explode("\n", $output))) {
			$this->error['dbNotDeleted'] = 'could not remove database.';
			$this->error['extra'] = var_export($output, true);
		}
	}

/**
 * Plesk Create Database
 *
 * Create a database on a plesk server.
 *
 * @return void
 **/
	public function pleskRemoveDatabase() {
		$this->pleskDomainId();
		$this->pleskClientId();
		$this->pleskCheckPermissions();
		$this->pleskDeleteDatabase();
		$this->pleskDeleteDatabaseUser();
		return true;
	}

/**
 * Plesk Domain Id
 *
 * Request the domain id for the domain.
 *
 *
 * @return void
 **/
	public function pleskDomainId() {
		$domainPacket = '
			<packet version="1.4.2.0">
			<domain>
			<get>
			   <filter>
				 <domain_name>' . $this->settings['ss_site_url'] . '</domain_name>
			   </filter>
			   <dataset>
				 <hosting/>
			   </dataset>
			</get>
			</domain>
			</packet>
		';
		$domain = $this->curlSend($domainPacket, $this->settings['panel_username'], base64_decode($this->settings['panel_password']));
		preg_match("/<id>([0-9]+)<\/id>/", $domain, $domainmatch);
		$domainId = $domainmatch[1];
		if ($domainId == "") {
			$this->error['noDomainId'] = 'domainId could not be acquired for plesk account.';
			$this->error['extra'] = $domain;
			$this->errorDie();
		}
		$this->settings['domainid'] = $domainId;
		return true;
	}

/**
 * Plesk Client Id
 *
 * Acquire the client id from the plesk panel
 *
 * @return void
 **/
	public function pleskClientId() {
		$clientidPacket = '
			<packet version="1.4.2.0">
			<client>
			<get>
			   <filter>
				 <login>' . $this->settings['panel_username'] . '</login>
			   </filter>
			   <dataset>
		          <gen_info/>
			   </dataset>
			</get>
			</client>
			</packet>
		';
		$client = curlSend($clientidPacket, $this->settings['panel_username'], base64_decode($this->settings['panel_password']));
		preg_match("/<id>([0-9]+)<\/id>/", $client, $clientidmatch);
		$clientidId = $clientidmatch[1];
		if ($clientidId == "") {
			$this->error['noClientId'] = 'clientId could not be acquired for plesk account.';
			$this->error['extra'] = $client;
			$this->errorDie();
		}
		$this->settings['clientid'] = $clientidId;
		return true;
	}

/**
 * Plesk Delete Database
 *
 * Delete a database on the plesk account.
 *
 * @return void
 **/
	public function pleskDeleteDatabase() {
		$createdbPacket = '
			<packet version="1.4.2.0">
			<database>
			<del-db>
				<domain-id>' . $this->settings['domainid'] . '</domain-id>
				<name>' . $this->settings['ss_dbname'] . '</name>
				<type>mysql</type>
			</dell-db>
			</database>
			</packet>
		';
		$dbpacket = curlSend($createdbPacket, $this->settings['panel_username'], base64_decode($this->settings['panel_password']));
		preg_match("/<status>([a-zA-Z0-9]+)<\/status>/", $dbpacket, $dbstatus);
		if ($dbstatus[1] != 'ok') {
			$this->error['dbNotCreated'] = 'database was not created or could not be found.';
			$this->error['extra'] = $dbpacket;
			$this->errorDie();
		}
		preg_match("/<id>([0-9]+)<\/id>/", $dbpacket, $matches);
		$dbid = $matches[1];
		$this->settings['dbid'] = $dbid;
		return true;
	}

/**
 * Plesk Delete Database User
 *
 * Delete database user.
 *
 * @return void
 **/
	public function pleskDeleteDatabaseUser() {
		$createuserPacket = '
			<packet version="1.4.2.0">
			<database>
			   <del-db-user>
				 <db-id>' . $this->settings['dbid'] . '</db-id>
				 <login>' . $this->settings['ss_dbuser'] . '</login>
				 <password>' . base64_decode($this->settings['ss_dbpass']) . '</password>
			   </del-db-user>
			</database>
			</packet>
		';
		$dbuser = curlSend($createuserPacket, $this->settings['panel_username'], base64_decode($this->settings['panel_password']));
		preg_match("/<status>([a-zA-Z0-9]+)<\/status>/", $dbuser, $userstatus);
		if ($dbstatus[1] != 'ok') {
			$this->error['dbUserNotCreated'] = 'database user was not created.';
			$this->error['extra'] = $dbuser;
			$this->errorDie();
		}
		preg_match("/<id>([0-9]+)<\/id>/", $dbpacket, $dbusermatch);
		$dbuserid = $dbusermatch[1];
		$this->settings['dbuserid'] = $dbuserid;
		return true;
	}

/**
 * runDatabaseCommand
 *
 * Each database command needs the option to retry up to three times before giving up. This will use
 * the base runCommand option, but then will fail if the process returns an error.
 *
 * @param string $command The command to run on the database
 * @param int $attempts Optional number of retries (default is 3)
 * @param int $usleep The length to wait in between retried (default 10 seconds)
 * @return void
 * @access public
 **/
	public function runDatabaseCommand($command = null, $attempts = 3, $usleep = 10000000) {
		if (!$command) {
			return false;
		}
		$output = null;
		while ($attempts > 0) {
			$output = $this->runCommand($command, true);
			if (preg_match('/mysqladmin/', $output)) {
				usleep(10000000);
				$attempts--;
			} else {
				return $output;
			}
		}
		$this->debug['error'] = 'We could not connect to mysqladmin. After ' . $attempts . ' we could not communication with the database server.';
		$this->debug['process'][] = 'MYSQLADMIN failed the following command: ' . $command;
		$this->debug['process'][] = 'MYSQLDMIN error: ' . $output;
		$this->error['extra'] = $output;
	}

/**
 * Run Command
 *
 * Run the specified command using passthru to get and return the results.
 *
 * @param string $command The command to run on the server.
 * @return string $output The raw output of the command.
 **/
	public function runCommand($command = null, $redirect = false) {
		if (!$command) {
			return false;
		}
		if ($redirect) {
			$command .= ' 2>&1';
		}
		ob_start();
		passthru($command);
		$output = ob_get_contents();
		ob_end_clean();
		return $output;
	}

/**
 * Error
 *
 * Call an error to pass back to the caller.
 *
 * @return void
 *
 **/
	public function errorDie() {
		$this->error['status'] = 'error';
		$this->error['debug'] = $this->debug;
		$this->error = json_encode($this->error);
		die($this->error);
	}

/**
 * Remove Directory Recursively
 *
 * @return void
 */
	public function removeDirectoryRecursive($directory = null) {
		if (!$directory || !is_dir($directory)) {
			return false;
		}
		$directoryHandle = @opendir($directory);
		if ($directoryHandle) {
			while ($file = @readdir($directoryHandle)) {
				$filename = $directory . '/' . $file;
				if ($file == '.' || $file == '..') {
					continue;
				} elseif (is_dir($filename) && !is_link($filename)) {
					$this->removeDirectoryRecursive($filename);
				} else {
					// In this context, we should have already removed all
					// the child directories and files.  Leaving any extra
					// files guarantees that we won't totally bleep up
					// someone's account.  In other news, we didn't have
					// nice things because of this.
				}
			}
			closedir($directoryHandle);
			@rmdir($directory);
		}
	}

}
