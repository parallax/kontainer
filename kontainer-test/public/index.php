<?php
require_once('vendor/autoload.php');
use \Rollbar\Rollbar;
use \Rollbar\Payload\Level;
Rollbar::init(
    array(
        'access_token' => '3f0a6734284d415aa7170476628795c5',
        'environment' => 'development'
    )
);

echo "Build: ";
echo $_ENV['BUILD'];
echo "\n";
echo "Branch: ";
echo $_ENV['SITE_BRANCH'];
echo "\n";
echo $_ENV['HOSTNAME'];
echo "\n";
echo "PHP OK\n";
?>
