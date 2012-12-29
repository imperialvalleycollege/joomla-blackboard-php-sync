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
 * Person CLI App for Blackboard.
 *
 * This application will sync User Records
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardPersonCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'person';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Person';

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
	public function displayStatistics()
	{
		foreach($this->terms as $term)
		{
			$this->termCode = $term;

			$this->logAndPrintLn("\nCurrent Statistics: ");

			// Active Student Count:
			$query = "SELECT count(studentCount) studentCount
					  FROM (SELECT distinct(username) studentCount
						    FROM " . $this->getTableName() . "
						    WHERE row_status = 'enabled'
						    and is_employee = 0) as tmp";

			$this->dbo->setQuery($query);
			$activeStudentCount = (int) $this->dbo->loadResult();

			// Active Employee Count:
			$query = "SELECT count(studentCount) studentCount
					  FROM (SELECT distinct(username) studentCount
						    FROM " . $this->getTableName() . "
						    WHERE row_status = 'enabled'
						    and is_employee = 1) as tmp";

			$this->dbo->setQuery($query);
			$activeEmployeeCount = (int) $this->dbo->loadResult();

			$this->logAndPrintLn('Total # of Students: ' . $activeStudentCount);
			$this->logAndPrintLn('Total # of Employees: ' . $activeEmployeeCount);
		}
	}

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
		if (!empty($this->action) && !empty($this->termCode))
		{
			foreach($this->terms as $term)
			{
				$this->termCode = $term;

				$this->logAndPrintLn("You've entered in '" . $this->action . "' as the action to perform.");
				$this->logAndPrintLn("You've entered in '" . $this->termCode . "' as the term code to use.");

				$this->logAndPrintLn("Attempting to Create User Table for " . $this->termCode . "...");
				$this->createPersonsTable();

				if ($this->oracleConnect())
				{
					$this->logAndPrintLn("Setting User Status for Existing Rows to 'disabled' (Disabled) by Default...");
					$this->setDefaultRowStatus();

					$this->logAndPrintLn("Attempting to Sync Courses...");
					$this->syncPersons();
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
	}

	/**
	 * Creates the Person Table for the
	 * Term currently being processed in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createPersonsTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`pidm` int(11) NOT NULL,
					`username` varchar(75) NOT NULL,
					`password` varchar(32) NOT NULL,
					`email` varchar(100) NOT NULL,
					`is_employee` tinyint(3) NOT NULL,
					`spriden_id` varchar(100) NOT NULL,
					`spriden_first_name` varchar(150) NOT NULL,
				    `spriden_last_name` varchar(150) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`new_data_source_key` varchar(24) NOT NULL,
					`system_role` varchar(54) NOT NULL,
					UNIQUE KEY  (`pidm`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Persons Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncPersons()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Class Roster Information for Current Term:
		$query = "select DISTINCT(SFRSTCR_PIDM), SPRIDEN_ID, TO_NUMBER(SUBSTR(SPRIDEN_ID, 2)) UIDNUMBER, SPRIDEN_LAST_NAME, SPRIDEN_FIRST_NAME, SPRIDEN_MI, GORPAUD_PIN, GOBTPAC_EXTERNAL_USER, GOBTPAC_LDAP_USER
		            from (select SFRSTCR_PIDM
		                  from SFRSTCR

		                  where SFRSTCR_TERM_CODE = :term_code
		                  GROUP BY SFRSTCR_PIDM
		                  UNION
		                  select sirasgn_pidm
		                  from sirasgn
		                  WHERE SIRASGN_TERM_CODE = :term_code
		                  UNION
		                  SELECT PEBEMPL_PIDM
		                  FROM PEBEMPL
		                  WHERE PEBEMPL_EMPL_STATUS = 'A'
		                  AND PEBEMPL_ECLS_CODE IN ('F0', 'F1', 'F2', 'FN', 'FP', 'NC', 'CM','CC','C0', 'C1', 'C2', 'C3', 'C4', 'C5', 'CP', 'AD'))
		            INNER JOIN
		              SPRIDEN
		            ON
		              SFRSTCR_PIDM = SPRIDEN_PIDM
		            LEFT OUTER JOIN
            			 GORPAUD
		            ON
            			SFRSTCR_PIDM = GORPAUD_PIDM
		            LEFT OUTER JOIN
            			GOBTPAC
		            ON
            			SFRSTCR_PIDM = GOBTPAC_PIDM
		            WHERE SPRIDEN_CHANGE_IND IS NULL
		            AND GORPAUD_ACTIVITY_DATE = (SELECT MAX(GORPAUD_ACTIVITY_DATE) FROM GORPAUD WHERE GORPAUD_PIDM = SFRSTCR_PIDM AND GORPAUD_CHG_IND = 'P')
		            AND GORPAUD_CHG_IND = 'P'
		            AND GOBTPAC_EXTERNAL_USER IS NOT NULL";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$oracle->getQuery()->bind(':term_code', $this->termCode);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' student/employee records.');
		foreach($rows as $index => &$row)
		{
			$this->syncPersonRecord($row, $index);
		}

		$this->logAndPrintLn("\n".'Processed ' . count($rows) . ' student/employee records.');
	}

	/**
	 * Add/Updates an individual Person Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 * @param int $index
	 *
	 * @return void
	 */
	public function syncPersonRecord(&$row, $index)
	{
		if ($this->get('includeSpridenIdsInLog', false))
		{
			$this->logAndPrintLn('Syncing ' . $row['SPRIDEN_ID'] . '...');
		}
		else
		{
			if (($index + 1) % 1000 == 0)
			{
				$this->logAndPrintLn('Processing Record ' . ($index + 1)) . '...';
			}
		}

		$new_data_source_key = $this->get('personDataSourceKey');
		$system_role = '';
		$row_status = 'enabled';
		$available_ind = 'Y';
		if (!empty($row['GOBTPAC_LDAP_USER']))
        {
            $username = $row['GOBTPAC_LDAP_USER'];
            $email = $row['GOBTPAC_LDAP_USER'] . '@' . $this->get('employeeEmailDomain');
            $is_employee = 1;
        }
        else
        {
            $username = $row['GOBTPAC_EXTERNAL_USER'];
            $email = $row['GOBTPAC_EXTERNAL_USER'] . '@' . $this->get('studentEmailDomain');
            $is_employee = 0;
        }

		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`pidm`, `username`, `password`, `email`, `is_employee`, `spriden_id`, " .
						 "`spriden_first_name`, `spriden_last_name`, `row_status`, `new_data_source_key`, `system_role`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['SFRSTCR_PIDM']) . ", " . $this->dbo->q($username) . ", " . $this->dbo->q($row['GORPAUD_PIN']) . ", " . $this->dbo->q($email) . ", " . $this->dbo->q($is_employee) . ", " . $this->dbo->q($row['SPRIDEN_ID']) . ", " .
					 	 "" . $this->dbo->q($row['SPRIDEN_FIRST_NAME']) . ", " . $this->dbo->q($row['SPRIDEN_LAST_NAME']) . ", " . $this->dbo->q($row_status) . ", " . $this->dbo->q($new_data_source_key) . ", " . $this->dbo->q($system_role) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`username` = " . $this->dbo->q($username) . ", `password` = " . $this->dbo->q($row['GORPAUD_PIN']) . ", `email` = " . $this->dbo->q($email) . ", `is_employee` = " . $this->dbo->q($is_employee) . ", `spriden_id` = " . $this->dbo->q($row['SPRIDEN_ID']) . ", " .
					 	 "`spriden_first_name` = " . $this->dbo->q($row['SPRIDEN_FIRST_NAME']) . ", `spriden_last_name` = " . $this->dbo->q($row['SPRIDEN_LAST_NAME']) . ", `row_status` = " . $this->dbo->q($row_status) . ", `new_data_source_key` = " . $this->dbo->q($new_data_source_key) . ", `system_role` = " . $this->dbo->q($system_role);

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
		if (!empty($this->action) && !empty($this->termCode))
		{
			foreach($this->terms as $term)
			{
				$this->termCode = $term;

				$this->logAndPrintLn("Attempting to Retrieve User Records for " . $this->termCode . "...");
				$this->retrieveRecords();
				$this->logAndPrintLn("Retrieved " . count($this->records) . " User Records to include...");
				$this->logAndPrintLn("Generating Users File String...");
				$this->generateFileString();
				$this->logAndPrintLn("Writing Users File String to a file...");
				$result = $this->writeString();
				if ($result)
				{
					// If we successfully wrote the file then send it:
					$this->sendPersonString();
				}
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
		$headerFields = array('EXTERNAL_PERSON_KEY', 'USER_ID', 'PASSWD', 'EMAIL', 'STUDENT_ID', 'FIRSTNAME', 'LASTNAME', 'ROW_STATUS', 'NEW_DATA_SOURCE_KEY', 'SYSTEM_ROLE');
		$recordFields = array('pidm', 'username', 'password', 'email', 'spriden_id', 'spriden_first_name', 'spriden_last_name', 'row_status', 'new_data_source_key', 'system_role');

		$this->generateFileStringHelper($headerFields, $recordFields);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendPersonString()
	{
		$serviceUsername = $this->getPersonStoreUsername();
		$servicePassword = $this->getPersonStorePassword();
		$serviceStoreURL = $this->getPersonStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardPersonCli')->execute();
