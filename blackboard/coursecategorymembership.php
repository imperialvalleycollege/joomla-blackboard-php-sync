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
 * Course Category Membership CLI App for Blackboard.
 *
 * This application will sync Course Category Memberships
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardCourseCategoryMembershipCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'coursecategorymembership';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Course Category Membership';

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

			$this->logAndPrintLn("Attempting to Create Blackboard Course Category Membership Table");
			$this->createCourseCategoryMembershipTable();

			if ($this->oracleConnect())
			{
				$this->logAndPrintLn("Setting Status for Existing Rows to 'disabled' (Disabled) by Default...");
				$this->setDefaultRowStatus();

				$this->logAndPrintLn("Attempting to Sync Course Category Memberships...");
				$this->syncCourseCategoryMemberships();
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
	 * Creates the Course Category Memberships in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createCourseCategoryMembershipTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`course_key` varchar(50) NOT NULL,
					`category_key` varchar(50) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`available_ind` varchar(3) NOT NULL,
					UNIQUE KEY  (`course_key`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Course Category Memberships Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncCourseCategoryMemberships()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Course Category Memberships Information:
		$query = "select ssbsect_term_code || '.' || ssbsect_subj_code category_key, ssbsect_term_code || '.' || ssbsect_crn course_key
		            from ssbsect, sirasgn
		            where ssbsect_term_code in (SELECT stvterm_code
		                                        FROM STVTERM
		                                        WHERE stvterm_end_date > (SYSDATE - :days_to_continue_showing)
		                                        AND STVTERM_CODE != '999999')
		            and sirasgn_term_code = ssbsect_term_code
		            and sirasgn_crn = ssbsect_crn";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$daysToContinueShowing = $this->get('daysToContinueShowing', 45);
		$oracle->getQuery()->bind(':days_to_continue_showing', $daysToContinueShowing);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' course category membership records.');
		foreach($rows as &$row)
		{
			$this->syncCourseCategoryMembershipRecord($row);
		}

		$this->logAndPrintLn("\n".'Processed ' . count($rows) . ' course category membership records.');
	}

	/**
	 * Add/Updates an individual Course Category Membership Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncCourseCategoryMembershipRecord(&$row)
	{
		$this->logAndPrintLn('Syncing ' . $row['CATEGORY_KEY'] . ' - ' . $row['COURSE_KEY'] . '...');

		$row_status = 'enabled';
		$available_ind = 'Y';

		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`course_key`, `category_key`, `row_status`, `available_ind`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['COURSE_KEY']) . ", " . $this->dbo->q($row['CATEGORY_KEY']) . ", " . $this->dbo->q($row_status) . ", " . $this->dbo->q($available_ind) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`category_key` = " . $this->dbo->q($row['CATEGORY_KEY']) . ", `row_status` = " . $this->dbo->q($row_status) . ", `available_ind` = " . $this->dbo->q($available_ind);

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
			$this->logAndPrintLn("Attempting to Retrieve Course Category Membership Records...");
			$this->retrieveRecords();
			$this->logAndPrintLn("Retrieved " . count($this->records) . " Course Category Membership Records to include...");
			$this->logAndPrintLn("Generating Course Category Membership File String...");
			$this->generateFileString();
			$this->logAndPrintLn("Writing Course Category Membership File String to a file...");
			$result = $this->writeString();
			if ($result)
			{
				// If we successfully wrote the file then send it:
				$this->sendCourseCategoryMembershipString();
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
		$headerFields = array('EXTERNAL_COURSE_KEY', 'EXTERNAL_CATEGORY_KEY', 'ROW_STATUS', 'AVAILABLE_IND');
		$recordFields = array('course_key', 'category_key', 'row_status', 'available_ind');

		$this->generateFileStringHelper($headerFields, $recordFields);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendCourseCategoryMembershipString()
	{
		$serviceUsername = $this->getCourseCategoryMembershipStoreUsername();
		$servicePassword = $this->getCourseCategoryMembershipStorePassword();
		$serviceStoreURL = $this->getCourseCategoryMembershipStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardCourseCategoryMembershipCli')->execute();
