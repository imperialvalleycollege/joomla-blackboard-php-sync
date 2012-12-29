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
 * Course CLI App for Blackboard.
 *
 * This application will sync Courses
 * from Ellucian's Banner ERP for the provided
 * term code to Blackboard.
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardCourseCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'course';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Course';

	/**
	 * Holds the Crosslisted Course Records List
	 *
	 * @var mixed
	 */
	protected $crosslistedCourseRecords = array();

	/**
	 * Blackboard Crosslisted Courses Output String
	 * which holds all lines for the
	 * sync file that will be sent to
	 * Blackboard.
	 *
	 * @var string
	 */
	protected $crosslistedOutputString = '';

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

			// Subject Course Count:
			$query = "SELECT `subject`, count(`course_key`) courseCount
			          FROM `" . $this->getTableName() . "`
					  GROUP BY `subject`
					  ORDER BY courseCount DESC";

			$this->dbo->setQuery($query);
			$subjectCourseCounts = $this->dbo->loadObjectList();

			foreach($subjectCourseCounts as $subjectCourseCount)
			{
				$this->logAndPrintLn('Subject: ' . $subjectCourseCount->subject . ', Course Count: ' . $subjectCourseCount->courseCount);
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

				$this->logAndPrintLn("Attempting to Create Courses Table for " . $this->termCode . "...");
				$this->createCoursesTable();

				$this->logAndPrintLn("Attempting to Create Crosslisted Courses Table for " . $this->termCode . "...");
				$this->createCrosslistedCoursesTable();

				if ($this->oracleConnect())
				{
					$this->logAndPrintLn("Setting Default Status for Existing Rows to be 'disabled' (Disabled) by Default...");
					$this->setDefaultRowStatus();

					$this->logAndPrintLn("Attempting to Sync Crosslisted Courses...");
					$this->syncCrosslistedCourses();

					$this->logAndPrintLn("Attempting to Sync Courses...");
					$this->syncCourses();

					$this->logAndPrintLn("Attempting to Add Merged Course Info to Courses Table...");
					$this->addMergedCourseInfo();

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
	 * Creates the Courses Table for the
	 * Term currently being processed in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createCoursesTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getTableName() . "` (
					`course_key` varchar(50) NOT NULL,
					`course_id` varchar(50) NOT NULL,
					`course_name` varchar(255) NOT NULL,
					`start_date` varchar(24) NOT NULL,
					`end_date` varchar(24) NOT NULL,
					`duration` varchar(30) NOT NULL,
					`master_course_key` varchar(50) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`allow_guest_ind` varchar(3) NOT NULL,
					`available_ind` varchar(3) NOT NULL,
					`catalog_ind` varchar(3) NOT NULL,
					`desc_page` varchar(3) NOT NULL,
					`description` varchar(4000) NOT NULL,
					`term_key` varchar(50) NOT NULL,
					`subject` varchar(50) NOT NULL,
					`course_number` varchar(50) NOT NULL,
					`division` varchar(50) NOT NULL,
					`department` varchar(50) NOT NULL,
					`term_code` int(10) NOT NULL,
					`crn` int(10) NOT NULL,
					`enrollment` int(10) NOT NULL,
					`available` int(10) NOT NULL,
					UNIQUE KEY  (`term_code`, `crn`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Creates the Crosslisted Courses Table for the
	 * Term currently being processed in the
	 * local MySQL Database.
	 *
	 * @return void
	 */
	public function createCrosslistedCoursesTable()
	{
		$query = "CREATE TABLE IF NOT EXISTS `" . $this->getCrosslistedTableName() . "` (
					`course_key` varchar(50) NOT NULL,
					`course_id` varchar(50) NOT NULL,
					`course_name` varchar(255) NOT NULL,
					`start_date` varchar(24) NOT NULL,
					`end_date` varchar(24) NOT NULL,
					`duration` varchar(30) NOT NULL,
					`row_status` varchar(24) NOT NULL,
					`allow_guest_ind` varchar(3) NOT NULL,
					`available_ind` varchar(3) NOT NULL,
					`catalog_ind` varchar(3) NOT NULL,
					`desc_page` varchar(3) NOT NULL,
					`description` varchar(4000) NOT NULL,
					`term_key` varchar(50) NOT NULL,
					`subject` varchar(50) NOT NULL,
					`course_number` varchar(50) NOT NULL,
					`division` varchar(50) NOT NULL,
					`department` varchar(50) NOT NULL,
					`term_code` int(10) NOT NULL,
					`primary_crn` int(10) NOT NULL,
					`crosslisted_crns` varchar(300) NOT NULL,
					`enrollment` int(10) NOT NULL,
					`available` int(10) NOT NULL,
					`manually_created` tinyint(3) NOT NULL,
					UNIQUE KEY  (`course_key`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8";
		$this->dbo->setQuery($query);
		$this->dbo->execute();
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
		// Query to set all courses for the term to be disabled prior to the next resync:
		$query = 'UPDATE `' . $this->getTableName() . '` ' .
				 'SET `row_status` = \'disabled\'';
		$this->dbo->setQuery($query);
		$this->dbo->execute();

		$query = 'UPDATE `' . $this->getCrosslistedTableName() . '` ' .
				 'SET `row_status` = \'disabled\' ' .
				 'WHERE `manually_created` = 0';
		$this->dbo->setQuery($query);
		$this->dbo->execute();
	}

	/**
	 * Loads the Course Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncCourses()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Course Information for Current Term:
		$query = "select sections.ssbsect_term_code,
	                   sections.ssbsect_crn,
	                   sections.ssbsect_term_code || '.' || sections.ssbsect_crn key,
	                   sections.ssbsect_enrl,
	                   sections.ssbsect_seats_avail,
	                   to_char((sections.ssbsect_ptrm_start_date - 2), 'YYYYMMDD') course_start_date,
	                   to_char((sections.ssbsect_ptrm_end_date + 7), 'YYYYMMDD') course_end_date,
	                   coursedesc.scbdesc_subj_code,
	                   coursedesc.scbdesc_crse_numb,
	                   coursedesc.scbdesc_term_code_eff,
	                   courseinfo.scbcrse_divs_code,
	                   courseinfo.scbcrse_dept_code,
	                   courseinfo.scbcrse_title,
	                   CAST(coursedesc.scbdesc_text_narrative AS VARCHAR2(4000)) scbdesc_text_narrative,
	                   (SELECT SSRXLST_XLST_GROUP
	                     FROM SSRXLST
	                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
	                     AND SSRXLST_CRN = sections.ssbsect_crn) crosslist_group,
	                   (SELECT sections.ssbsect_term_code || '.XLST_GRP.' || SSRXLST_XLST_GROUP
	                     FROM SSRXLST
	                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
	                     AND SSRXLST_CRN = sections.ssbsect_crn) crosslist_group_key,
	                   (SELECT SSRXLST_CRN
	                     FROM SSRXLST
	                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
	                     AND ROWNUM <= 1
	                     AND SSRXLST_XLST_GROUP = (SELECT SSRXLST_XLST_GROUP
	                                               FROM SSRXLST
	                                               WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
	                                               AND SSRXLST_CRN = sections.ssbsect_crn)) crosslist_crn
	            from ssbsect sections,
	                 scbcrse courseinfo,
	                 scbdesc coursedesc
	            where sections.ssbsect_term_code = :term_code
	            and sections.ssbsect_ssts_code = 'A'
	            and sections.ssbsect_subj_code = courseinfo.scbcrse_subj_code
	            and sections.ssbsect_crse_numb = courseinfo.scbcrse_crse_numb
	            and courseinfo.scbcrse_subj_code = coursedesc.scbdesc_subj_code
	            and courseinfo.scbcrse_crse_numb = coursedesc.scbdesc_crse_numb
	            and coursedesc.scbdesc_term_code_eff = (select max(b.scbdesc_term_code_eff)
							                                        from scbdesc b
							                                        where coursedesc.scbdesc_subj_code = b.scbdesc_subj_code
							                                        and coursedesc.scbdesc_crse_numb = b.scbdesc_crse_numb
							                                        and b.scbdesc_term_code_eff <= sections.ssbsect_term_code)
	            and courseinfo.scbcrse_eff_term = (select max(c.scbcrse_eff_term)
						                                       from scbcrse c
						                                       where courseinfo.scbcrse_subj_code = c.scbcrse_subj_code
						                                       and courseinfo.scbcrse_crse_numb = c.scbcrse_crse_numb
						                                       and c.scbcrse_eff_term <= sections.ssbsect_term_code)
				order by ssbsect_term_code ASC, ssbsect_crn ASC, coursedesc.scbdesc_term_code_eff desc";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$oracle->getQuery()->bind(':term_code', $this->termCode);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' course records.');
		foreach($rows as $row)
		{
			$this->syncCourseRecord($row);
		}

		$this->logAndPrintLn('Processed ' . count($rows) . ' course records.');
	}

	/**
	 * Add/Updates an individual Course Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncCourseRecord(&$row)
	{
		$this->logAndPrintLn('Syncing ' . $row['KEY'] . '...');

		$row_status = 'enabled';
		$available_ind = 'Y';
		$allow_guest_ind = 'Y';
		$catalog_ind = 'Y';
		$desc_page = 'Y';
		$courseName = $row['SCBDESC_SUBJ_CODE'] . " " . $row['SCBDESC_CRSE_NUMB'] . ": " . $row['SCBCRSE_TITLE'] . " (" . $row['SSBSECT_CRN'] . ")";
		$duration = 'range';
		$description = str_replace("\n", '<br />', $row['SCBDESC_TEXT_NARRATIVE']);

		$query = "INSERT INTO " . $this->getTableName() . " " .
						 "(`course_key`, `course_id`, `course_name`, `start_date`, `end_date`, `duration`, " .
						 "`master_course_key`, `row_status`, `allow_guest_ind`, `available_ind`, `catalog_ind`, " .
						 "`desc_page`, `description`, `term_key`, `subject`, `course_number`, `division`, `department`, " .
						 "`term_code`, `crn`, `enrollment`, `available`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($courseName) . ", " . $this->dbo->q($row['COURSE_START_DATE']) . ", " . $this->dbo->q($row['COURSE_END_DATE']) . ", " . $this->dbo->q($duration) . ", " .
					 	 "" . $this->dbo->q($row['CROSSLIST_GROUP_KEY']) . ", " . $this->dbo->q($row_status) . ", " . $this->dbo->q($allow_guest_ind) . ", " . $this->dbo->q($available_ind) . ", " . $this->dbo->q($catalog_ind) . ", " .
					 	 "" . $this->dbo->q($desc_page) . ", " . $this->dbo->q($description) . ", " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "" . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SSBSECT_CRN']) . ", " . $this->dbo->q($row['SSBSECT_ENRL']) . ", " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL']) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`course_name` = " . $this->dbo->q($courseName) . ", `start_date` = " . $this->dbo->q($row['COURSE_START_DATE']) . ", `end_date` = " . $this->dbo->q($row['COURSE_END_DATE']) . ", `duration` = " . $this->dbo->q($duration) . ", " .
					 	 "`master_course_key` = " . $this->dbo->q($row['CROSSLIST_GROUP_KEY']) . ", `row_status` = " . $this->dbo->q($row_status) . ", `allow_guest_ind` = " . $this->dbo->q($allow_guest_ind) . ", `available_ind` = " . $this->dbo->q($available_ind) . ", `catalog_ind` = " . $this->dbo->q($catalog_ind) . ", " .
					 	 "`desc_page` = " . $this->dbo->q($desc_page) . ", `description` = " . $this->dbo->q($description) . ", `term_key` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `subject` = " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", `course_number` = " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", `division` = " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", `department` = " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "`term_code` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `crn` = " . $this->dbo->q($row['SSBSECT_CRN']) . ", `enrollment` = " . $this->dbo->q($row['SSBSECT_ENRL']) . ", `available` = " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL']);

		$this->dbo->setQuery($query);
		$result = $this->dbo->execute();

		if (!$result)
		{
			$this->failedInsertsLog[] = 'Error: ' . $this->dbo->getError() . ', Query: ' . $query;
		}
	}

	/**
	 * Loads the Crosslisted Course Result Set and then processes
	 * each record individually adding/updating the row
	 * in the local MySQL database.
	 *
	 * @return void
	 */
	public function syncCrosslistedCourses()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		// Query to get Crosslisted Course Information for Current Term:
		$query = "SELECT sections.ssbsect_term_code,
				       sections.ssbsect_crn,
				       sections.ssbsect_term_code || '.XLST_GRP.' || SSRXLST_XLST_GROUP key,
				       SSRXLST_CRNS,
				       ssbsect_enrl_total,
				       ssbsect_seats_avail_total,
				       to_char((sections.ssbsect_ptrm_start_date - 2), 'YYYYMMDD') course_start_date,
				       to_char((sections.ssbsect_ptrm_end_date + 7), 'YYYYMMDD') course_end_date,
				       coursedesc.scbdesc_subj_code,
				       coursedesc.scbdesc_crse_numb,
				       coursedesc.scbdesc_term_code_eff,
				       courseinfo.scbcrse_divs_code,
				       courseinfo.scbcrse_dept_code,
				       courseinfo.scbcrse_title,
				       CAST(coursedesc.scbdesc_text_narrative AS VARCHAR2(4000)) scbdesc_text_narrative
				 FROM (select SSRXLST_XLST_GROUP, SSRXLST_TERM_CODE, SUM(sections.ssbsect_enrl) ssbsect_enrl_total, SUM(sections.ssbsect_seats_avail) ssbsect_seats_avail_total, MIN(SSRXLST_CRN) SSRXLST_CRN, LISTAGG(SSRXLST_CRN, ',') WITHIN GROUP (ORDER BY SSRXLST_CRN) SSRXLST_CRNS
				       from ssrxlst,
				            ssbsect sections
				       where SSRXLST_TERM_CODE = :term_code
				       and SSRXLST_TERM_CODE = sections.ssbsect_term_code
				       and SSRXLST_CRN = sections.ssbsect_crn
				       group by SSRXLST_XLST_GROUP, SSRXLST_TERM_CODE),
				      ssbsect sections,
				      scbdesc coursedesc,
				      scbcrse courseinfo
				 WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
				 AND SSRXLST_CRN = sections.ssbsect_crn
				 and sections.ssbsect_ssts_code = 'A'
				 AND SSRXLST_TERM_CODE = :term_code
				 AND SSRXLST_XLST_GROUP = (SELECT SSRXLST_XLST_GROUP
				                           FROM SSRXLST
				                           WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
				                           AND SSRXLST_CRN = sections.ssbsect_crn)
				AND coursedesc.scbdesc_subj_code = sections.ssbsect_subj_code
				AND coursedesc.scbdesc_crse_numb = sections.ssbsect_crse_numb
				AND coursedesc.scbdesc_term_code_eff = (select max(b.scbdesc_term_code_eff)
				                                        from scbdesc b
				                                       where coursedesc.scbdesc_subj_code = b.scbdesc_subj_code
				                                        and coursedesc.scbdesc_crse_numb = b.scbdesc_crse_numb
				                                       and b.scbdesc_term_code_eff <= sections.ssbsect_term_code)
				AND courseinfo.scbcrse_subj_code = sections.ssbsect_subj_code
				AND courseinfo.scbcrse_crse_numb = sections.ssbsect_crse_numb
				AND courseinfo.scbcrse_eff_term = (select max(c.scbcrse_eff_term)
				                                   from scbcrse c
				                                   where courseinfo.scbcrse_subj_code = c.scbcrse_subj_code
				                                  and courseinfo.scbcrse_crse_numb = c.scbcrse_crse_numb
				                                   and c.scbcrse_eff_term <= sections.ssbsect_term_code)
				order by SSRXLST_XLST_GROUP, ssbsect_term_code ASC, ssbsect_crn ASC, coursedesc.scbdesc_term_code_eff desc";

		$this->logAndPrintLn('Collecting rows to process...');

		$oracle->setQuery($query);
		$oracle->getQuery()->bind(':term_code', $this->termCode);

		$rows = $oracle->loadAssocList();

		$this->logAndPrintLn('Need to process a total of ' . count($rows) . ' crosslisted course records.');
		foreach($rows as $row)
		{
			$this->syncCrosslistedCourseRecord($row);
		}

		$this->logAndPrintLn('Processed ' . count($rows) . ' crosslisted course records.');
	}

	/**
	 * Add/Updates an individual Crosslisted Course Record
	 * in the local MySQL database.
	 *
	 * @param array $row
	 *
	 * @return void
	 */
	public function syncCrosslistedCourseRecord(&$row)
	{
		$this->logAndPrintLn('Syncing ' . $row['KEY'] . '...');

		$row_status = 'enabled';
		$available_ind = 'Y';
		$allow_guest_ind = 'Y';
		$catalog_ind = 'Y';
		$desc_page = 'Y';
		$courseName = 'MASTER - ' . $row['SCBDESC_SUBJ_CODE'] . " " . $row['SCBDESC_CRSE_NUMB'] . ": " . $row['SCBCRSE_TITLE'];
		$duration = 'range';
		$description = str_replace("\n", '<br />', $row['SCBDESC_TEXT_NARRATIVE']);
		$manually_created = 0;

		// Insert Rows:
		$query = "INSERT INTO " . $this->getCrosslistedTableName() . " " .
						 "(`course_key`, `course_id`, `course_name`, `start_date`, `end_date`, `duration`, " .
						 "`row_status`, `allow_guest_ind`, `available_ind`, `catalog_ind`, " .
						 "`desc_page`, `description`, `term_key`, `subject`, `course_number`, `division`, `department`, " .
						 "`term_code`, `primary_crn`, `crosslisted_crns`, `enrollment`, `available`, `manually_created`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($row['KEY']) . ", " . $this->dbo->q($courseName) . ", " . $this->dbo->q($row['COURSE_START_DATE']) . ", " . $this->dbo->q($row['COURSE_END_DATE']) . ", " . $this->dbo->q($duration) . ", " .
					 	 "" . $this->dbo->q($row_status) . ", " . $this->dbo->q($allow_guest_ind) . ", " . $this->dbo->q($available_ind) . ", " . $this->dbo->q($catalog_ind) . ", " .
					 	 "" . $this->dbo->q($desc_page) . ", " . $this->dbo->q($description) . ", " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "" . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SSBSECT_CRN']) . ", " . $this->dbo->q($row['SSRXLST_CRNS']) . ", " . $this->dbo->q($row['SSBSECT_ENRL_TOTAL']) . ", " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL_TOTAL']) . ", " . $this->dbo->q($manually_created) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`course_name` = " . $this->dbo->q($courseName) . ", `start_date` = " . $this->dbo->q($row['COURSE_START_DATE']) . ", `end_date` = " . $this->dbo->q($row['COURSE_END_DATE']) . ", `duration` = " . $this->dbo->q($duration) . ", " .
					 	 "`row_status` = " . $this->dbo->q($row_status) . ", `allow_guest_ind` = " . $this->dbo->q($allow_guest_ind) . ", `available_ind` = " . $this->dbo->q($available_ind) . ", `catalog_ind` = " . $this->dbo->q($catalog_ind) . ", " .
					 	 "`desc_page` = " . $this->dbo->q($desc_page) . ", `description` = " . $this->dbo->q($description) . ", `term_key` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `subject` = " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", `course_number` = " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", `division` = " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", `department` = " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "`term_code` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `primary_crn` = " . $this->dbo->q($row['SSBSECT_CRN']) . ", `crosslisted_crns` = " . $this->dbo->q($row['SSRXLST_CRNS']) . ", `enrollment` = " . $this->dbo->q($row['SSBSECT_ENRL_TOTAL']) . ", `available` = " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL_TOTAL']) . ", `manually_created` = " . $this->dbo->q($manually_created);

		$this->dbo->setQuery($query);
		$result = $this->dbo->execute();

		if (!$result)
		{
			$this->failedInsertsLog[] = 'Error: ' . $this->dbo->getError() . ', Query: ' . $query;
		}
	}

	/**
	 * Performs the Merge Action
	 *
	 * @return void
	 */
	public function merge()
	{
		if (!empty($this->crnList) && !empty($this->termCode))
		{
			$this->logAndPrintLn("You've requested that enrollments for '" . implode(',', $this->crnList) . "' be merged.");
			$this->logAndPrintLn("You've entered in '" . $this->termCode . "' as the term code to use.");

			if ($this->oracleConnect())
			{
				$this->logAndPrintLn("Attempting to Merge Courses...");
				$this->mergeCourses();

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
	 * Loads the Information for the Merged Courses
	 * from Banner and then creates a custom Merged Course
	 * Record in the local MySQL database.
	 *
	 * @return void
	 */
	public function mergeCourses()
	{
		// Get the Oracle Database Connection:
		$oracle = $this->oracleConnect();

		if (!empty($this->crnList))
		{
			// Query to get Course Information for Specified Primary Crosslisted Course:
			$query = "select sections.ssbsect_term_code,
		                   sections.ssbsect_crn,
	                     coursedesc.scbdesc_term_code_eff,
	                     courseinfo.scbcrse_eff_term,
		                   sections.ssbsect_term_code || '.' || sections.ssbsect_crn key,
		                   sections.ssbsect_enrl ssbsect_enrl_total,
		                   sections.ssbsect_seats_avail ssbsect_seats_avail_total,
		                   to_char((sections.ssbsect_ptrm_start_date - 2), 'YYYYMMDD') course_start_date,
		                   to_char((sections.ssbsect_ptrm_end_date + 7), 'YYYYMMDD') course_end_date,
		                   coursedesc.scbdesc_subj_code,
		                   coursedesc.scbdesc_crse_numb,
		                   coursedesc.scbdesc_term_code_eff,
		                   courseinfo.scbcrse_divs_code,
		                   courseinfo.scbcrse_dept_code,
		                   courseinfo.scbcrse_title,
		                   CAST(coursedesc.scbdesc_text_narrative AS VARCHAR2(4000)) scbdesc_text_narrative,
		                   (SELECT SSRXLST_XLST_GROUP
		                     FROM SSRXLST
		                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
		                     AND SSRXLST_CRN = sections.ssbsect_crn) crosslist_group,
		                   (SELECT sections.ssbsect_term_code || '.XLST_GRP.' || SSRXLST_XLST_GROUP
		                     FROM SSRXLST
		                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
		                     AND SSRXLST_CRN = sections.ssbsect_crn) crosslist_group_key,
		                   (SELECT SSRXLST_CRN
		                     FROM SSRXLST
		                     WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
		                     AND ROWNUM <= 1
		                     AND SSRXLST_XLST_GROUP = (SELECT SSRXLST_XLST_GROUP
		                                               FROM SSRXLST
		                                               WHERE SSRXLST_TERM_CODE = sections.ssbsect_term_code
		                                               AND SSRXLST_CRN = sections.ssbsect_crn)) crosslist_crn
		            from ssbsect sections,
		                 scbcrse courseinfo,
		                 scbdesc coursedesc
		            where sections.ssbsect_term_code = :term_code
		            and sections.ssbsect_ssts_code = 'A'
		            and sections.ssbsect_subj_code = courseinfo.scbcrse_subj_code
		            and sections.ssbsect_crse_numb = courseinfo.scbcrse_crse_numb
		            and courseinfo.scbcrse_subj_code = coursedesc.scbdesc_subj_code
		            and courseinfo.scbcrse_crse_numb = coursedesc.scbdesc_crse_numb
		            and coursedesc.scbdesc_term_code_eff = (select max(b.scbdesc_term_code_eff)
								                                        from scbdesc b
								                                        where coursedesc.scbdesc_subj_code = b.scbdesc_subj_code
								                                        and coursedesc.scbdesc_crse_numb = b.scbdesc_crse_numb
								                                        and b.scbdesc_term_code_eff <= sections.ssbsect_term_code)
		            and courseinfo.scbcrse_eff_term = (select max(c.scbcrse_eff_term)
							                                       from scbcrse c
							                                       where courseinfo.scbcrse_subj_code = c.scbcrse_subj_code
							                                       and courseinfo.scbcrse_crse_numb = c.scbcrse_crse_numb
							                                       and c.scbcrse_eff_term <= sections.ssbsect_term_code)
	                and SSBSECT_CRN = :primary_crn
					order by ssbsect_term_code ASC, ssbsect_crn ASC, coursedesc.scbdesc_term_code_eff desc";

			$this->logAndPrintLn('Collecting data to process...');

			$oracle->setQuery($query);
			$oracle->getQuery()->bind(':term_code', $this->termCode);

			$primary_crn = $this->crnList[0];
			$oracle->getQuery()->bind(':primary_crn', $primary_crn);

			$row = $oracle->loadAssoc();

			// The above query doesn't actually return the full total so we'll run a second query for that:
			$query = 'select SUM(ssbsect_enrl) ssbsect_enrl_total, SUM(ssbsect_seats_avail) ssbsect_seats_avail_total
				      from ssbsect where ssbsect_term_code = :term_code and ssbsect_crn IN (' . implode(',', $this->crnList) . ')';

			$oracle->setQuery($query);
			$oracle->getQuery()->bind(':term_code', $this->termCode);

			$totals = $oracle->loadAssoc();

			$row['SSBSECT_ENRL_TOTAL'] = $totals['SSBSECT_ENRL_TOTAL'];
			$row['SSBSECT_SEATS_AVAIL_TOTAL'] = $totals['SSBSECT_SEATS_AVAIL_TOTAL'];

			$this->mergeCourseRecord($row);
		}
	}

	/**
	 * Inserts/Updates a custom Merged Course Record
	 * in the local MySQL database.
	 *
	 * @param mixed $row
	 *
	 * @return void
	 */
	public function mergeCourseRecord(&$row)
	{
		// Create Course Key First:
		$uniqueKey = md5(implode(',', $this->crnList));
		$course_key = $row['SSBSECT_TERM_CODE'] . '.XLST_CUSTOM.' . substr($uniqueKey, 0, 8);

		// Run a detection test for each item in the CRN List to see if we're adding to an existing merge:
		foreach($this->crnList as $primary_crn)
		{
			$query = 'select course_key from ' . $this->getCrosslistedTableName() . " " .
					 'where crosslisted_crns LIKE ' . $this->dbo->q( '%' . $primary_crn . '%');

			$this->dbo->setQuery($query);
			$exists = $this->dbo->loadColumn();

			if (!empty($exists))
			{
				foreach($exists as $exist)
				{
					// This helps ensure that the Course Key doesn't change
					// even when the individual courses might:
					$this->logAndPrintLn('Old Course Key: ' . $course_key);
					$course_key = $exist;
					$this->logAndPrintLn('New Course Key: ' . $course_key);
				}
			}
		}

		$row_status = 'enabled';
		$available_ind = 'Y';
		$allow_guest_ind = 'Y';
		$catalog_ind = 'Y';
		$desc_page = 'Y';
		$courseName = 'MASTER - ' . $row['SCBDESC_SUBJ_CODE'] . " " . $row['SCBDESC_CRSE_NUMB'] . ": " . $row['SCBCRSE_TITLE'];
		$duration = 'range';
		$description = str_replace("\n", '<br />', $row['SCBDESC_TEXT_NARRATIVE']);
		$manually_created = 1;


		$crosslisted_crns = implode(',', $this->crnList);

		// Insert Rows into Local Etudes Database:
		$query = "INSERT INTO " . $this->getCrosslistedTableName() . " " .
						 "(`course_key`, `course_id`, `course_name`, `start_date`, `end_date`, `duration`, " .
						 "`row_status`, `allow_guest_ind`, `available_ind`, `catalog_ind`, " .
						 "`desc_page`, `description`, `term_key`, `subject`, `course_number`, `division`, `department`, " .
						 "`term_code`, `primary_crn`, `crosslisted_crns`, `enrollment`, `available`, `manually_created`) " .
					 "VALUES " .
					 	 "(" . $this->dbo->q($course_key) . ", " . $this->dbo->q($course_key) . ", " . $this->dbo->q($courseName) . ", " . $this->dbo->q($row['COURSE_START_DATE']) . ", " . $this->dbo->q($row['COURSE_END_DATE']) . ", " . $this->dbo->q($duration) . ", " .
					 	 "" . $this->dbo->q($row_status) . ", " . $this->dbo->q($allow_guest_ind) . ", " . $this->dbo->q($available_ind) . ", " . $this->dbo->q($catalog_ind) . ", " .
					 	 "" . $this->dbo->q($desc_page) . ", " . $this->dbo->q($description) . ", " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "" . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", " . $this->dbo->q($row['SSBSECT_CRN']) . ", " . $this->dbo->q($crosslisted_crns) . ", " . $this->dbo->q($row['SSBSECT_ENRL_TOTAL']) . ", " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL_TOTAL']) . ", " . $this->dbo->q($manually_created) . ') ' .
					 "ON DUPLICATE KEY UPDATE " .
					 	 "`course_name` = " . $this->dbo->q($courseName) . ", `start_date` = " . $this->dbo->q($row['COURSE_START_DATE']) . ", `end_date` = " . $this->dbo->q($row['COURSE_END_DATE']) . ", `duration` = " . $this->dbo->q($duration) . ", " .
					 	 "`row_status` = " . $this->dbo->q($row_status) . ", `allow_guest_ind` = " . $this->dbo->q($allow_guest_ind) . ", `available_ind` = " . $this->dbo->q($available_ind) . ", `catalog_ind` = " . $this->dbo->q($catalog_ind) . ", " .
					 	 "`desc_page` = " . $this->dbo->q($desc_page) . ", `description` = " . $this->dbo->q($description) . ", `term_key` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `subject` = " . $this->dbo->q($row['SCBDESC_SUBJ_CODE']) . ", `course_number` = " . $this->dbo->q($row['SCBDESC_CRSE_NUMB']) . ", `division` = " . $this->dbo->q($row['SCBCRSE_DIVS_CODE']) . ", `department` = " . $this->dbo->q($row['SCBCRSE_DEPT_CODE']) . ", " .
					 	 "`term_code` = " . $this->dbo->q($row['SSBSECT_TERM_CODE']) . ", `primary_crn` = " . $this->dbo->q($row['SSBSECT_CRN']) . ", `crosslisted_crns` = " . $this->dbo->q($crosslisted_crns) . ", `enrollment` = " . $this->dbo->q($row['SSBSECT_ENRL_TOTAL']) . ", `available` = " . $this->dbo->q($row['SSBSECT_SEATS_AVAIL_TOTAL']) . ", `manually_created` = " . $this->dbo->q($manually_created);

		$this->dbo->setQuery($query);
		$result = $this->dbo->execute();

		if (!$result)
		{
			$this->failedInsertsLog[] = 'Error: ' . $this->dbo->getError() . ', Query: ' . $query;
		}
	}

	/**
	 * As the final part of the syncing process
	 * we look at the Cross Listed table and pull
	 * out the list of courses for each merged
	 * course key and add in that course key to
	 * the regular Courses Table in the master_course_key
	 * field so that the courses table has all of
	 * the information it requires.
	 *
	 * @return void
	 */
	public function addMergedCourseInfo()
	{
		$query = 'select course_key, crosslisted_crns from ' . $this->getCrosslistedTableName() . " " .
			     'where row_status = ' . $this->dbo->q('enabled');

		$this->dbo->setQuery($query);
		$rows = $this->dbo->loadAssocList();

		// Run a detection test for each item in the CRN List to see if we're adding to an existing merge:
		foreach($rows as $mergedCourseInfo)
		{
			$course_key = $mergedCourseInfo['course_key'];
			$crns = explode(',', $mergedCourseInfo['crosslisted_crns']);
			$available_ind = 'N';

			foreach($crns as $crn)
			{
				$query = 'UPDATE ' . $this->getTableName() . ' ' .
						 'SET `master_course_key` = ' . $this->dbo->q($course_key) . ', ' .
						 '`available_ind` = ' . $this->dbo->q($available_ind) . ' ' .
						 'WHERE `term_code` = ' . $this->dbo->q($this->termCode) . ' ' .
						 'AND `crn` = ' . $this->dbo->q($crn);

				$this->dbo->setQuery($query);
				$added = $this->dbo->execute();
				if ($added)
				{
					$this->logAndPrintLn('MASTER_COURSE_KEY = ' . $course_key . ' was added for TERM = ' . $this->termCode . ' and CRN = ' . $crn);
				}
			}
		}
	}

	/**
	 * Performs the Unmerge Action
	 *
	 * @return void
	 */
	public function unmerge()
	{
		if (!empty($this->crnList) && !empty($this->termCode))
		{
			$this->logAndPrintLn("You've requested that enrollments for '" . implode(',', $this->crnList) . "' be unmerged.");
			$this->logAndPrintLn("You've entered in '" . $this->termCode . "' as the term code to use.");

			$this->logAndPrintLn("Attempting to Unmerge Courses...");
			$this->unmergeCourses();

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

	/**
	 * Updates/Disables a custom Merged Course Record
	 * from the local MySQL database.
	 *
	 * Removes the CRN(s) from the Merged Course Record.
	 * If no more CRNs are in the list it goes ahead
	 * and unmerges the course record.
	 *
	 * @return void
	 */
	public function unmergeCourses()
	{
		foreach($this->crnList as $crn)
		{
			$query = 'select course_key, crosslisted_crns from ' . $this->getCrosslistedTableName() . " " .
					 'where `crosslisted_crns` LIKE ' . $this->dbo->q( '%' . $crn . '%') . ' ' .
					 'and `manually_created` = 1';

			$this->dbo->setQuery($query);
			$rows = $this->dbo->loadAssocList();

			if (!empty($rows))
			{
				foreach($rows as $mergedCourseInfo)
				{
					$course_key = $mergedCourseInfo['course_key'];
					$crns = explode(',', $mergedCourseInfo['crosslisted_crns']);

					$key = array_search($crn, $crns);
					unset($crns[$key]);
					sort($crns, SORT_NUMERIC);

					$updated_crosslisted_crns = implode(',', $crns);
					$updated_primary_crn = $crns[0];

					if (!empty($updated_crosslisted_crns))
					{
						$query = 'UPDATE ' . $this->getCrosslistedTableName() . ' ' .
							 'SET `crosslisted_crns` = ' . $this->dbo->q($updated_crosslisted_crns) . ', ' .
							 '`primary_crn` = ' . $this->dbo->q($updated_primary_crn) . ' ' .
							 'WHERE `course_key` = ' . $this->dbo->q($course_key) . ' ';

						$this->dbo->setQuery($query);
						$updated = $this->dbo->execute();

						if ($updated)
						{
							$this->logAndPrintLn('Updated Crosslisted CRNs List is ' . "'" . $updated_crosslisted_crns . "'" . ' for COURSE_KEY = ' . $course_key);
						}
					}
					else
					{
						$query = 'UPDATE ' . $this->getCrosslistedTableName() . ' ' .
							 'SET `crosslisted_crns` = ' . $this->dbo->q($updated_crosslisted_crns) . ', ' .
							 '`row_status` = ' . $this->dbo->q('disabled') . ' ' .
							 'WHERE `course_key` = ' . $this->dbo->q($course_key);

						$this->dbo->setQuery($query);
						$updated = $this->dbo->execute();

						if ($updated)
						{
							$this->logAndPrintLn('Disabled Row for COURSE_KEY = ' . $course_key . ' because no more CRNs are assigned to it.');
						}
					}
				}
			}
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

				$this->logAndPrintLn("Attempting to Retrieve Crosslisted Course Records for " . $this->termCode . "...");
				$this->retrieveCrosslistedCourseRecords();
				$this->logAndPrintLn("Retrieved " . count($this->crosslistedCourseRecords) . " Crosslisted Course Records to include...");

				$this->logAndPrintLn("Attempting to Retrieve Course Records for " . $this->termCode . "...");
				$this->retrieveRecords();

				$this->logAndPrintLn("Retrieved " . count($this->records) . " Course Records to include...");

				$this->logAndPrintLn("Generating Crosslisted Courses File String...");
				$this->generateCrosslistedFileString();

				$this->logAndPrintLn("Generating Courses File String...");
				$this->generateFileString();

				$this->logAndPrintLn("Writing Courses File String to a file...");
				$result = $this->writeString();
				if ($result)
				{
					// If we successfully wrote the file then send it:
					$this->logAndPrintLn("Sending Crosslisted Courses File String (we send a separate upload first to prevent errors in Blackboard)...");
					$this->sendCrosslistedCoursesString();

					// If we successfully wrote the file then send it:
					$this->logAndPrintLn("Sending Courses File String...");
					$this->sendCoursesString();
				}
			}
		}
	}

	/**
	 * Populates the Crosslisted Course Records
	 * variable.
	 *
	 * @return void
	 */
	public function retrieveCrosslistedCourseRecords()
	{
		$query = 'SELECT *
		          FROM `' . $this->getCrosslistedTableName() . '`';

		$this->dbo->setQuery($query);
		$this->crosslistedCourseRecords = $this->dbo->loadAssocList();
	}

	/**
	 * Generates the Blackboard Output String
	 *
	 * @return void
	 */
	public function generateCrosslistedFileString()
	{
		$output = '';

		$headerFields = array('EXTERNAL_COURSE_KEY', 'COURSE_ID', 'COURSE_NAME', 'START_DATE', 'END_DATE',
							  'DURATION', 'MASTER_COURSE_KEY', 'ROW_STATUS', 'ALLOW_GUEST_IND', 'AVAILABLE_IND',
							  'CATALOG_IND', 'DESC_PAGE', 'DESCRIPTION', 'TERM_KEY');

		$recordFields = array('course_key', 'course_id', 'course_name', 'start_date', 'end_date',
						      'duration', 'master_course_key', 'row_status', 'allow_guest_ind', 'available_ind',
						      'catalog_ind', 'desc_page', 'description', 'term_key');

		$headerLine = implode($this->get('blackboardFieldSeparator', '|'), $headerFields);

		$output .= $headerLine . "\r\n";

		if (!empty($this->crosslistedCourseRecords))
		{
			foreach($this->crosslistedCourseRecords as $xlst_record)
			{
				$data = array();
				$xlst_record['master_course_key'] = '';

				foreach($recordFields as $recordField)
				{
					$data[] = $xlst_record[$recordField];
				}

				$output .= implode($this->get('blackboardFieldSeparator', '|'), $data);

				$output .= "\r\n";
			}
		}

		$this->crosslistedOutputString = utf8_encode(rtrim($output));
	}

	/**
	 * Generates the Blackboard Output String
	 *
	 * @return void
	 */
	public function generateFileString()
	{
		$output = '';

		$headerFields = array('EXTERNAL_COURSE_KEY', 'COURSE_ID', 'COURSE_NAME', 'START_DATE', 'END_DATE',
							  'DURATION', 'MASTER_COURSE_KEY', 'ROW_STATUS', 'ALLOW_GUEST_IND', 'AVAILABLE_IND',
							  'CATALOG_IND', 'DESC_PAGE', 'DESCRIPTION', 'TERM_KEY');

		$recordFields = array('course_key', 'course_id', 'course_name', 'start_date', 'end_date',
						      'duration', 'master_course_key', 'row_status', 'allow_guest_ind', 'available_ind',
						      'catalog_ind', 'desc_page', 'description', 'term_key');

		$headerLine = implode($this->get('blackboardFieldSeparator', '|'), $headerFields);

		$output .= $headerLine . "\r\n";

		if (!empty($this->crosslistedCourseRecords))
		{
			foreach($this->crosslistedCourseRecords as $xlst_record)
			{
				$data = array();
				$xlst_record['master_course_key'] = '';

				foreach($recordFields as $recordField)
				{
					$data[] = $xlst_record[$recordField];
				}

				$output .= implode($this->get('blackboardFieldSeparator', '|'), $data);

				$output .= "\r\n";
			}
		}

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
	 * Sends the Generated Blackboard Crosslisted Course Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendCrosslistedCoursesString()
	{
		$serviceUsername = $this->getCourseStoreUsername();
		$servicePassword = $this->getCourseStorePassword();
		$serviceStoreURL = $this->getCourseStoreUrl();

		$this->sendStringHelper($this->crosslistedOutputString, $serviceUsername, $servicePassword, $serviceStoreURL);
	}

	/**
	 * Sends the Generated Blackboard Output String
	 * to the Service Store URL for the current
	 * syncing process.
	 *
	 * @return void
	 */
	public function sendCoursesString()
	{
		$serviceUsername = $this->getCourseStoreUsername();
		$servicePassword = $this->getCourseStorePassword();
		$serviceStoreURL = $this->getCourseStoreUrl();

		$this->sendString($serviceUsername, $servicePassword, $serviceStoreURL);
	}

	/**
	 * Gets the Crosslisted Courses table name for the
	 * current syncing process.
	 *
	 * @return string
	 */
	public function getCrosslistedTableName()
	{
		return "courses_xlst_" . $this->termCode . "_sync";
	}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardCourseCli')->execute();
