ECHO OFF 

ECHO Starting Term Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\term.php --action sync

ECHO Waiting 30 seconds before running next command
ping 123.45.67.89 -n 1 -w 30000 > nul

ECHO Starting Course Category Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\coursecategory.php --action sync

ECHO Waiting 30 seconds before running next command
ping 123.45.67.89 -n 1 -w 30000 > nul

ECHO Starting Course Category Membership Sync
C:\xampp\php\php C:\xampp\htdocs\blackboard\coursecategorymembership.php --action sync