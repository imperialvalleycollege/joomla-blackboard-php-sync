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
 * Course Membership CLI App for Blackboard.
 *
 * This application will sync Course Memberships
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardCourseMembershipCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'coursemembership';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Course Membership';

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
						    WHERE row_status = 'enabled' and role = 'Student') as tmp";

			$this->dbo->setQuery($query);
			$activeStudentCount = (int) $this->dbo->loadResult();

			// Total Active Student Section Enrollments:
			$query = "SELECT count(`username`) studentCount
					  FROM " . $this->getTableName() . "
					  WHERE row_status = 'enabled' and role = 'Student'";

			$this->dbo->setQuery($query);
			$activeStudentSectionEnrollmentCount = (int) $this->dbo->loadResult();

			// Total Inactive Student Section Enrollments:
			$query = "SELECT count(`username`) studentCount
					  FROM " . $this->getTableName() . "
					  WHERE row_status = 'disabled' and role = 'Student'";

			$this->dbo->setQuery($query);
			$inactiveStudentSectionEnrollmentCount = (int) $this->dbo->loadResult();

			// Active Instructor Count:
			$query = "SELECT count(instructorCount) instructorCount
					  FROM (SELECT distinct(username) instructorCount
						    FROM " . $this->getTableName() . "
						    WHERE row_status = 'enabled' and role = 'Instructor') as tmp";

			$this->dbo->setQuery($query);
			$activeInstructorCount = (int) $this->dbo->loadResult();

			// Total Instructor Classes:
			$query = "SELECT count(`username`) instructorCount
					  FROM " . $this->getTableName() . "
					  WHERE row_status = 'enabled' and role = 'Instructor'";

			$this->dbo->setQuery($query);
			$activeInstructorSectionsCount = (int) $this->dbo->loadResult();

			$this->logAndPrintLn('Total # of Students: ' . $activeStudentCount);
			$this->logAndPrintLn('Total # of Active Student Section Enrollments: ' . $activeStudentSectionEnrollmentCount);
			$this->logAndPrintLn('Total # of Inactive Student Section Enrollments: ' . $inactiveStudentSectionEnrollmentCount);
			$this->logAndPrintLn('Total # of Instructors: ' . $activeInstructorCount);
			$this->logAndPrintLn('Total # of Active Instructor Section Enrollments: ' . $activeInstructorSectionsCount);
			if ($activeStudentCount > 0)
			{
				$this->logAndPrintLn('Average # of Classes Students Are Taking: ' . round($activeStudentSectionEnrollmentCount / $activeStudentCount, 3));
			}
			if ($activeInstructorCount > 0)
			{
				$this->logAndPrintLn('Average # of Classes Taught Per Instructor: ' . round($activeInstructorSectionsCount / $activeInstructorCount, 3));
			}
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

				$this->logAndPrintLn("Attempting to Create Roster Table for " . $this->termCode . "...");
				$this->createCourseMembershipsTable();

				if ($this->oracleConnect())
				{
					$this->logAndPrintLn("Setting Enrollment Status for Existing Rows to 'disabled' (Dropped) by Default...");
					$this->setDefaultRowStatus();

					$this->logAndPrintLn("Attempting to Sync Courses...");
					$this->syncCourseMemberships();

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
	 * Creates the Course Memberships Table for the
	 * Term currently being processed in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createCourseMembershipsTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`course_key` varchar(36) NOT NULL,
					`crn` int(10) NOT NULL,
                    `spriden_id` varchar(27) NOT NULL,
				    `pidm` int(11) NOT NULL,
                    `role` varchar(54) NOT NULL,
                    `row_status` varchar(24) NOT NULL,
                    `available_ind` varchar(3) NOT NULL,
                    `enrollment_date` varchar(24) NOT NULL,
				    `username` varchar(75) NOT NULL,
				    `student_first_name` varchar(150) NOT NULL,
				    `student_last_name` varchar(150) NOT NULL,
					UNIQUE KEY  (`pidm`, `crn`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Course Memberships Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncCourseMemberships()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Class Roster Information for Current Term:
		$query = "select SFRSTCR_TERM_CODE || '.' || SFRSTCR_CRN key, SFRSTCR_TERM_CODE term, SFRSTCR_CRN crn, SFRSTCR_PIDM pidm,
		                 SPRIDEN_ID, SPRIDEN_FIRST_NAME, SPRIDEN_LAST_NAME, SPRIDEN_MI, GOBTPAC_EXTERNAL_USER, GOBTPAC_LDAP_USER,
		                 'Student' role, to_char(sfrstcr_add_date, 'YYYYMMDD') enrollment_date
					from (select SFRSTCR_PIDM, SFRSTCR_TERM_CODE, SFRSTCR_CRN, sfrstcr_rsts_code, sfrstcr_add_date
						  from SFRSTCR
						  where SFRSTCR_TERM_CODE = :term_code
						  and (sfrstcr_rsts_code = 'RW' or sfrstcr_rsts_code = 'RE')
						  )
					INNER JOIN
					  SPRIDEN
					ON
					  SFRSTCR_PIDM = SPRIDEN_PIDM
					LEFT OUTER JOIN
					  GOBTPAC
					ON
					  SFRSTCR_PIDM = GOBTPAC_PIDM
					WHERE SPRIDEN_CHANGE_IND IS NULL
					AND GOBTPAC_EXTERNAL_USER IS NOT NULL
					UNION
					SELECT SIRASGN_TERM_CODE || '.' || SIRASGN_CRN key, SIRASGN_TERM_CODE term, SIRASGN_CRN crn, SIRASGN_PIDM pidm,
					       SPRIDEN_ID, SPRIDEN_FIRST_NAME, SPRIDEN_LAST_NAME, SPRIDEN_MI, GOBTPAC_EXTERNAL_USER, GOBTPAC_LDAP_USER,
					       'Instructor' role, to_char(sirasgn_activity_date, 'YYYYMMDD') enrollment_date
					FROM SIRASGN
					INNER JOIN
					 SPRIDEN
					ON
					  SIRASGN_PIDM = SPRIDEN_PIDM

					LEFT OUTER JOIN
					   GOBTPAC
					ON
					  SIRASGN_PIDM = GOBTPAC_PIDM
					WHERE SIRASGN_TERM_CODE = :term_code
					AND SPRIDEN_CHANGE_IND IS NULL
					AND GOBTPAC_EXTERNAL_USER IS NOT NULL
					ORDER BY term, crn, role, SPRIDEN_FIRST_NAME, SPRIDEN_LAST_NAME";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$oracle->getQuery()->bind(':term_code', $this->termCode);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' student/instructor records.');
		foreach($rows as $index => &$row)
		{
			$this->syncCourseMembershipRecord($row, $index);
		}

		$this->logAndPrintLn("\n".'Processed ' . count($rows) . ' student/instructor records.');
	}

	/**
	 * Add/Updates an individual Course Membership Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncCourseMembershipRecord(&$row, $index)
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

		$row_status = 'enabled';
		$available_ind = 'Y';
		if (!empty($row['GOBTPAC_LDAP_USER']))
        {
            $username = $row['GOBTPAC_LDAP_USER'];
        }
        else
        {
            $username = $row['GOBTPAC_EXTERNAL_USER'];
        }

		// Insert Rows:
		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`course_key`, `crn`, `spriden_id`, `pidm`, `role`, `row_status`, " .
						 "`available_ind`, `enrollment_date`, `username`, `student_first_name`, `student_last_name`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($row['CRN']) . ", " . $this->dbo->q($row['SPRIDEN_ID']) . ", " . $this->dbo->q($row['PIDM']) . ", " . $this->dbo->q($row['ROLE']) . ", " . $this->dbo->q($row_status) . ", " .
					 	 "" . $this->dbo->q($available_ind) . ", " . $this->dbo->q($row['ENROLLMENT_DATE']) . ", " . $this->dbo->q($username) . ", " . $this->dbo->q($row['SPRIDEN_FIRST_NAME']) . ", " . $this->dbo->q($row['SPRIDEN_LAST_NAME']) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`spriden_id` = " . $this->dbo->q($row['SPRIDEN_ID']) . ", `role` = " . $this->dbo->q($row['ROLE']) . ", `row_status` = " . $this->dbo->q($row_status) . ", " .
					 	 "`available_ind` = " . $this->dbo->q($available_ind) . ", `enrollment_date` = " . $this->dbo->q($row['ENROLLMENT_DATE']) . ", `username` = " . $this->dbo->q($username) . ", `student_first_name` = " . $this->dbo->q($row['SPRIDEN_FIRST_NAME']) . ", `student_last_name` = " . $this->dbo->q($row['SPRIDEN_LAST_NAME']);

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

				$this->logAndPrintLn("Attempting to Retrieve Course Memembership Records for " . $this->termCode . "...");
				$this->retrieveRecords();
				$this->logAndPrintLn("Retrieved " . count($this->records) . " Course Mememberships Records to include...");
				$this->logAndPrintLn("Generating Course Mememberships File String...");
				$this->generateFileString();
				$this->logAndPrintLn("Writing Roster File String to a file...");
				$result = $this->writeString();
				if ($result)
				{
					// If we successfully wrote the file then send it:
					$this->sendCourseMembershipString();
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
		// Enrollment Dates are apparently not supported in the Flat File Feed Integration:
		//$headerFields = array('EXTERNAL_COURSE_KEY', 'EXTERNAL_PERSON_KEY', 'ROLE', 'ROW_STATUS', 'AVAILABLE_IND', 'ENROLLMENT_DATE');
		$headerFields = array('EXTERNAL_COURSE_KEY', 'EXTERNAL_PERSON_KEY', 'ROLE', 'ROW_STATUS', 'AVAILABLE_IND');
		$recordFields = array('course_key', 'pidm', 'role', 'row_status', 'available_ind');

		$this->generateFileStringHelper($headerFields, $recordFields);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendCourseMembershipString()
	{
		$serviceUsername = $this->getCourseMembershipStoreUsername();
		$servicePassword = $this->getCourseMembershipStorePassword();
		$serviceStoreURL = $this->getCourseMembershipStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardCourseMembershipCli')->execute();
