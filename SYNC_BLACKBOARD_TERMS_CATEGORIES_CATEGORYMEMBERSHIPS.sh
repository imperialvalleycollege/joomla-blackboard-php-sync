/usr/bin/php /var/www/vhosts/example.com/blackboard/term.php --action sync-and-send

sleep 30

/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategory.php --action sync-and-send

sleep 30

/usr/bin/php /var/www/vhosts/example.com/blackboard/coursecategorymembership.php --action sync-and-send