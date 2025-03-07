<?php

/*
 * Copyright 2005 - 2021 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../class/centreonAuth.class.php';
require_once __DIR__ . '/../../class/centreonLog.class.php';
require_once __DIR__ . '/../functions.php';

use Symfony\Component\Yaml\Yaml;

$centreonLog = new CentreonLog();

//error specific content
$versionOfTheUpgrade = 'UPGRADE - 22.04.0-beta.1: ';

try {
    /**
     * Create Tables
     */
    $errorMessage = "Unable to create 'password_expiration_excluded_users' table";
    $pearDB->query(
        "CREATE TABLE IF NOT EXISTS `password_expiration_excluded_users` (
        `provider_configuration_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        PRIMARY KEY (`provider_configuration_id`, `user_id`),
        CONSTRAINT `password_expiration_excluded_users_provider_configuration_id_fk`
          FOREIGN KEY (`provider_configuration_id`)
          REFERENCES `provider_configuration` (`id`) ON DELETE CASCADE,
        CONSTRAINT `password_expiration_excluded_users_provider_user_id_fk`
          FOREIGN KEY (`user_id`)
          REFERENCES `contact` (`contact_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $errorMessage = "Unable to create table 'contact_password'";
    $pearDB->query(
        "CREATE TABLE IF NOT EXISTS `contact_password` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `password` varchar(255) NOT NULL,
        `contact_id` int(11) NOT NULL,
        `creation_date` BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (`id`),
        KEY `contact_password_contact_id_fk` (`contact_id`),
        INDEX `creation_date_index` (`creation_date`),
        CONSTRAINT `contact_password_contact_id_fk` FOREIGN KEY (`contact_id`)
        REFERENCES `contact` (`contact_id`) ON DELETE CASCADE)"
    );

    /**
     * Alter Tables
     */
    if (
        $pearDB->isColumnExist('contact', 'login_attempts') !== 1
        && $pearDB->isColumnExist('contact', 'blocking_time') !== 1
    ) {
        // Add login blocking mechanism to contact
        $errorMessage = 'Impossible to add "login_attempts" and "blocking_time" columns to "contact" table';
        $pearDB->query(
            "ALTER TABLE `contact`
            ADD `login_attempts` INT(11) UNSIGNED DEFAULT NULL,
            ADD `blocking_time` BIGINT(20) UNSIGNED DEFAULT NULL"
        );
    }

    $errorMessage = "Unable to find constraint unique_index from security_token";
    $constraintExistStatement = $pearDB->query(
        'SELECT CONSTRAINT_NAME from INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_NAME="security_token" AND CONSTRAINT_NAME="unique_token"'
    );
    if ($constraintExistStatement->fetch() !== false) {
        $errorMessage = "Unable to remove unique_index from security_token";
        $pearDB->query("ALTER TABLE `security_token` DROP INDEX `unique_token`");
    }

    $errorMessage = "Unable to alter table security_token";
    $pearDB->query("ALTER TABLE `security_token` MODIFY `token` varchar(4096)");

    if ($pearDB->isColumnExist('provider_configuration', 'custom_configuration') !== 1) {
        // Add custom_configuration to provider configurations
        $errorMessage = "Unable to add column 'custom_configuration' to table 'provider_configuration'";
        $pearDB->query(
            "ALTER TABLE `provider_configuration` ADD COLUMN `custom_configuration` JSON NOT NULL AFTER `name`"
        );
    }

    /**
     * Transactional queries
     */
    $pearDB->beginTransaction();

    $errorMessage = "Unable to select existing passwords from 'contact' table";
    if ($pearDB->isColumnExist('contact', 'contact_passwd') === 1) {
        $getPasswordResult = $pearDB->query(
            "SELECT `contact_id`, `contact_passwd` FROM `contact` WHERE `contact_passwd` IS NOT NULL"
        );

        // Move old password from contact to contact_password
        $errorMessage = "Unable to insert password in 'contact_password' table";
        $statement = $pearDB->prepare(
            "INSERT INTO `contact_password` (`password`, `contact_id`, `creation_date`)
            VALUES (:password, :contactId, :creationDate)"
        );
        while ($row = $getPasswordResult->fetch()) {
            $statement->bindValue(':password', $row['contact_passwd'], \PDO::PARAM_STR);
            $statement->bindValue(':contactId', $row['contact_id'], \PDO::PARAM_INT);
            $statement->bindValue(':creationDate', time(), \PDO::PARAM_INT);
            $statement->execute();
        }
    }

    //Insert default providers configurations
    $errorMessage = "Impossible to add default OpenID provider configuration";
    insertOpenIdConfiguration($pearDB);
    $errorMessage = "Impossible to add default WebSSO provider configuration";
    insertWebSSOConfiguration($pearDB);
    $errorMessage = "Unable to insert default local security policy configuration";
    updateSecurityPolicyConfiguration($pearDB);

    /**
     * Add new UnifiedSQl broker output
     */
    $errorMessage = 'Unable to update cb_type table ';
    $pearDB->query(
        "UPDATE `cb_type` set type_name = 'Perfdata Generator (Centreon Storage) - DEPRECATED'
        WHERE type_shortname = 'storage'"
    );
    $pearDB->query(
        "UPDATE `cb_type` set type_name = 'Broker SQL database - DEPRECATED'
        WHERE type_shortname = 'sql'"
    );

    $errorMessage = "Unable to add 'unifed_sql' broker configuration output";
    addNewUnifiedSqlOutput($pearDB);
    $errorMessage = "Unable to migrate broker config to unified_sql";
    migrateBrokerConfigOutputsToUnifiedSql($pearDB);

    $errorMessage = "Unable to configure centreon-gorgone api user";
    configureGorgoneApiUser($pearDB);

    $errorMessage = 'Unable to exclude Gorgone / MBI / MAP users from password policy';
    excludeUsersFromPasswordPolicy($pearDB);

    $pearDB->commit();
    if ($pearDB->isColumnExist('contact', 'contact_passwd') === 1) {
        $errorMessage = "Unable to drop column 'contact_passwd' from 'contact' table";
        $pearDB->query("ALTER TABLE `contact` DROP COLUMN `contact_passwd`");
    }
} catch (\Exception $e) {
    if ($pearDB->inTransaction()) {
        $pearDB->rollBack();
    }

    $centreonLog->insertLog(
        4,
        $versionOfTheUpgrade . $errorMessage .
        " - Code : " . (int)$e->getCode() .
        " - Error : " . $e->getMessage() .
        " - Trace : " . $e->getTraceAsString()
    );

    throw new \Exception($versionOfTheUpgrade . $errorMessage, (int)$e->getCode(), $e);
}

