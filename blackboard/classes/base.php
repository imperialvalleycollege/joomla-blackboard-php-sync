<?php
/**
 * @package    BlackboardCli
 *
 * @copyright  Copyright (C) 2012 Omar E. Ramos, Imperial Valley College. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or die;

/**
 * Base CLI App for Blackboard.
 *
 * This class provides the generic
 * functionality used by the concrete
 * syncing classes.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
abstract class BlackboardBaseCli extends JApplicationCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'base';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Base';

	/**
	 * A database object for the application to use.
	 *
	 * @var    JDatabase
	 * @since  11.3
	 */
	protected $dbo = null;

	/**
	 * An Oracle database object for the application to use.
	 *
	 * @var    JDatabase
	 * @since  11.3
	 */
	protected $oracle = null;

	/**
	 * Holds the start execution time
	 * for the script.
	 *
	 * @var float
	 */
	protected $time = null;

	/**
	 * Array to hold log information
	 *
	 * @var array
	 */
	protected $log = array();

	/**
	 * The action(s) to perform
	 * provided by the user via the
	 * --action option.
	 *
	 * Can be sync, send, or sync-and-send in most cases.
	 * In the course sync you additional have the merge
	 * and unmerge actions.
	 *
	 * @var string
	 */
	protected $action = '';

	/**
	 * Holds the CRN List provided
	 * to the merge/unmerge actions
	 * for the Blackboard Course Sync App.
	 *
	 * Initially it will be a string, but
	 * will be converted and stored as an
	 * array.
	 *
	 * @var mixed
	 */
	protected $crnList = '';

	/**
	 * Holds the current Term Code
	 * provided by the user via the
	 * --termcode option.
	 *
	 * Multiple terms can be provided
	 * separated by comma.
	 *
	 * @var string
	 */
	protected $termCode = '';

	/**
	 * The term code string is processed
	 * into an array and stored here.
	 *
	 * @var mixed
	 */
	protected $terms = array();


	/**
	 * Holds the current Blackboard Domain Setting
	 *
	 * Primarily the default value specified in
	 * the configuration would be used, but you can
	 * also use the --mode parameter and switch between
	 * your 'test' and 'production' Blackboard instances.
	 *
	 * @var string
	 */
	protected $blackboardDomain = '';

	/**
	 * Records array for rows retrieved
	 * from the local MySQL database while
	 * executing the send() method.
	 *
	 * @var array
	 */
	protected $records = array();

	/**
	 * Blackboard Output String
	 * which holds all lines for the
	 * sync file that will be sent to
	 * Blackboard.
	 *
	 * @var string
	 */
	protected $outputString = '';

	/**
	 * Blackboard Output File Path
	 * where the output string is stored
	 * at the end of execution of the
	 * send() method.
	 *
	 * @var string
	 */
	protected $outputFilePath = '';

	/**
	 * Log for Failed Database Queries
	 *
	 * If for any reason a database query into the
	 * local database fails, then it will get
	 * logged in this array.
	 *
	 * @var array
	 */
	protected $failedInsertsLog = array();

	/**
	 * Constructor Function for the Base Class.
	 *
	 * Initializes the object, gets the starting execution time
	 * and creates a JDatabase instance.
	 *
	 * Also, processes the custom 'mode' (production/test) provided
	 * by the user via the --mode option.
	 *
	 * @return void
	 */
	public function __construct()
	{
		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();
		// initialize the time value
		$this->time = microtime(true);

		jimport('joomla.database.database');

		// Note, this will throw an exception if there is an error
		// creating the database connection.
		$this->dbo = JDatabase::getInstance(
			array(
				'driver' => $this->get('dbDriver'),
				'host' => $this->get('dbHost'),
				'user' => $this->get('dbUser'),
				'password' => $this->get('dbPass'),
				'database' => $this->get('dbName'),
				'prefix' => $this->get('dbPrefix'),
			)
		);

		// Can be equal to 'test' or 'production'
		// if not given default configuration value
		// is used:
		$mode = $this->input->get('mode');

		$this->setBlackboardMode($mode);
	}

	/**
	 * Handles the user input, processes it
	 * and executes the application.
	 *
	 * Also writes the log file and sends the
	 * notification email.
	 *
	 * @return void
	 */
	protected function doExecute()
	{
		// Print a blank line.
		$this->logAndPrintLn();
		$this->logAndPrintLn('BLACKBOARD ' . JString::strtoupper($this->friendlyName) . ' SYNC');
		$this->logAndPrintLn('============================');
		$this->logAndPrintLn();

		// Retrieve the provided CLI parameters:
		// Example: script.php --action sync-and-send --termcode 201320
		$this->action = $this->input->get('action');
		$this->termCode = $this->input->get('termcode', '', 'STRING');
		$this->crnList = $this->input->get('crns', '', 'STRING');

		// Prep the Term List Array if multiple terms were provided:
		if (!empty($this->termCode))
		{
			$this->terms = explode(',', str_replace(' ', '', $this->termCode));
		}

		if (!empty($this->crnList))
		{
			// Process CRN List:
			$crns = explode(',', $this->crnList);

			foreach($crns as &$crn)
			{
				$crn = (int) trim($crn);
			}

			sort($crns, SORT_NUMERIC);

			$this->crnList = array_unique($crns);

			$this->logAndPrintLn('Sanitized and Sorted CRN List: ' . "'" . implode(',', $this->crnList) . "'");
		}

		if ($this->action === 'sync' || $this->action === 'sync-and-send')
		{
			$this->sync();
			$syncTime = microtime(true);
			$this->logAndPrintLn("\nSync Processing Time: " . round($syncTime - $this->time, 3) . " seconds.");
		}

		if ($this->action === 'send' || $this->action === 'sync-and-send')
		{
			$this->send();
			if (isset($syncTime))
			{
				$this->logAndPrintLn("\nSend Processing Time: " . round(microtime(true) - $syncTime, 3) . " seconds.");
			}
		}

		if ($this->action === 'clean' || get_class($this) === 'BlackboardCleanCli')
		{
			if (method_exists($this, 'clean'))
			{
				$days = $this->input->get('days');
				$this->setDaysToKeep($days);

				$this->clean();
			}
			if (isset($syncTime))
			{
				$this->logAndPrintLn("\nSend Processing Time: " . round(microtime(true) - $syncTime, 3) . " seconds.");
			}
		}

		if ($this->action === 'merge')
		{
			if (method_exists($this, 'merge'))
			{
				$this->merge();
			}
			if (isset($syncTime))
			{
				$this->logAndPrintLn("\nSend Processing Time: " . round(microtime(true) - $syncTime, 3) . " seconds.");
			}
		}

		if ($this->action === 'unmerge')
		{
			if (method_exists($this, 'unmerge'))
			{
				$this->unmerge();
			}
			if (isset($syncTime))
			{
				$this->logAndPrintLn("\nSend Processing Time: " . round(microtime(true) - $syncTime, 3) . " seconds.");
			}
		}

		$this->logAndPrintLn("\nTotal Processing Time: " . round(microtime(true) - $this->time, 3) . " seconds.");

		$this->displayStatistics();

		$this->logAndPrintLn('Writing log file...');
		if (!$this->writeLogFile())
		{
			$this->logAndPrintLn('Failed to write log file! Please check permissions on the log folder.');
		}


		$this->logAndPrintLn('Emailing log file...');
		if (!$this->emailLogFile())
		{
			$this->logAndPrintLn('Failed to send notification email! Please check your email settings.');
		}
	}

	protected function clean()
	{
		if (!empty($this->action) || get_class($this) === 'BlackboardCleanCli')
		{
			jimport('joomla.filesystem.folder');
			jimport('joomla.filesystem.file');
			jimport('joomla.filesystem.path');

			// Paths to clean:
			$filePath = JPath::clean(JPATH_BASE . '/files/');
			$logPath = JPath::clean(JPATH_BASE . '/logs/');

			$currentTime = time();
			$daysToKeep = $this->get('daysToKeep', 7);
			$keepStartTime = $currentTime - ($daysToKeep * 3600 * 24);

			$this->logAndPrintLn();
			$this->logAndPrintLn('Currently cleaning out ' . $filePath);
			$this->logAndPrintLn('============================');
			$this->logAndPrintLn();

			$files = JFolder::files($filePath, '.', false, true);
			foreach($files as $file)
			{
				$modificationTime = filemtime($file);
				if ($modificationTime < $keepStartTime)
				{
					// File was created within the last seven days and should be kept:
					//$this->logAndPrintLn($file . ' modified on: ' . $modificationTime);
					$cleanedPath = JPath::clean($file);
					if (JFile::delete($cleanedPath))
					{
						$this->logAndPrintLn('Successfully deleted ' . $cleanedPath);
					}
					else
					{
						$this->logAndPrintLn('Failed to delete ' . $cleanedPath);
					}
				}

			}

			$this->logAndPrintLn();
			$this->logAndPrintLn('Currently cleaning out ' . $logPath);
			$this->logAndPrintLn('============================');
			$this->logAndPrintLn();

			$logs = JFolder::files($logPath, '.', false, true);
			foreach($logs as $logFile)
			{
				$modificationTime = filemtime($logFile);
				if ($modificationTime < $keepStartTime)
				{
					// File was created within the last seven days and should be kept:
					//$this->logAndPrintLn($file . ' modified on: ' . $modificationTime);
					$cleanedPath = JPath::clean($logFile);
					if (JFile::delete($cleanedPath))
					{
						$this->logAndPrintLn('Successfully deleted ' . $cleanedPath);
					}
					else
					{
						$this->logAndPrintLn('Failed to delete ' . $cleanedPath);
					}
				}

			}
		}
	}

	/**
	 * Method to perform any additional queries
	 * and add any useful statistical information
	 * to the log.
	 *
	 * @return void
	 */
	abstract public function displayStatistics();

	/**
	 * Performs the syncing functions from
	 * Banner to the local MySQL database.
	 *
	 * Does not send anything to Blackboard.
	 *
	 * @return void
	 */
	abstract public function sync();

	/**
	 * Performs the sending functions from
	 * the local MySQL database to Blackboard.
	 *
	 * @return void
	 *
	 */
	abstract public function send();

	/**
	 * Connects to your Banner Database using
	 * Joomla's Oracle Driver.
	 *
	 * @return JDatabaseDriverOracle
	 */
	public function oracleConnect()
	{
		if (is_null($this->oracle))
		{
			// Create Oracle DBO
			$options = array('driver' => 'oracle',
				             'host' => $this->get('oracleHost'),
				             'user' => $this->get('oracleUsername'),
				             'password' => $this->get('oraclePassword'),
				             'database' => $this->get('oracleServiceName'),
				             'port' => $this->get('oraclePort'));

			$this->oracle = JDatabase::getInstance($options);
		}

		return $this->oracle;
	}

	/**
	 * Puts the program in Blackboard
	 * Production or Test mode.
	 *
	 * This changes the Blackboard service submission
	 * URL to the defined test domain or the
	 * production domain.
	 *
	 * @param string $mode
	 *
	 * @return void
	 */
	public function setBlackboardMode($mode)
	{
		switch($mode)
		{
			case 'test':
				$this->set('blackboardMode', 'test');
				$this->blackboardDomain = $this->get('blackboardTestDomain');
				$this->logAndPrintLn('Using user provided mode: TEST');
				break;
			case 'production':
				$this->set('blackboardMode', 'production');
				$this->blackboardDomain = $this->get('blackboardDomain');
				$this->logAndPrintLn('Using user provided mode: PRODUCTION');
				break;
			default:
				$this->logAndPrintLn('Using default mode: ' . $this->get('blackboardMode'));
				if ($this->get('blackboardMode') === 'test')
				{
					$this->blackboardDomain = $this->get('blackboardTestDomain');
				}
				else if ($this->get('blackboardMode') === 'production')
				{
					$this->blackboardDomain = $this->get('blackboardDomain');
				}
				break;
		}

		$this->logAndPrintLn('Blackboard Domain is now set to: ' . $this->blackboardDomain);
	}

	/**
	 * Sets the number of days to keep option used
	 * during the clean action.
	 *
	 * This changes the number of days to keep for generated files
	 * (logs and copies of the sync files sent to Blackboard).
	 *
	 * @param string $days
	 *
	 * @return void
	 */
	public function setDaysToKeep($days)
	{
		if (!empty($days))
		{
			$days = (int) $days;
		}
		else
		{
			$days = (int) $this->get('daysToKeep', 7);
		}

		$days = abs($days);
		$this->set('daysToKeep', $days);

		$this->logAndPrintLn('Days To Keep Option is now set to: ' . $this->get('daysToKeep'));
	}

	/**
	 * Logging method
	 *
	 * @param mixed $stringOrArray
	 * @param bool $nl
	 *
	 * @return void
	 */
	public function logAndPrintLn($stringOrArray = '', $nl = true)
	{
		if (is_array($stringOrArray))
		{
			$this->log[] = 'List:';
			$this->out('List:');
			foreach($stringOrArray as $key => $string)
			{
				$this->log[] = $key . ': ' . $string;
				$this->out($key . ': ' . $string, $nl);
			}
		}
		else
		{
			$this->log[] = $stringOrArray;
			$this->out($stringOrArray, $nl);
		}
	}

	/**
	 * Returns the Log Array.
	 *
	 * @return array
	 */
	public function getLog()
	{
		return $this->log;
	}

	/**
	 * Gets the default table name for the
	 * current syncing process.
	 *
	 * @return string
	 */
	public function getTableName()
	{
		$prefix = $this->pluralize($this->syncType);
		if (!empty($this->termCode))
		{
			$prefix .= '_' . $this->termCode;
		}
		return $prefix . "_sync";
	}

	/**
	 * Very simply Pluralizer Method
	 * Not suitable for every need but should
	 * work well for the situations needed
	 * for the Blackboard CLI Apps
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	public static function pluralize($string)
	{
		$length = JString::strlen($string);
		if (JString::substr($string, -1, 1) === 'y')
		{
			$plural = JString::substr($string, 0, $length - 1) . 'ies';
		}
		else
		{
			$plural = $string . 's';
		}

		return $plural;
	}

	/**
	 * Returns the Current Date Time
	 * with weird characters removed
	 * to make it safe for usage in creating
	 * the log/output filenames
	 *
	 * @param bool $performStringReplace
	 *
	 * @return string
	 */
	public function getCurrentDateTime($performStringReplace = true)
	{
		$date = JFactory::getDate('now', $this->get('timezone'))->toSql(true);
		if ($performStringReplace)
		{
			return str_replace(array(' ', ':'), '_', $date);
		}
		else
		{
			return $date;
		}
	}

	/**
	 * Returns the correct Course Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getCourseStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/course/store';
	}

	/**
	 * Returns the correct Course Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseServiceTestUsername');
			case 'production':
				return $this->get('courseServiceUsername');
		}
	}

	/**
	 * Returns the correct Course Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseServiceTestPassword');
			case 'production':
				return $this->get('courseServicePassword');
		}
	}

	/**
	 * Returns the correct Course Category Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getCourseCategoryStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/coursecategory/store';
	}

	/**
	 * Returns the correct Course Category Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseCategoryStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseCategoryServiceTestUsername');
			case 'production':
				return $this->get('courseCategoryServiceUsername');
		}
	}

	/**
	 * Returns the correct Course Category Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseCategoryStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseCategoryServiceTestPassword');
			case 'production':
				return $this->get('courseCategoryServicePassword');
		}
	}

	/**
	 * Returns the correct Course Category Membership Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getCourseCategoryMembershipStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/coursecategorymembership/store';
	}

	/**
	 * Returns the correct Course Category Membership Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseCategoryMembershipStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseCategoryMembershipServiceTestUsername');
			case 'production':
				return $this->get('courseCategoryMembershipServiceUsername');
		}
	}

	/**
	 * Returns the correct Course Category Membership Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseCategoryMembershipStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseCategoryMembershipServiceTestPassword');
			case 'production':
				return $this->get('courseCategoryMembershipServicePassword');
		}
	}

	/**
	 * Returns the correct Course Membership Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getCourseMembershipStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/membership/store';
	}

	/**
	 * Returns the correct Course Membership Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseMembershipStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseMembershipServiceTestUsername');
			case 'production':
				return $this->get('courseMembershipServiceUsername');
		}
	}

	/**
	 * Returns the correct Course Membership Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getCourseMembershipStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('courseMembershipServiceTestPassword');
			case 'production':
				return $this->get('courseMembershipServicePassword');
		}
	}

	/**
	 * Returns the correct Course Standard Association Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getCourseStandardAssociationStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/standardsassociation/store';
	}

	/**
	 * Returns the correct Person Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getPersonStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/person/store';
	}

	/**
	 * Returns the correct Person Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getPersonStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('personServiceTestUsername');
			case 'production':
				return $this->get('personServiceUsername');
		}
	}

	/**
	 * Returns the correct Person Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getPersonStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('personServiceTestPassword');
			case 'production':
				return $this->get('personServicePassword');
		}
	}

	/**
	 * Returns the correct Term Store URL
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $domain
	 *
	 * @return string
	 */
	public function getTermStoreUrl($domain = null)
	{
		if (empty($domain))
		{
			$domain = $this->blackboardDomain;
		}

		return	'https://' . $domain . '/webapps/bb-data-integration-flatfile-BBLEARN/endpoint/term/store';
	}

	/**
	 * Returns the correct Term Store Username
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getTermStoreUsername($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('termServiceTestUsername');
			case 'production':
				return $this->get('termServiceUsername');
		}
	}

	/**
	 * Returns the correct Term Store Password
	 * based on the current Blackboard Mode
	 * (production/test).
	 *
	 * @param mixed $mode
	 *
	 * @return string
	 */
	public function getTermStorePassword($mode = null)
	{
		if (empty($mode))
		{
			$mode = $this->get('blackboardMode');
		}

		switch($mode)
		{
			case 'test':
				return $this->get('termServiceTestPassword');
			case 'production':
				return $this->get('termServicePassword');
		}
	}

	/**
	 * Sets the default row status to be disabled
	 * This ensures that old records are disabled
	 * and only new/current records are enabled
	 * in Blackboard.
	 *
	 * @return  void
	 */
	public function setDefaultRowStatus()
	{
		// Query to set all students to be dropped prior to the next resync:
		$query = 'UPDATE `' . $this->getTableName() . '` ' .
				 'SET `row_status` = \'disabled\'';
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Populates the internal $records
	 * variable using the table name
	 * for the current sync process.
	 *
	 * @return void
	 */
	public function retrieveRecords()
	{
		$query = 'SELECT *
		          FROM `' . $this->getTableName() . '`';

		$this->dbo->setQuery($query);
		$this->records = $this->dbo->loadAssocList();
	}

	/**
	 * Provided a list of Header and Record
	 * Fields this will genereate the necessary
	 * Blackboard output string and use the
	 * separator you defined in the configuration.
	 *
	 * @param mixed $headerFields
	 * @param mixed $recordFields
	 */
	protected function generateFileStringHelper($headerFields = array(), $recordFields = array())
	{
		$output = '';

		$headerLine = implode($this->get('blackboardFieldSeparator', '|'), $headerFields);

		$output .= $headerLine . "\r\n";

		if (!empty($this->records))
		{
			foreach($this->records as $record)
			{
				$data = array();
				foreach($recordFields as $recordField)
				{
					$data[] = $record[$recordField];
				}
				$output .= implode($this->get('blackboardFieldSeparator', '|'), $data);
				$output .= "\r\n";
			}
		}

		$this->outputString = utf8_encode(rtrim($output));
	}

	/**
	 * Generic Write String method
	 *
	 * @return bool
	 */
	public function writeString()
	{
		$prefix = $this->pluralize($this->syncType);
		if (!empty($this->termCode))
		{
			$prefix .= '_' . $this->termCode;
		}
		return $this->writeStringHelper($this->outputString, $prefix);
	}

	/**
	 * Write String Helper method
	 *
	 * @param string $string
	 * @param string $prefix
	 *
	 * @return bool
	 */
	protected function writeStringHelper(&$string, $prefix)
	{
		jimport('joomla.filesystem.path');
		jimport('joomla.filesystem.file');
		$path =  JPath::clean(JPATH_BASE . '/files/' . $prefix . '_' . $this->getCurrentDateTime() . '.txt');
		if (!JFile::write($path, $string))
		{
			// Log failed file write:
			$this->logAndPrintLn('Failed writing the string to ' . $path);
			return false;
		}
		return true;
	}

	/**
	 * Generic Send String Method
	 *
	 * @param string $username
	 * @param string $password
	 * @param string $serviceURL
	 *
	 * @return void
	 */
	public function sendString($username, $password, $serviceURL)
	{
		return $this->sendStringHelper($this->outputString, $username, $password, $serviceURL);
	}

	/**
	 * Send String Helper Method
	 *
	 * @param string $string
	 * @param string $username
	 * @param string $password
	 * @param string $serviceURL
	 *
	 * @return void
	 */
	protected function sendStringHelper(&$string, $username, $password, $serviceURL)
	{
		$registry = new JRegistry();
		try
		{
			$transport = JHttpFactory::getAvailableDriver($registry);
		}
		catch(RuntimeException $e)
		{
			// Most likely the user can't use the stream or socket transports
			// so let's default to cURL:
			$transport = JHttpFactory::getAvailableDriver($registry, 'curl');
		}

		$this->logAndPrintLn('Currently using the Joomla ' . get_class($transport) . ' transport class to perform the submission to Blackboard');

		$client = new JHttp(null, $transport);

		$headers = array();

		$encodedCredentials = base64_encode($username . ':' . $password);
		$headers['Authorization'] = 'Basic ' . $encodedCredentials;
		$headers['Content-Type'] = 'text/plain';

		$this->logAndPrintLn('Currently posting string to: ' . $serviceURL);
		$response = $client->post($serviceURL, $string, $headers);

		$this->logAndPrintLn('Server Response Code: ' . $response->code);
		if ($response->code === 200)
		{
			$this->logAndPrintLn('File Submitted Successfully!');
		}
		else
		{
			$this->logAndPrintLn('There may have been an issue submitting the file.');
			$this->logAndPrintLn('Server Response Headers:');
			$this->logAndPrintLn($response->headers);
		}
	}

	/**
	 * Write Log File Method.
	 * Automatically generates the needed prefix
	 * according to the current sync type.
	 *
	 * @return boolean
	 */
	public function writeLogFile()
	{
		$prefix = $this->pluralize($this->syncType) . '_' . $this->action;

		return $this->writeLogFileHelper($prefix);
	}

	/**
	 * Log File Helper which takes in a
	 * string prefix to be used for the
	 * created file.
	 *
	 * @param string $prefix
	 *
	 * @return boolean
	 */
	protected function writeLogFileHelper($prefix)
	{
		jimport('joomla.filesystem.path');
		jimport('joomla.filesystem.file');
		$this->outputFilePath = JPath::clean(JPATH_BASE . '/logs/' . $prefix . '_' . $this->getCurrentDateTime() . '.txt');
		$this->logAndPrintLn('Currently writing log file to: ');
		$this->logAndPrintLn($this->outputFilePath);
		return JFile::write($this->outputFilePath, implode("\n", $this->log));
	}

	/**
	 * Emails the log file
	 * using the specified friendly
	 * name for the current sync process
	 *
	 * @return bool
	 */
	public function emailLogFile()
	{
		$subjectPrefix = 'Blackboard ' . $this->friendlyName . ' Sync Log:';
		return $this->emailLogHelper($subjectPrefix);
	}

	/**
	 * Email Log Helper File
	 * Takes in a prefix and sends out an email
	 * Makes use of the notification and SMTP
	 * configuration variables you specify
	 *
	 * @param mixed $subjectPrefix
	 * @return reference
	 */
	public function emailLogHelper($subjectPrefix)
	{
		$isHTML = true;
		$subject = $subjectPrefix . ' ' . $this->getCurrentDateTime(false);
		$body = '<div style="font-family: Courier, monospace">' . implode("<br />", $this->log) . '</div>';
		$from = $this->get('notificationFromEmail');
		$fromName = $this->get('notificationFromName');

		$recipients = JString::trim($this->get('notificationToEmail'));
		if (!empty($recipients))
		{
			$recipients = explode(',', str_replace(' ', '', $recipients));
		}

		$mail = JFactory::getMailer();

		if ($this->get('useSMTP', false))
		{
			$mail->useSMTP(true, $this->get('smtpHost'), $this->get('smtpUsername'), $this->get('smtpPassword'), $this->get('smtpSecure'), $this->get('smtpPort', 25));
		}

		return $mail->sendMail($from, $fromName, $recipients, $subject, $body, $isHTML, null, null, null, $from, $fromName);
	}
}
