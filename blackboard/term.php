#!/usr/bin/php
<?php
/**
 * @package    BlackboardCli
 *
 * @copyright  Copyright (C) 2012 Omar E. Ramos, Imperial Valley College. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

// We are a valid Joomla entry point.
define('_JEXEC', 1);

// Setup the base path related constant.
define('JPATH_BASE', dirname(__FILE__));
define('JPATH_SITE', JPATH_BASE);

// Bootstrap the application.
require dirname(__FILE__).'/bootstrap.php';

/**
 * Term CLI App for Blackboard.
 *
 * This application will sync Terms
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardTermCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'term';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Term';

	/**
	 * Class constructor.
	 *
	 * This constructor invokes the parent JApplicationCli class constructor,
	 * and then creates a connector to the database so that it is
	 * always available to the application when needed.
	 *
	 * @since   11.3
	 * @throws  JDatabaseException
	 */
	public function __construct()
	{
		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();
	}

	/**
	 * Execute the application.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */
	protected function doExecute()
	{
		parent::doExecute();
	}

	/**
	 * Method to perform any additional queries
	 * and add any useful statistical information
	 * to the log.
	 *
	 * @return void
	 */
	public function displayStatistics(){}

	/**
	 * Performs the syncing functions from
	 * Banner to the local MySQL database.
	 *
	 * Does not send anything to Blackboard.
	 *
	 * @return void
	 */
	public function sync()
	{
		if (!empty($this->action))
		{
			$this->logAndPrintLn("You've entered in '" . $this->action . "' as the action to perform.");

			$this->logAndPrintLn("Attempting to Create Blackboard Term Table");
			$this->createTermsTable();

			if ($this->oracleConnect())
			{
				$this->logAndPrintLn("Setting Status for Existing Rows to 'disabled' (Disabled) by Default...");
				$this->setDefaultRowStatus();

				$this->logAndPrintLn("Attempting to Sync Terms...");
				$this->syncTerms();

				if (!empty($this->failedInsertsLog))
				{
					$this->logAndPrintLn(count($this->failedInsertsLog) . " queries failed during the Sync...");
					foreach($this->failedInsertsLog as $badQuery)
					{
						$this->logAndPrintLn($badQuery);
					}
				}
			}
		}
	}

	/**
	 * Creates the Terms Table in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createTermsTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`term_key` varchar(50) NOT NULL,
					`term_name` varchar(75) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`start_date` varchar(24) NOT NULL,
					`end_date` varchar(24) NOT NULL,
					`duration` varchar(30) NOT NULL,
					`available_ind` varchar(3) NOT NULL,
					UNIQUE KEY  (`term_key`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Terms Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncTerms()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Class Roster Information for Current Term:
		$query = "SELECT stvterm_code, stvterm_desc, to_char(stvterm_start_date, 'YYYYMMDD') stvterm_start_date, to_char(stvterm_end_date, 'YYYYMMDD') stvterm_end_date
		          FROM STVTERM
		          WHERE stvterm_end_date > (SYSDATE - :days_to_continue_showing)
		          AND STVTERM_CODE != '999999'";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$daysToContinueShowing = $this->get('daysToContinueShowing', 45);
		$oracle->getQuery()->bind(':days_to_continue_showing', $daysToContinueShowing);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' term records.');

		foreach($rows as &$row)
		{
			$this->syncTermRecord($row);
		}

		$this->logAndPrintLn("\n".'Processed ' . count($rows) . ' term records.');
	}

	/**
	 * Add/Updates an individual Term Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncTermRecord(&$row)
	{
		$this->logAndPrintLn('Syncing ' . $row['STVTERM_CODE'] . '...');

		$duration = 'range';
		$row_status = 'enabled';
		$available_ind = 'Y';

		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`term_key`, `term_name`, `row_status`, `start_date`, `end_date`, " .
						 "`duration`, `available_ind`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['STVTERM_CODE']) . ", " . $this->dbo->q($row['STVTERM_DESC']) . ", " . $this->dbo->q($row_status) . ", " . $this->dbo->q($row['STVTERM_START_DATE']) . ", " . $this->dbo->q($row['STVTERM_END_DATE']) . ", " .
					 	 "" . $this->dbo->q($duration) . ", " . $this->dbo->q($available_ind) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`term_name` = " . $this->dbo->q($row['STVTERM_DESC']) . ", `row_status` = " . $this->dbo->q($row_status) . ", `start_date` = " . $this->dbo->q($row['STVTERM_START_DATE']) . ", `end_date` = " . $this->dbo->q($row['STVTERM_END_DATE']) . ", " .
					 	 "`duration` = " . $this->dbo->q($duration) . ", `available_ind` = " . $this->dbo->q($available_ind);

		$this->dbo->setQuery($query);
		$result = $this->dbo->execute();

		if (!$result)
		{
			$this->failedInsertsLog[] = 'Error: ' . $this->dbo->getError() . ', Query: ' . $query;
		}
	}

	/**
	 * Performs the sending functions from
	 * the local MySQL database to Blackboard.
	 *
	 * @return void
	 *
	 */
	public function send()
	{
		if (!empty($this->action))
		{
			$this->logAndPrintLn("Attempting to Retrieve Terms Records...");
			$this->retrieveRecords();
			$this->logAndPrintLn("Retrieved " . count($this->records) . " Term Records to include...");
			$this->logAndPrintLn("Generating Terms File String...");
			$this->generateFileString();
			$this->logAndPrintLn("Writing Terms File String to a file...");
			$result = $this->writeString();
			if ($result)
			{
				// If we successfully wrote the file then send it:
				$this->sendTermString();
			}
		}
	}

	/**
	 * Generates the Blackboard Output String
	 *
	 * @return void
	 */
	public function generateFileString()
	{
		$headerFields = array('EXTERNAL_TERM_KEY', 'NAME', 'ROW_STATUS', 'START_DATE', 'END_DATE', 'DURATION', 'AVAILABLE_IND');
		$recordFields = array('term_key', 'term_name', 'row_status', 'start_date', 'end_date', 'duration', 'available_ind');

		$this->generateFileStringHelper($headerFields, $recordFields);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendTermString()
	{
		$serviceUsername = $this->getTermStoreUsername();
		$servicePassword = $this->getTermStorePassword();
		$serviceStoreURL = $this->getTermStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardTermCli')->execute();
