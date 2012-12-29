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
 * Course Category CLI App for Blackboard.
 *
 * This application will sync Course Categories
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardCourseCategoryCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'coursecategory';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Course Category';

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

			$this->logAndPrintLn("Attempting to Create Blackboard Course Category Table");
			$this->createCourseCategoriesTable();

			if ($this->oracleConnect())
			{
				$this->logAndPrintLn("Setting Status for Existing Rows to 'disabled' (Disabled) by Default...");
				$this->setDefaultRowStatus();

				$this->logAndPrintLn("Attempting to Sync Course Categories...");
				$this->syncCourseCategories();

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
	 * Creates the Course Categories Table in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createCourseCategoriesTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`category_key` varchar(50) NOT NULL,
					`category_title` varchar(75) NOT NULL,
					`parent_category_key` varchar(50) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`available_ind` varchar(3) NOT NULL,
					UNIQUE KEY  (`category_key`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Course Categories Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncCourseCategories()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Class Roster Information for Current Term:
		$query = "select distinct(ssbsect_term_code) key, stvterm_desc || ' Courses' title, '' parent
		            from ssbsect,
		                 stvterm
		            where ssbsect_term_code in (SELECT stvterm_code
		                                        FROM STVTERM
		                                        WHERE stvterm_end_date > (SYSDATE - :days_to_continue_showing)
		                                        AND STVTERM_CODE != '999999')
		            and ssbsect_term_code = stvterm_code
		            UNION
		            select ssbsect_term_code || '.' || ssbsect_subj_code key, stvsubj_desc title, ssbsect_term_code parent
		            from ssbsect,
		                 stvsubj,
		                 sirasgn
		            where ssbsect_term_code in (SELECT stvterm_code
		                                        FROM STVTERM
		                                        WHERE stvterm_end_date > (SYSDATE - :days_to_continue_showing)
		                                        AND STVTERM_CODE != '999999')
		            and ssbsect_subj_code = stvsubj_code
		            and sirasgn_term_code = ssbsect_term_code
		            and sirasgn_crn = ssbsect_crn";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$daysToContinueShowing = $this->get('daysToContinueShowing', 45);
		$oracle->getQuery()->bind(':days_to_continue_showing', $daysToContinueShowing);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' course category records.');
		foreach($rows as &$row)
		{
			$this->syncCourseCategoryRecord($row);
		}

		$this->logAndPrintLn("\n".'Processed ' . count($rows) . ' course category records.');
	}

	/**
	 * Add/Updates an individual Course Category Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncCourseCategoryRecord(&$row)
	{
		$this->logAndPrintLn('Syncing ' . $row['KEY'] . '...');

		$row_status = 'enabled';
		$available_ind = 'Y';

		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`category_key`, `category_title`, `parent_category_key`, `row_status`, `available_ind`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($row['TITLE']) . ", " . $this->dbo->q($row['PARENT']) . ", " . $this->dbo->q($row_status) . ", " . $this->dbo->q($available_ind) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`category_title` = " . $this->dbo->q($row['TITLE']) . ", `parent_category_key` = " . $this->dbo->q($row['PARENT']) . ", `row_status` = " . $this->dbo->q($row_status) . ", `available_ind` = " . $this->dbo->q($available_ind);

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
			$this->logAndPrintLn("Attempting to Retrieve Course Category Records...");
			$this->retrieveRecords();
			$this->logAndPrintLn("Retrieved " . count($this->records) . " Course Category Records to include...");
			$this->logAndPrintLn("Generating Course Category File String...");
			$this->generateFileString();
			$this->logAndPrintLn("Writing Course Category File String to a file...");
			$result = $this->writeString();
			if ($result)
			{
				// If we successfully wrote the file then send it:
				$this->sendCourseCategoryString();
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
		$headerFields = array('EXTERNAL_CATEGORY_KEY', 'TITLE', 'ROW_STATUS', 'AVAILABLE_IND', 'PARENT_CATEGORY_KEY');
		$recordFields = array('category_key', 'category_title', 'row_status', 'available_ind', 'parent_category_key');

		$this->generateFileStringHelper($headerFields, $recordFields);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendCourseCategoryString()
	{
		$serviceUsername = $this->getCourseCategoryStoreUsername();
		$servicePassword = $this->getCourseCategoryStorePassword();
		$serviceStoreURL = $this->getCourseCategoryStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardCourseCategoryCli')->execute();
