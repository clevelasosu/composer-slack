# OSUCOE Slack package
## Installation

```bash
composer config repositories.slack vcs https://github.com/clevelasosu/composer-slack
composer require osucoe/slack
```

## SlackSCIM API
Documentation: https://api.slack.com/scim

SCIM commands require an enterprise admin API key.  It manages enterprise grid users IDP groups.

```php
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.slack.com/scim/v1/',
    'headers' => [
        'Authorization' => 'Bearer '.$slack_api_key,
    ],
]);

$scim = new OSUCOE\Slack\SlackSCIM($client);
$scim->createGroup('mygroup', ['user1']));
$scim->getGroupMembers('mygroup');
$scim->createUser('user2','user2@email.com');

```

## SlackAdmin API
Documentation: https://api.slack.com/admins

Admin API is used for:
 * Managing Teams (workspaces): create, list, set default channels, icons, etc
 * Assigning users to Teams and setting users as owners or admins
 * Approving and managing apps
 * Adding and manging emojis
 
This implementation currently only deals with creating teams and managing a few aspects of users.

These commands require an enterprise app key.
```php
$adminclient = new GuzzleHttp\Client([
    'base_uri' => 'https://slack.com/api/',
]);

$admin = new OSUCOE\Slack\SlackAdmin($adminclient, $conf['slack_admin_api_key']);
$teamid = $admin->createTeam('testworkspace', 'testworkspace', 'This is a description', 'closed');

``` 