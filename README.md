Blackboard PHP Sync
========================

This set of Joomla CLI Applications allow you to setup a reliable syncing process between your local
on campus Ellucian Banner installation and your production/test Blackboard installation(s).

Background
============

We're a California community college that just started using Blackboard in Fall 2012. Initially, we approached our syncing process 
by using the built-in "Refresh" method that comes with Blackboard since it seemed to be the simplest method to get going with 
and would automatically take care of "diffing" the current sync file with the one that had been previously submitted (which would
automatically disable any students that had dropped a course). Unfortunately, in the second week of the semester the Refresh method broke down
and no longer worked properly and while it was confirmed as a bug, the slated fix date wasn't going to be until mid-2013 sometime.

The "Store" method seemed to work more reliably, and could disable students in courses like the Refresh method had, but because of the way we initially built the syncing process (only sending up the 
current list of active students for the courses) we didn't really have the full history of students that needed to be disabled/enabled
for a course (Banner's list is pretty accurate, though for students that drop earlier in the semester their record ends up getting removed from the dataset completely).

So we decided to switch back to something similar to our previous process we had used before Blackboard.

What we do is sync up our student data to an intermediate MySQL database which contains the full history of drops/enrollments which gives us the
correct data needed for the "Store" method to disable/enable the students.

Additionally, switching to this method gives us additional flexibility in allowing for "merged courses" to be defined for instructors teaching
multiple sections of the same course that aren't already crosslisted (the program handles crosslisting for Banner courses automatically). Within the course
syncing process there are additional "merge" and "unmerge" actions available that allow you to display those courses in a merged way within Blackboard.

There might be a few assumptions in the code that are particular to our college, but hopefully I've done a good job in generalizing
things enough for the code to be useful for your institution. If you have any suggestions, please contact us at webjunky@imperial.edu.

Outside Dependencies:
============

There are a few things you'll need to have available for these syncing processes to work:
- Server/Local Test Environment with PHP Installed
- MySQL database to hold the syncing data for the processes (required tables are automatically created by the scripts when they are run)
- Oracle Instant Client installed with PHP OCI8 and PDO-OCI extensions enabled
- Database Credentials for your institution's Banner Database that allow you to query the required tables (SPRIDEN, GOBTPAC, GORPAUD, SSBSECT, SCBCRSE, SCBDESC, SFRSTCR, PEBEMPL, and STVTERM are some examples of tables that are queried)
- Blackboard Service Credentials
- Ability to use cURL, Sockets, or HTTP Streams so that the sync file can be sent to the Blackboard Service URL Endpoints.

Installation
============

Clone or download the https://github.com/imperialvalleycollege/blackboard-php-sync repository to your local machine.

The main file that needs to be setup is the configuration.php file.

To do this go ahead and copy the ``configuration.dist.php`` file to ``configuration.php``.

Once the file has been created go ahead and open it up using your favorite text editor and enter in the required values
needed to get the application(s) fully working.

Particularly, you should make sure these values are populated:
- MySQL database connection information 
- Oracle database connection information
- Blackboard Domain Information
- Blackboard Service Usernames/Passwords
- Notification Email Settings

Once the configuration file has been setup you're pretty much good to go.

If you are using XAMPP on your local Windows machine and have the ``blackboard`` folder located in ``C:\xampp\htdocs`` you can run the Windows examples with valid term codes for your institution.

Example Usage
================

There is a separate ``EXAMPLE_USAGE.txt`` file in this repository that provides a variety of examples for you to review.

However, the examples have been included below as well.

One thing I'd like to explain are the 3 different options available for syncind/sending with Blackboard.

SYNC, SEND, and SYNC-AND-SEND Explanation:
-----------
The above 3 options are supported for the 6 primary syncing processes (person, course, course membership and term, course category, course category membership).

- sync - Use this method from the command line if you simply want to sync your local MySQL database with Ellucian's Banner for that particular process
- send - Use this method from the command line if you simply want to send whatever was last synced to the local MySQL database to Blackboard for that particular process.
- sync-and-send - Use this method from the command line if you want to sync your local MySQL database with Ellucian's Banner and send it to Blackboard for that particular process.

Linux Examples:
-----------

``/usr/bin/php /var/www/vhosts/example.com/blackboard/term.php --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/person.php --termcode 201320,201322 --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320,201322 --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursemembership.php --termcode 201320,201322 --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategory.php --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategorymembership.php --action sync-and-send``

Merge/Unmerge Linux Examples:
-----------

``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320 --action merge --crns 20764,20762``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320 --action unmerge --crns 20764``

Windows Examples:
-----------

``C:\xampp\php\php C:\xampp\htdocs\blackboard\term.php --action sync-and-send``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\person.php --termcode 201320,201322 --action sync-and-send``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\course.php --termcode 201320,201322 --action sync-and-send``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\coursemembership.php --termcode 201320,201322 --action sync-and-send``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\coursecategory.php --action sync-and-send``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\coursecategorymembership.php --action sync-and-send``

Merge/Unmerge Windows Examples:
-----------

``C:\xampp\php\php C:\xampp\htdocs\blackboard\course.php --termcode 201320 --action merge --crns 20764,20762``
``C:\xampp\php\php C:\xampp\htdocs\blackboard\course.php --termcode 201320 --action unmerge --crns 20764``

Command Line Applications
=========================

Each of the files below located within the "blackboard" folder are individual CLI Applications that can
be run independently of each other.

In other words, you can choose to use one, some or all of them depending on your needs.

For regular syncing during the term the recommended order to run the processes would be:
- person
- course
- course membership

These would be run on a frequent basis (every 4-6 hours) via a cron job or scheduled task on the machine you end using to run
the programs.

The following processes can be run on a less frequent basis (once a week) and do not require a term code value to be provided (they just automatically bring in a set of upcoming values):
- term 
- course category
- course category membership

It is recommended to run the term syncing process once before doing a course sync (just to prep the data in Blackboard so the term the course refers to is valid when the course is added).

clean.php
-----------

This application will clean out any old log or sync
files from the filesystem that are older than the specified
number of days (will default to Global Config value if a value
is not provided).

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/clean.php --days 7``

course.php
-----------

This application will sync Courses
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320,201322 --action sync-and-send``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320 --action merge --crns 20764,20762``
``/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320 --action unmerge --crns 20764``

coursecategory.php
-----------

This application will sync Course Categories
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategory.php --action sync-and-send``

coursecategorymembership.php
-----------

This application will sync Course Category Memberships
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategorymembership.php --action sync-and-send``
 
coursemembership.com
------------

This application will sync Course Memberships
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/coursemembership.php --termcode 201320,201322 --action sync-and-send``

person.php
-----------

This application will sync User Records
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/person.php --termcode 201320,201322 --action sync-and-send``

term
-----------

This application will sync Terms
from Ellucian's Banner ERP for the provided
term code to Blackboard.

Example:
``/usr/bin/php /var/www/vhosts/example.com/blackboard/term.php --action sync-and-send``

Combined Command Line Applications
=========================

In the root folder of this repository, I've included 4 example CLI applications (2 for Windows, 2 for Linux) that shows
how you can combine one or more calls to one or more of the syncing processes in a single file.

For our needs, I've separated this into 2 files (1 for a person, course and course membership sync-and-send and 1 for a term, course category and course category membership sync-and-send).

Using these files reduces the number of cron jobs/scheduled tasks you would have to setup on your end and also makes it easier to change/edit what happens over time 
(you can just go and edit one of the files below and change the term code rather than having to go and modify the cron job/scheduled task).

SYNC_BLACKBOARD_TERMS_CATEGORIES_CATEGORYMEMBERSHIPS.bat
-----------

This is the Windows batch file that performs the following syncs:
- term 
- course category
- course category membership

SYNC_BLACKBOARD_TERMS_CATEGORIES_CATEGORYMEMBERSHIPS.sh
-----------

This is the Linux shell script file that performs the following syncs:
- term 
- course category
- course category membership

SYNC_BLACKBOARD_USERS_COURSES_ENROLLMENTS.bat
-----------

This is the Windows batch file that performs the following syncs:
- person
- course
- course membership

SYNC_BLACKBOARD_USERS_COURSES_ENROLLMENTS.sh
-----------

This is the Linux shell script file that performs the following syncs:
- person
- course
- course membership