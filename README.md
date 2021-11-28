===  
with phpUnit asserts
composer update
cd public  
php app.php  

no phpUnit asserts
cd src/Service  
comment in source.php assertions block L::563 till L::611
php source.php
