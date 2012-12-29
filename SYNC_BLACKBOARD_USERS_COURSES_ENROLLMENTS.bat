ECHO OFF

ECHO Starting Person Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\person.php --termcode 201320,201322 --action sync

ECHO Waiting 30 seconds before running next command
ping 123.45.67.89 -n 1 -w 30000 > nul

ECHO Starting Course Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\course.php --termcode 201320,201322 --action sync

ECHO Waiting 30 seconds before running next command
ping 123.45.67.89 -n 1 -w 30000 > nul

ECHO Starting Course Membership Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\coursemembership.php --termcode 201320,201322 --action sync

ECHO Waiting 30 seconds before running next command
ping 123.45.67.89 -n 1 -w 30000 > nul

C:\xampp\php\php C:\xampp\htdocs\blackboard\clean.php --days 7

