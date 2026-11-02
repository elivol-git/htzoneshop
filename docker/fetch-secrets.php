<?php
// Prints KEY=value lines (dotenv format) from a JSON secret in AWS Secrets Manager.
// Credentials come from the EC2 instance profile (no keys on disk).
require __DIR__.'/../vendor/autoload.php';

use Aws\SecretsManager\SecretsManagerClient;

$secretId = getenv('AWS_SECRET_ID');
$region = getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: 'eu-central-1';

$client = new SecretsManagerClient(['version' => 'latest', 'region' => $region]);
$secret = json_decode($client->getSecretValue(['SecretId' => $secretId])['SecretString'], true, flags: JSON_THROW_ON_ERROR);

// Non-secret defaults; the secret's keys override them.
$env = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => getenv('APP_URL') ?: 'http://localhost',
    'LOG_CHANNEL' => 'stderr',
    'DB_CONNECTION' => 'mysql',
    'DB_PORT' => '3306',
    'CACHE_STORE' => 'file',
    'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync',
];

foreach ($secret as $key => $value) {
    $env[$key] = (string) $value;
}

foreach ($env as $key => $value) {
    echo $key.'="'.addcslashes($value, "\"\\$\n").'"'.PHP_EOL;
}
