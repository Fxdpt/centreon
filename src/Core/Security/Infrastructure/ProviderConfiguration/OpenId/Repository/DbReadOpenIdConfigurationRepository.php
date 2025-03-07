<?php

/*
 * Copyright 2005 - 2022 Centreon (https://www.centreon.com/)
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

declare(strict_types=1);

namespace Core\Security\Infrastructure\ProviderConfiguration\OpenId\Repository;

use Centreon\Infrastructure\DatabaseConnection;
use Centreon\Infrastructure\Repository\AbstractRepositoryDRB;
use Core\Contact\Domain\Model\ContactGroup;
use Core\Contact\Domain\Model\ContactTemplate;
use Core\Contact\Infrastructure\Repository\DbContactGroupFactory;
use Core\Contact\Infrastructure\Repository\DbContactTemplateFactory;
use Core\Security\Infrastructure\ProviderConfiguration\OpenId\Builder\DbConfigurationBuilder;
use Core\Security\Application\ProviderConfiguration\Repository\ReadProviderConfigurationsRepositoryInterface;
use Core\Security\Application\ProviderConfiguration\OpenId\Repository\ReadOpenIdConfigurationRepositoryInterface
    as ReadRepositoryInterface;
use Core\Security\Domain\ProviderConfiguration\OpenId\Model\Configuration;
use Core\Security\Domain\ProviderConfiguration\OpenId\Model\AuthorizationRule;
use Core\Security\Infrastructure\Repository\DbAccessGroupFactory;

class DbReadOpenIdConfigurationRepository extends AbstractRepositoryDRB implements
    ReadProviderConfigurationsRepositoryInterface,
    ReadRepositoryInterface
{
    /**

     * @param DatabaseConnection $db
     */
    public function __construct(DatabaseConnection $db)
    {
        $this->db = $db;
    }

    /**
     * @inheritDoc
     */
    public function findConfigurations(): array
    {
        $configurations = [];

        $openIdConfiguration = $this->findConfiguration();
        if ($openIdConfiguration !== null) {
            $configurations[] = $openIdConfiguration;
        }

        return $configurations;
    }

    /**
     * @inheritDoc
     */
    public function findConfiguration(): ?Configuration
    {
        $statement = $this->db->query(
            $this->translateDbName("SELECT * FROM `:db`.`provider_configuration` WHERE name = 'openid'")
        );
        $configuration = null;
        if ($statement !== false && $result = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $this->validateJsonRecord(
                $result['custom_configuration'],
                __DIR__ . '/CustomConfigurationSchema.json',
            );
            $customConfiguration = json_decode($result['custom_configuration'], true);
            $customConfiguration['contact_template'] = $customConfiguration['contact_template_id'] !== null
                ? $this->getContactTemplate($customConfiguration['contact_template_id'])
                : null;
            $customConfiguration['contact_group'] = $customConfiguration['contact_group_id'] !== null
                ? $this->getContactGroup($customConfiguration['contact_group_id'])
                : null;
            $customConfiguration['authorization_rules'] = $this->getAuthorizationRulesByProviderId((int) $result["id"]);
            $configuration = DbConfigurationBuilder::create($result, $customConfiguration);
        }

        return $configuration;
    }

    /**
     * Get Contact Template
     *
     * @param int $contactTemplateId
     * @return ContactTemplate|null
     * @throws \Throwable
     */
    private function getContactTemplate(int $contactTemplateId): ?ContactTemplate
    {
        $statement = $this->db->prepare(
            "SELECT
                contact_id,
                contact_name
            FROM contact
            WHERE
                contact_id = :contactTemplateId
                AND contact_register = 0"
        );
        $statement->bindValue(':contactTemplateId', $contactTemplateId, \PDO::PARAM_INT);
        $statement->execute();

        $contactTemplate = null;
        if ($statement !== false && $result = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $contactTemplate = DbContactTemplateFactory::createFromRecord($result);
        }

        return $contactTemplate;
    }

    /**
     * Get Contact Group
     *
     * @param int $contactGroupId
     * @return ContactGroup|null
     * @throws \Throwable
     */
    private function getContactGroup(int $contactGroupId): ?ContactGroup
    {
        $statement = $this->db->prepare(
            "SELECT
                cg_id,
                cg_name
            FROM contactgroup
            WHERE
                cg_id = :contactGroupId"
        );
        $statement->bindValue(':contactGroupId', $contactGroupId, \PDO::PARAM_INT);
        $statement->execute();

        $contactGroup = null;
        if ($statement !== false && $result = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $contactGroup = DbContactGroupFactory::createFromRecord($result);
        }

        return $contactGroup;
    }

    /**
     * Get Authorization Rules
     *
     * @param integer $providerConfigurationId
     * @return AuthorizationRule[]
     * @throws \Throwable
     */
    private function getAuthorizationRulesByProviderId(int $providerConfigurationId): array
    {
        $statement = $this->db->prepare(
            "SELECT * from security_provider_access_group_relation spagn
                INNER JOIN acl_groups ON acl_group_id = spagn.access_group_id
                WHERE spagn.provider_configuration_id = :providerConfigurationId"
        );
        $statement->bindValue(':providerConfigurationId', $providerConfigurationId, \PDO::PARAM_INT);
        $statement->execute();

        $authorizationRules = [];
        while ($statement !== false && is_array($result = $statement->fetch(\PDO::FETCH_ASSOC))) {
            $accessGroup = DbAccessGroupFactory::createFromRecord($result);
            $authorizationRules[] = new AuthorizationRule($result['claim_value'], $accessGroup);
        }
        return $authorizationRules;
    }
}
