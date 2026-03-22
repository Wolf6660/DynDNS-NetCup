<?php
echo "PHP Version: " . PHP_VERSION . "<br>";

echo "PDO drivers:<pre>";
print_r(PDO::getAvailableDrivers());
echo "</pre>";

echo "SQLite3 class exists: " . (class_exists('SQLite3') ? 'YES' : 'NO') . "<br>";
