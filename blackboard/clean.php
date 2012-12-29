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
 * Clean CLI App for Blackboard.
 *
 * This application will clean out any old log or sync
 * files from the filesystem that are older than the specified
 * number of days (will default to Global Config value if a value
 * is not provided).
 *
 * @package  BlackboardCli
 * @since    11.3
 */
class BlackboardCleanCli extends BlackboardBaseCli
{
	/**
	 * Singular version of the
	 * current Blackboard Sync Process
	 *
	 * @var string
	 */
	protected $syncType = 'clean';

	/**
	 * Friendly Singular version of the
	 * current Blackboard Sync Process
	 * (can include spaces/capitalized letters)
	 *
	 * Used within the logging methods primarily.
	 *
	 * @var string
	 */
	protected $friendlyName = 'Clean';

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

	protected function clean()
	{
		parent::clean();
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
	public function sync(){}

	/**
	 * Performs the sending functions from
	 * the local MySQL database to Blackboard.
	 *
	 * @return void
	 *
	 */
	public function send(){}
}

// Instantiate the application object, passing the class name to JApplicationCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('BlackboardCleanCli')->execute();
