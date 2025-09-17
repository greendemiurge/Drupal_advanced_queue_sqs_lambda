# Drupal Advanced Queue SQS Lambda Base Image

This repository provides a base AWS Lambda image (built on Bref) for running Drupal Advanced Queue jobs triggered from Amazon SQS. It bootstraps Drupal inside the Lambda runtime and processes SQS messages using the advancedqueue and advancedqueue_sqs_backend modules.

What you get in this base image:
- PHP (Bref runtime) with additional extensions (GD, MongoDB) preinstalled.
- A handler script execute_sqs_queue_item.php that boots Drupal once per cold start and processes SQS records.
- Sensible environment variables set for Drupal and Composer inside Lambda.

Typical workflow:
1) Build and push this base image to your ECR (scripts provided), or use the pre‑pushed image from ECR if available to you.
2) In your application’s repository, extend FROM this base image and copy your Drupal project code into /var/task. Then run composer install so vendor autoloading works in Lambda.

## How to extend this image for your project

Create a Dockerfile in your project (or update an existing one) that uses this base image and copies your code. The essential instructions are:

```
FROM ghcr.io/greendemiurge/drupal-advanced-queue-lambda:v83.0.0 AS lambda
ENV PROJECT_ROOT=/var/task
ENV DRUPAL_ROOT=/var/task/web
ENV PATH="${PATH}:/var/task/vendor/bin"
COPY . $PROJECT_ROOT
RUN cd "$PROJECT_ROOT" && composer install --no-interaction --prefer-dist --optimize-autoloader
```

Notes:
- PROJECT_ROOT must contain your composer.json so that composer install installs dependencies to /var/task/vendor.
- DRUPAL_ROOT should point to your Drupal web docroot (commonly /var/task/web for a typical Composer‑based Drupal project). Adjust if your docroot differs.
- The PATH addition makes vendor/bin tools (e.g., Drush if included) available.
- Ensure your codebase includes the advancedqueue and advancedqueue_sqs_backend modules enabled in your Drupal site.

## Environment variables
These are commonly used by the handler and Drupal bootstrap:
- PROJECT_ROOT: Defaults to /var/task in the base image.
- DRUPAL_ROOT: Defaults to /var/task/web.
- SITE_PATH: Optional; defaults to sites/default.
- AWS_REGION: Required for fetching SSM parameters and Secrets Manager values.
- Any variables ending with _PARAM will be resolved from AWS SSM Parameter Store (WithDecryption=true) at cold start. The resulting value is exported under the same name without the _PARAM suffix.
- Any variables ending with _SECRET will be fetched from AWS Secrets Manager at cold start. If a secret is a JSON object, keys are exported as EXPORTNAME_KEY.

Examples:
- Set DB_PASSWORD_PARAM=/my/app/DB_PASSWORD to resolve and export DB_PASSWORD at runtime.
- Set DB_CREDS_SECRET=my/secret/id where the secret is {"user":"foo","pass":"bar"}. This will export DB_CREDS_USER and DB_CREDS_PASS.

## Handler entry point
- The Lambda handler is execute_sqs_queue_item.php (configured via _HANDLER and CMD in the image).
- It uses Bref’s SqsHandler to iterate SQS records and convert them into Advanced Queue jobs using Drupal\advancedqueue_sqs_backend\SqsMessageMapper.

## Local notes / troubleshooting
- Ensure your project composer.json requires the Drupal modules you need (advancedqueue, advancedqueue_sqs_backend, etc.).
- Make sure your Drupal settings.php can read credentials from the environment (DB settings, Redis, etc.). Given secrets resolution occurs at cold start, these variables will be present by the time Drupal boots.
- If your docroot is not /var/task/web, set DRUPAL_ROOT accordingly in your extended image.
- Logs from Lambda (e.g., cold start boot messages, missing modules) will appear in CloudWatch; search for lines starting with [Lambda].

## License
This project is licensed under the [MIT License](license.txt). See the LICENSE file for details.