/**
 * Insert SSO configuration
 *
 * @param CentreonDB $pearDB
 * @return void
 */
function insertWebSSOConfiguration(CentreonDB $pearDB): void
{
    $customConfiguration = [
        "trusted_client_addresses" => [],
        "blacklist_client_addresses" => [],
        "login_header_attribute" => "HTTP_AUTH_USER",
        "pattern_matching_login" => null,
        "pattern_replace_login" => null
    ];
    $isActive = false;
    $isForced = false;
    $statement = $pearDB->query("SELECT * FROM options WHERE `key` LIKE 'sso_%'");
    $result = $statement->fetchAll(\PDO::FETCH_ASSOC);
    if (!empty($result)) {
        foreach ($result as $configLine) {
            switch ($configLine['key']) {
                case 'sso_enable':
                    $isActive = $configLine['value'] === '1';
                    break;
                case 'sso_mode':
                    $isForced = $configLine['value'] === '0'; //'0' SSO Only, '1' Mixed
                    break;
                case 'sso_trusted_clients':
                    $customConfiguration['trusted_client_addresses'] = !empty($configLine['value'])
                        ? explode(',', $configLine['value'])
                        : [];
                    break;
                case 'sso_blacklist_clients':
                    $customConfiguration['blacklist_client_addresses'] = !empty($configLine['value'])
                        ? explode(',', $configLine['value'])
                        : [];
                    break;
                case 'sso_header_username':
                    $customConfiguration['login_header_attribute'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'sso_username_pattern':
                    $customConfiguration['pattern_matching_login'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'sso_username_replace':
                    $customConfiguration['pattern_replace_login'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
            }
        }
        $pearDB->query("DELETE FROM options WHERE `key` LIKE 'sso_%'");
    }
    $insertStatement = $pearDB->prepare(
        "INSERT INTO provider_configuration (`type`,`name`,`custom_configuration`,`is_active`,`is_forced`)
        VALUES ('web-sso','web-sso', :customConfiguration, :isActive, :isForced)"
    );
    $insertStatement->bindValue(':customConfiguration', json_encode($customConfiguration), \PDO::PARAM_STR);
    $insertStatement->bindValue(':isActive', $isActive ? '1' : '0', \PDO::PARAM_STR);
    $insertStatement->bindValue(':isForced', $isForced ? '1' : '0', \PDO::PARAM_STR);
    $insertStatement->execute();
}

/**
 * insert OpenId Configuration Default configuration.
 *
 * @param CentreonDB $pearDB
 */
function insertOpenIdConfiguration(CentreonDB $pearDB): void
{
    $customConfiguration = [
        "trusted_client_addresses" => [],
        "blacklist_client_addresses" => [],
        "base_url" => null,
        "authorization_endpoint" => null,
        "token_endpoint" => null,
        "introspection_token_endpoint" => null,
        "userinfo_endpoint" => null,
        "endsession_endpoint" => null,
        "connection_scopes" => [],
        "login_claim" => null,
        "client_id" => null,
        "client_secret" => null,
        "authentication_type" => "client_secret_post",
        "verify_peer" => true
    ];
    $isActive = false;
    $isForced = false;
    $statement = $pearDB->query("SELECT * FROM options WHERE `key` LIKE 'openid_%'");
    $result = $statement->fetchAll(\PDO::FETCH_ASSOC);
    if (!empty($result)) {
        foreach ($result as $configLine) {
            switch ($configLine['key']) {
                case 'openid_connect_enable':
                    $isActive = $configLine['value'] === '1';
                    break;
                case 'openid_connect_mode':
                    $isForced = $configLine['value'] === '0'; //'0' OpenId Connect Only, '1' Mixed
                    break;
                case 'openid_connect_trusted_clients':
                    $customConfiguration['trusted_client_addresses'] = !empty($configLine['value'])
                        ? explode(',', $configLine['value'])
                        : [];
                    break;
                case 'openid_connect_blacklist_clients':
                    $customConfiguration['blacklist_client_addresses'] = !empty($configLine['value'])
                        ? explode(',', $configLine['value'])
                        : [];
                    break;
                case 'openid_connect_base_url':
                    $customConfiguration['base_url'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_authorization_endpoint':
                    $customConfiguration['authorization_endpoint'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_token_endpoint':
                    $customConfiguration['token_endpoint'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_introspection_endpoint':
                    $customConfiguration['introspection_token_endpoint'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_userinfo_endpoint':
                    $customConfiguration['userinfo_endpoint'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_end_session_endpoint':
                    $customConfiguration['endsession_endpoint'] = !empty($configLine['value'])
                        ? $configLine['value']
                        : null;
                    break;
                case 'openid_connect_scope':
                    $customConfiguration['connection_scopes'] = !empty($configLine['value'])
                        ? explode(' ', $configLine['value'])
                        : [];
                    break;
                case 'openid_connect_login_claim':
                    $customConfiguration['login_claim'] = !empty($configLine['value']) ? $configLine['value'] : null;
                    break;
                case 'openid_connect_client_id':
                    $customConfiguration['client_id'] = !empty($configLine['value']) ? $configLine['value'] : null;
                    break;
                case 'openid_connect_client_secret':
                    $customConfiguration['client_secret'] = !empty($configLine['value']) ? $configLine['value'] : null;
                    break;
                case 'openid_connect_client_basic_auth':
                    $customConfiguration['authentication_type'] = $configLine['value'] === '1'
                        ? 'client_secret_basic'
                        : 'client_secret_post';
                    break;
                case 'openid_connect_verify_peer':
                    // '1' is Verify Peer disable
                    $customConfiguration['verify_peer'] = $configLine['value'] === '1' ? false : true;
                    break;
            }
        }
        $pearDB->query("DELETE FROM options WHERE `key` LIKE 'open_id%'");
    }
    $insertStatement = $pearDB->prepare(
        "INSERT INTO provider_configuration (`type`,`name`,`custom_configuration`,`is_active`,`is_forced`)
        VALUES ('openid','openid', :customConfiguration, :isActive, :isForced)"
    );
    $insertStatement->bindValue(':customConfiguration', json_encode($customConfiguration), \PDO::PARAM_STR);
    $insertStatement->bindValue(':isActive', $isActive ? '1' : '0', \PDO::PARAM_STR);
    $insertStatement->bindValue(':isForced', $isForced ? '1' : '0', \PDO::PARAM_STR);
    $insertStatement->execute();
}

/**
 * Handle new broker output creation 'unified_sql'
 *
 * @param CentreonDB $pearDB
 */
function addNewUnifiedSqlOutput(CentreonDB $pearDB): void
{
    // Add new output type 'unified_sql'
    $statement = $pearDB->query("SELECT cb_module_id FROM cb_module WHERE name = 'Storage'");
    $module = $statement->fetch();
    if ($module === false) {
        throw new Exception("Cannot find 'Storage' module in cb_module table");
    }
    $moduleId = $module['cb_module_id'];

    $stmt = $pearDB->prepare(
        "INSERT INTO `cb_type` (`type_name`, `type_shortname`, `cb_module_id`)
        VALUES ('Unified SQL', 'unified_sql', :cb_module_id)"
    );
    $stmt->bindValue(':cb_module_id', $moduleId, PDO::PARAM_INT);
    $stmt->execute();
    $typeId = $pearDB->lastInsertId();

    // Link new type to tag 'output'
    $statement = $pearDB->query("SELECT cb_tag_id FROM cb_tag WHERE tagname = 'Output'");
    $tag = $statement->fetch();
    if ($tag === false) {
        throw new Exception("Cannot find 'Output' tag in cb_tag table");
    }
    $tagId = $tag['cb_tag_id'];

    $stmt = $pearDB->prepare(
        "INSERT INTO `cb_tag_type_relation` (`cb_tag_id`, `cb_type_id`, `cb_type_uniq`)
        VALUES (:cb_tag_id, :cb_type_id, 0)"
    );
    $stmt->bindValue(':cb_tag_id', $tagId, PDO::PARAM_INT);
    $stmt->bindValue(':cb_type_id', $typeId, PDO::PARAM_INT);
    $stmt->execute();

    // Create new field 'unified_sql_db_type' with fixed value
    $pearDB->query("INSERT INTO options VALUES ('unified_sql_db_type', 'mysql')");

    $pearDB->query(
        "INSERT INTO `cb_field` (fieldname, displayname, description, fieldtype, external)
        VALUES ('db_type', 'DB type', 'Target DBMS.', 'text', 'T=options:C=value:CK=key:K=unified_sql_db_type')"
    );
    $fieldId = $pearDB->lastInsertId();

    // Add form fields for 'unified_sql' output
    $inputs = [];
    $statement = $pearDB->query(
        "SELECT DISTINCT(tfr.cb_field_id), tfr.is_required FROM cb_type_field_relation tfr, cb_type t, cb_field f
        WHERE tfr.cb_type_id = t.cb_type_id
        AND t.type_shortname in ('sql', 'storage')
        AND tfr.cb_field_id = f.cb_field_id
        AND f.fieldname NOT LIKE 'db_type'
        ORDER BY tfr.order_display"
    );
    $inputs = $statement->fetchAll();
    if (empty($inputs)) {
        throw new Exception("Cannot find fields in cb_type_field_relation table");
    }

    $inputs[] = ['cb_field_id' => $fieldId, 'is_required' => 1];

    $query = "INSERT INTO `cb_type_field_relation` (`cb_type_id`, `cb_field_id`, `is_required`, `order_display`)";
    $bindedValues = [];
    foreach ($inputs as $key => $input) {
        $query .= $key === 0 ? " VALUES " : ", ";
        $query .= "(:cb_type_id_$key, :cb_field_id_$key, :is_required_$key, :order_display_$key)";

        $bindedValues[':cb_type_id_' . $key] = $typeId;
        $bindedValues[':cb_field_id_' . $key] = $input['cb_field_id'];
        $bindedValues[':is_required_' . $key] = $input['is_required'];
        $bindedValues[':order_display_' . $key] = (int) $key + 1;
    }
    $stmt = $pearDB->prepare($query);
    foreach ($bindedValues as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
}

/**
 * Insert security policy configuration into local provider custom configuration
 *
 * @param CentreonDB $pearDB
 */
function updateSecurityPolicyConfiguration(CentreonDB $pearDB): void
{
    $localProviderConfiguration = json_encode([
        "password_security_policy" => [
            "password_length" => 12,
            "has_uppercase_characters" => true,
            "has_lowercase_characters" => true,
            "has_numbers" => true,
            "has_special_characters" => true,
            "attempts" => 5,
            "blocking_duration" => 900,
            "password_expiration_delay" => 15552000,
            "delay_before_new_password" => null,
            "can_reuse_passwords" => false,
        ],
    ]);
    $statement = $pearDB->prepare(
        "UPDATE `provider_configuration`
        SET `custom_configuration` = :localProviderConfiguration
        WHERE `name` = 'local'"
    );
    $statement->bindValue(':localProviderConfiguration', $localProviderConfiguration, \PDO::PARAM_STR);
    $statement->execute();
}

/**
 * Migrate broker outputs 'sql' and 'storage' to a unique output 'unified_sql'
 *
 * @param CentreonDB $pearDB
 * @throws \Exception
 * @return void
 */
function migrateBrokerConfigOutputsToUnifiedSql(CentreonDB $pearDB): void
{
    $outputTag = 1;

    // Determine blockIds for output of type sql and storage
    $dbResult = $pearDB->query("SELECT cb_type_id FROM cb_type WHERE type_shortname IN ('sql', 'storage')");
    $typeIds = $dbResult->fetchAll(\PDO::FETCH_COLUMN, 0);
    if (empty($typeIds) || count($typeIds) !== 2) {
        throw new \Exception("Error while retrieving 'sql' and 'storage' in cb_type table");
    }
    $blockIds = array_map(fn ($typeId) => "{$outputTag}_{$typeId}", $typeIds);

    // Retrieve broker config ids to migrate
    $subqueries = [];
    $bindedValues = [];
    foreach ($blockIds as $key => $blockId) {
        $subqueries[] = "SELECT DISTINCT(config_id) FROM cfg_centreonbroker_info
            WHERE config_group = 'output' AND config_key = 'blockId' AND config_value = :blockId_{$key}";
        $bindedValues[":blockId_{$key}"] = $blockId;
    }
    $stmt = $pearDB->prepare(implode(' INTERSECT ', $subqueries));
    foreach ($bindedValues as $param => $value) {
        $stmt->bindValue($param, $value, \PDO::PARAM_STR);
    }
    $stmt->execute();
    $configIds = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    if (empty($configIds)) {
        throw new \Exception("Cannot find broker config ids to migrate");
    }

    // Retrieve unified_sql type id
    $dbResult = $pearDB->query("SELECT cb_type_id FROM cb_type WHERE type_shortname = 'unified_sql'");
    $unifiedSqlType = $dbResult->fetch(\PDO::FETCH_COLUMN, 0);
    if (empty($unifiedSqlType)) {
        throw new \Exception("Cannot find 'unified_sql' in cb_type table");
    }
    $unifiedSqlTypeId = (int) $unifiedSqlType['cb_type_id'];

    foreach ($configIds as $configId) {
        // Find next config group id
        $dbResult = $pearDB->query(
            "SELECT MAX(config_group_id) as max_config_group_id FROM cfg_centreonbroker_info
            WHERE config_id = $configId AND config_group = 'output'"
        );
        $maxConfigGroupId = $dbResult->fetch(\PDO::FETCH_COLUMN, 0);
        if (empty($maxConfigGroupId)) {
            throw new \Exception("Cannot find max config group id in cfg_centreonbroker_info table");
        }
        $nextConfigGroupId = (int) $maxConfigGroupId['max_config_group_id'] + 1;

        // Find config group ids of outputs to replace
        $dbResult = $pearDB->query(
            "SELECT config_group_id FROM cfg_centreonbroker_info
            WHERE config_id = $configId AND config_key = 'blockId'
            AND config_value IN ('" . implode('\', \'', $blockIds) . "')"
        );
        $configGroupIds = $dbResult->fetchAll(\PDO::FETCH_COLUMN, 0);
        if (empty($configGroupIds)) {
            throw new \Exception("Cannot find config group ids in cfg_centreonbroker_info table");
        }

        // Build unified sql output config from outputs to replace
        $unifiedSqlOutput = [];
        foreach ($configGroupIds as $configGroupId) {
            $dbResult = $pearDB->query(
                "SELECT * FROM cfg_centreonbroker_info
                WHERE config_id = $configId AND config_group = 'output' AND config_group_id = $configGroupId"
            );
            while ($row = $dbResult->fetch()) {
                $unifiedSqlOutput[$row['config_key']] = array_merge($unifiedSqlOutput[$row['config_key']] ?? [], $row);
                $unifiedSqlOutput[$row['config_key']]['config_group_id'] = $nextConfigGroupId;
            }
        }
        if (empty($unifiedSqlOutput)) {
            throw new \Exception("Cannot find conf for unified sql from cfg_centreonbroker_info table");
        }

        $unifiedSqlOutput['name']['config_value'] = str_replace(
            ['sql', 'perfdata'],
            'unified-sql',
            $unifiedSqlOutput['name']['config_value']
        );
        $unifiedSqlOutput['type']['config_value'] = 'unified_sql';
        $unifiedSqlOutput['blockId']['config_value'] = "{$outputTag}_{$unifiedSqlTypeId}";

        // Insert new output
        $queryRows = [];
        $bindedValues = [];
        $columnNames = null;
        foreach ($unifiedSqlOutput as $configKey => $configInput) {
            $columnNames = $columnNames ?? implode(", ", array_keys($configInput));

            $queryKeys = [];
            foreach ($configInput as $key => $value) {
                $queryKeys[] = ":" . $configKey . '_' . $key;
                if (in_array($key, ['config_key', 'config_value', 'config_group'])) {
                    $bindedValues[':' . $configKey . '_' . $key] = ['value' => $value, 'type' => \PDO::PARAM_STR];
                } else {
                    $bindedValues[':' . $configKey . '_' . $key] = ['value' => $value, 'type' => \PDO::PARAM_INT];
                }
            }
            if (! empty($queryKeys)) {
                $queryRows[] = '(' . implode(', ', $queryKeys) . ')';
            }
        }

        if (! empty($queryRows) && $columnNames !== null) {
            $query = "INSERT INTO cfg_centreonbroker_info ($columnNames) VALUES ";
            $query .= implode(', ', $queryRows);

            $stmt = $pearDB->prepare($query);
            foreach ($bindedValues as $key => $value) {
                $stmt->bindValue($key, $value['value'], $value['type']);
            }
            $stmt->execute();
        }

        // Delete deprecated outputs
        $bindedValues = [];
        foreach ($configGroupIds as $index => $configGroupId) {
            $bindedValues[':id_' . $index] = $configGroupId;
        }

        $stmt = $pearDB->prepare(
            "DELETE FROM cfg_centreonbroker_info
            WHERE config_id = $configId
            AND config_group = 'output'
            AND config_group_id IN (" . implode(', ', array_keys($bindedValues)) . ")"
        );
        foreach ($bindedValues as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }
        $stmt->execute();
    }
}

/**
 * Configure api user in centreon gorgone configuration file
 * and create user in database if needed
 *
 * @param CentreonDB $pearDB
 */
function configureGorgoneApiUser(CentreonDB $pearDB): void
{
    $gorgoneUser = null;

    $apiConfigurationFile = getGorgoneApiConfigurationFilePath();
    if ($apiConfigurationFile !== null && is_writable($apiConfigurationFile)) {
        $apiConfigurationContent = file_get_contents($apiConfigurationFile);
        if (
            preg_match('/@GORGONE_USER@/', $apiConfigurationContent)
            && preg_match('/@GORGONE_PASSWORD@/', $apiConfigurationContent)
        ) {
            $gorgoneUser = 'centreon-gorgone';
            $gorgonePassword = generatePassword();
            file_put_contents(
                $apiConfigurationFile,
                str_replace(
                    ['@GORGONE_USER@', '@GORGONE_PASSWORD@'],
                    [$gorgoneUser, $gorgonePassword],
                    $apiConfigurationContent,
                ),
            );

            createGorgoneUser(
                $pearDB,
                $gorgoneUser,
                password_hash($gorgonePassword, CentreonAuth::PASSWORD_HASH_ALGORITHM)
            );
        }
    }
}

/**
 * Create centreon-gorgone user in database
 *
 * @param CentreonDB $pearDB
 * @param string $userAlias
 * @param string $hashedPassword
 */
function createGorgoneUser(CentreonDB $pearDB, string $userAlias, string $hashedPassword): void
{
    $statementCreateUser = $pearDB->prepare(
        "INSERT INTO `contact`
        (`timeperiod_tp_id`, `timeperiod_tp_id2`, `contact_name`, `contact_alias`,
        `contact_lang`, `contact_host_notification_options`, `contact_service_notification_options`,
        `contact_email`, `contact_pager`, `contact_comment`, `contact_oreon`, `contact_admin`, `contact_type_msg`,
        `contact_activate`, `contact_auth_type`, `contact_ldap_dn`, `contact_enable_notifications`)
        VALUES(1, 1, :gorgoneUser, :gorgoneUser, 'en_US.UTF-8', 'n', 'n', 'gorgone@localhost', NULL, NULL,
        '0', '1', 'txt', '1', 'local', NULL, '0')"
    );
    $statementCreateUser->bindValue(":gorgoneUser", $userAlias, \PDO::PARAM_STR);
    $statementCreateUser->execute();

    $statementCreatePassword = $pearDB->prepare(
        "INSERT INTO `contact_password` (`password`, `contact_id`, `creation_date`)
        SELECT :gorgonePassword, c.contact_id, (SELECT UNIX_TIMESTAMP(NOW()))
        FROM contact c
        WHERE c.contact_alias = :gorgoneUser"
    );
    $statementCreatePassword->bindValue(":gorgoneUser", $userAlias, \PDO::PARAM_STR);
    $statementCreatePassword->bindValue(":gorgonePassword", $hashedPassword, \PDO::PARAM_STR);
    $statementCreatePassword->execute();
}

/**
 * Exclude Gorgone / MBI / MAP users from password policy
 *
 * @param CentreonDB $pearDB
 */
function excludeUsersFromPasswordPolicy(CentreonDB $pearDB): void
{
    $usersToExclude = [
        ':bi' => 'CBIS',
        ':map' => 'centreon-map'
    ];

    $gorgoneUser = getGorgoneApiUser();
    if ($gorgoneUser !== null) {
        $usersToExclude[':gorgone'] = $gorgoneUser;
    }

    $statement = $pearDB->prepare(
        "INSERT INTO `password_expiration_excluded_users` (provider_configuration_id, user_id)
        SELECT pc.id, c.contact_id
        FROM `provider_configuration` pc, `contact` c
        WHERE pc.name = 'local'
        AND c.contact_alias IN (" . implode(',', array_keys($usersToExclude)) . ")
        GROUP BY pc.id, c.contact_id
        ON DUPLICATE KEY UPDATE provider_configuration_id = provider_configuration_id"
    );

    foreach ($usersToExclude as $userToExcludeParam => $usersToExcludeValue) {
        $statement->bindValue($userToExcludeParam, $usersToExcludeValue, \PDO::PARAM_STR);
    }

    $statement->execute();
}

/**
 * Get centreon-gorgone api user from configuration file
 *
 * @return string|null
 */
function getGorgoneApiUser(): ?string
{
    $gorgoneUser = null;

    $apiConfigurationFile = getGorgoneApiConfigurationFilePath();
    if ($apiConfigurationFile !== null) {
        $configuration = Yaml::parseFile($apiConfigurationFile);

        if (isset($configuration['gorgone']['tpapi'][0]['username'])) {
            $gorgoneUser = $configuration['gorgone']['tpapi'][0]['username'];
        } elseif (isset($configuration['gorgone']['tpapi'][1]['username'])) {
            $gorgoneUser = $configuration['gorgone']['tpapi'][1]['username'];
        }
    }

    return $gorgoneUser;
}

/**
 * Get centreon-gorgone api configuration file path if found and readable
 *
 * @return string|null
 */
function getGorgoneApiConfigurationFilePath(): ?string
{
    $gorgoneEtcPath = _CENTREON_ETC_ . '/../centreon-gorgone';

    $apiConfigurationFile = $gorgoneEtcPath . '/config.d/31-centreon-api.yaml';

    if (file_exists($apiConfigurationFile) && is_readable($apiConfigurationFile)) {
        return $apiConfigurationFile;
    }

    return null;
}
