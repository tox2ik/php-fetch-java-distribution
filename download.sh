composer install
selenium-server-java &
server=$!
php fetch.php
kill $server

