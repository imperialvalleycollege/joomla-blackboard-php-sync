# Spring 2013 Section:

/usr/bin/php /var/www/vhosts/example.com/blackboard/person.php --termcode 201320,201322 --action sync-and-send

sleep 30

/usr/bin/php /var/www/vhosts/example.com/blackboard/course.php --termcode 201320,201322 --action sync-and-send

sleep 30

/usr/bin/php /var/www/vhosts/example.com/blackboard/coursemembership.php --termcode 201320,201322 --action sync-and-send

# Perform regular cleaning:
/usr/bin/php /var/www/vhosts/example.com/blackboard/clean.php --days 7
