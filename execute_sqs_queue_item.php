<?php

require getenv('PROJECT_ROOT') . '/vendor/autoload.php';

use Aws\SecretsManager\SecretsManagerClient;
use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Aws\Ssm\SsmClient;
use Aws\Exception\AwsException;

/**
 * Gets params from SSM secure params.
 *
 * Lambdas lack the ability to resolve secrets from SSM-Secure params, so this
 * will take any param name env var and translate it, e.g.:
 *
 * DB_PASSWORD_PARAM
 *   will be resolved and its value will be set as
 * DB_PASSWORD
 */
function get_secure_params(): void {
  $region = getenv('AWS_REGION');
  $ssm = new SsmClient([
    'version' => 'latest',
    'region' => $region,
  ]);

  $to_fetch = [];
  $param_map = [];

  foreach ($_ENV as $key => $value) {
    if (str_ends_with($key, '_PARAM')) {
      $export_name = substr($key, 0, -6);
      $to_fetch[] = $value;
      $param_map[$value] = $export_name;
    }
  }

  $batch_size = 10;
  for ($i = 0; $i < count($to_fetch); $i += $batch_size) {
    $batch = array_slice($to_fetch, $i, $batch_size);

    $max_attempts = 5;
    $attempt = 0;
    while ($attempt < $max_attempts) {
      try {
        $result = $ssm->getParameters([
          'Names' => $batch,
          'WithDecryption' => TRUE,
        ]);

        foreach ($result['Parameters'] as $param) {
          $name = $param['Name'];
          $value = $param['Value'];
          $env_key = $param_map[$name];

          putenv("$env_key=$value");
          $_ENV[$env_key] = $value;
          $_SERVER[$env_key] = $value;
        }

        if (!empty($result['InvalidParameters'])) {
          foreach ($result['InvalidParameters'] as $invalid) {
            error_log("SSM parameter not found: $invalid");
          }
        }

        break;
      }

      catch (Aws\Exception\AwsException $e) {
        $attempt++;
        if ($attempt >= $max_attempts) {
          error_log("Failed to fetch SSM secrets after $max_attempts attempts: " . $e->getMessage());
        }
        else {
          $base = 100;
          $delay = $base * pow(2, $attempt);
          $jitter = rand(0, 100);
          usleep(($delay + $jitter) * 1000); // convert ms to µs
        }
      }
    }
  }
}

/**
 * Gets secrets from SecretsManager.
 *
 * Lambdas lack the ability to resolve secrets from secrets manager, so this
 * will take any secret name env var and translate it, e.g.:
 *
 * DB_PASSWORD_SECRET
 *   will be resolved and its value will be set as
 * DB_PASSWORD
 *
 * If the secrets are nested JSON, the keys will be appended, e.g.:
 * DB_CREDS_SECRET
 *   contains
 * {user: foo, pass: bar}
 *   will be resolved to
 * DB_CREDS_USER
 * DB_CREDS_PASS
 */
function get_secrets(): void {
  $region = getenv('AWS_REGION') ?: 'us-east-1';

  $secrets = new SecretsManagerClient([
    'version' => 'latest',
    'region' => $region,
  ]);

  foreach ($_ENV as $key => $secret_id) {
    if (!str_ends_with($key, '_SECRET')) {
      continue;
    }

    $export_name = substr($key, 0, -7);
    try {
      $result = $secrets->getSecretValue(['SecretId' => $secret_id]);
      $secret_value = $result['SecretString'] ?? NULL;

      if ($secret_value === NULL) {
        error_log("No SecretString returned for $secret_id");
        continue;
      }
      // Handle JSON-formatted secrets
      if ($decoded = json_decode($secret_value, TRUE)) {
        foreach ($decoded as $sub_key => $sub_val) {
          $full_key = strtoupper("{$export_name}_{$sub_key}");
          putenv("$full_key=$sub_val");
          $_ENV[$full_key] = $sub_val;
          $_SERVER[$full_key] = $sub_val;
        }
      }
      else {
        putenv("$export_name=$secret_value");
        $_ENV[$export_name] = $secret_value;
        $_SERVER[$export_name] = $secret_value;
      }
    }
    catch (AwsException $e) {
      error_log("Failed to fetch secret $secret_id: " . $e->getMessage());
    }
  }
}

// Only run this once at cold start
if (!defined('DRUPAL_BOOTSTRAPPED')) {
  define('DRUPAL_BOOTSTRAPPED', TRUE);
  get_secure_params();
  get_secrets();

  $autoloader = require getenv('PROJECT_ROOT') . '/vendor/autoload.php';
  $site_path = getenv('SITE_PATH') ?: 'sites/default';
  chdir(getenv('DRUPAL_ROOT'));

  $request = Request::createFromGlobals();
  $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('<none>'));
  $request->attributes->set(RouteObjectInterface::ROUTE_NAME, '<none>');

  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel::bootEnvironment();
  $kernel->setSitePath($site_path);
  Drupal\Core\Site\Settings::initialize($kernel->getAppRoot(), $kernel->getSitePath(), $autoloader);

  $kernel->boot();
  $kernel->preHandle($request);

  error_log('[Lambda] Cold start: Drupal booted');
}

return new class extends SqsHandler {

  private $queueStorage;

  private $queueProcessor;

  public function __construct() {
    $this->queueStorage = \Drupal::entityTypeManager()
      ->getStorage('advancedqueue_queue');
    $this->queueProcessor = \Drupal::service('advancedqueue.processor');
  }

  /**
   * The core bref lambda handler.
   *
   * @param \Bref\Event\Sqs\SqsEvent $event
   *   The lambda event, as passed in from SQS.
   * @param \Bref\Context\Context $context
   *   The lambda context object
   */
  public function handleSqs(SqsEvent $event, Context $context): void {
    error_log('[Lambda] Handling SQS event');

    foreach ($event->getRecords() as $record) {
      $message = $record->toArray();
      $body = $message['body'] ?? NULL;

      if (!is_string($body)) {
        error_log("Missing or invalid 'body' in SQS record");
        continue;
      }

      if (!\Drupal::moduleHandler()->moduleExists('advancedqueue')) {
        error_log("Module advancedqueue is NOT enabled");
      }

      if (!\Drupal::moduleHandler()
        ->moduleExists('advancedqueue_sqs_backend')) {
        error_log("Module advancedqueue_sqs_backend is NOT enabled");
      }

      $mapper_class = 'Drupal\\advancedqueue_sqs_backend\\SqsMessageMapper';
      if (!class_exists($mapper_class)) {
        error_log("Class $mapper_class not found after boot.");
        continue;
      }

      /** @var \Drupal\advancedqueue\Entity\JobInterface $job */
      $job = $mapper_class::fromSqsMessageArray($message)->toJob();
      $queue = $this->queueStorage->load($job->getQueueId());
      if ($queue) {
        $this->queueProcessor->processJob($job, $queue);
      }
      else {
        error_log("Queue not found: " . $job->getQueueId());
      }
    }
  }

};
