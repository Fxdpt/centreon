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

namespace Core\Infrastructure\RealTime\Hypermedia;

use Centreon\Domain\Contact\Contact;
use Centreon\Domain\Contact\Interfaces\ContactInterface;
use Core\Application\RealTime\UseCase\FindService\FindServiceResponse;

class ServiceHypermediaProvider extends AbstractHypermediaProvider implements HypermediaProviderInterface
{
    public const URI_CONFIGURATION = '/main.php?p=60201&o=c&service_id={serviceId}',
                 URI_EVENT_LOGS = '/main.php?p=20301&svc={hostId}_{serviceId}',
                 URI_REPORTING = '/main.php?p=30702&period=yesterday&start=&end=&host_id={hostId}&item={serviceId}',
                 URI_SERVICEGROUP_CONFIGURATION = '/main.php?p=60203&o=c&sg_id={servicegroupId}',
                 URI_SERVICE_CATEGORY_CONFIGURATION = '/main.php?p=60209&o=c&sc_id={serviceCategoryId}';

    public const ENDPOINT_SERVICE_TIMELINE = 'centreon_application_monitoring_gettimelinebyhostandservice',
                 ENDPOINT_PERFORMANCE_GRAPH = 'monitoring.metric.getServicePerformanceMetrics',
                 ENDPOINT_STATUS_GRAPH = 'monitoring.metric.getServiceStatusMetrics',
                 ENDPOINT_SERVICE_NOTIFICATION_POLICY = 'configuration.service.notification-policy';

    /**
     * @param ContactInterface $contact
     * @param UriGenerator $uriGenerator
     */
    public function __construct(
        private ContactInterface $contact,
        private UriGenerator $uriGenerator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isValidFor(mixed $data): bool
    {
        return ($data instanceof FindServiceResponse);
    }

    /**
     * Create configuration redirection uri for Service resource
     *
     * @param array<string, mixed> $parameters
     * @return string|null
     */
    public function createForConfiguration(array $parameters): ?string
    {
        $roles = [
            Contact::ROLE_CONFIGURATION_SERVICES_WRITE,
            Contact::ROLE_CONFIGURATION_SERVICES_READ
        ];

        if (! $this->canContactAccessPages($this->contact, $roles)) {
            return null;
        }

        return $this->uriGenerator->generateUri(
            self::URI_CONFIGURATION,
            ['{serviceId}' => $parameters['serviceId']]
        );
    }

    /**
     * Create reporting redirection uri for Service resource
     *
     * @param array<string, mixed> $parameters
     * @return string|null
     */
    public function createForReporting(array $parameters): ?string
    {
        if (! $this->canContactAccessPages($this->contact, [Contact::ROLE_REPORTING_DASHBOARD_SERVICES])) {
            return null;
        }

        return $this->uriGenerator->generateUri(
            self::URI_REPORTING,
            [
                '{serviceId}' => $parameters['serviceId'],
                '{hostId}' => $parameters['hostId']
            ]
        );
    }

    /**
     * Create event logs redirection uri for Service resource
     *
     * @param array<string, mixed> $parameters
     * @return string|null
     */
    public function createForEventLog(array $parameters): ?string
    {
        if (! $this->canContactAccessPages($this->contact, [Contact::ROLE_MONITORING_EVENT_LOGS])) {
            return null;
        }

        return $this->uriGenerator->generateUri(
            self::URI_EVENT_LOGS,
            [
                '{serviceId}' => $parameters['serviceId'],
                '{hostId}' => $parameters['hostId']
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function createEndpoints(mixed $response): array
    {
        $parameters = [
            'hostId' => $response->hostId,
            'serviceId' => $response->id,
        ];

        return [
            'timeline' => $this->uriGenerator->generateEndpoint(self::ENDPOINT_SERVICE_TIMELINE, $parameters),
            'status_graph' => $this->uriGenerator->generateEndpoint(self::ENDPOINT_STATUS_GRAPH, $parameters),
            'performance_graph' => $response->hasGraphData
                ? $this->uriGenerator->generateEndpoint(self::ENDPOINT_PERFORMANCE_GRAPH, $parameters)
                : null,
            'notification_policy' => $this->uriGenerator->generateEndpoint(
                self::ENDPOINT_SERVICE_NOTIFICATION_POLICY,
                $parameters
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createInternalUris(mixed $response): array
    {
        $parameters = [
            'hostId' => $response->hostId,
            'serviceId' => $response->id,
        ];
        return [
            'configuration' => $this->createForConfiguration($parameters),
            'logs' => $this->createForEventLog($parameters),
            'reporting' => $this->createForReporting($parameters)
        ];
    }

    /**
     * Create servicegroup configuration redirection uri
     *
     * @param array<string, mixed> $parameters
     * @return string|null
     */
    public function createForGroup(array $parameters): ?string
    {
        $roles = [
            Contact::ROLE_CONFIGURATION_SERVICES_SERVICE_GROUPS_READ_WRITE,
            Contact::ROLE_CONFIGURATION_SERVICES_SERVICE_GROUPS_READ
        ];

        if (! $this->canContactAccessPages($this->contact, $roles)) {
            return null;
        }

        return $this->uriGenerator->generateUri(
            self::URI_SERVICEGROUP_CONFIGURATION,
            ['{servicegroupId}' => $parameters['servicegroupId']]
        );
    }

    /**
     * @inheritDoc
     */
    public function convertGroupsForPresenter(array $groups): array
    {
        return array_map(
            fn (array $group) => [
                'id' => $group['id'],
                'name' => $group['name'],
                'configuration_uri' => $this->createForGroup(['servicegroupId' => $group['id']])
            ],
            $groups
        );
    }

    /**
     * Create service category configuration redirection uri
     *
     * @param array<string, mixed> $parameters
     * @return string|null
     */
    public function createForCategory(array $parameters): ?string
    {
        $roles = [
            Contact::ROLE_CONFIGURATION_SERVICES_CATEGORIES_READ_WRITE,
            Contact::ROLE_CONFIGURATION_SERVICES_CATEGORIES_READ
        ];

        if (! $this->canContactAccessPages($this->contact, $roles)) {
            return null;
        }

        return $this->uriGenerator->generateUri(
            self::URI_SERVICE_CATEGORY_CONFIGURATION,
            ['{serviceCategoryId}' => $parameters['categoryId']]
        );
    }

    /**
     * @inheritDoc
     */
    public function convertCategoriesForPresenter(array $categories): array
    {
        return array_map(
            fn (array $category) => [
                'id' => $category['id'],
                'name' => $category['name'],
                'configuration_uri' => $this->createForCategory(['categoryId' => $category['id']])
            ],
            $categories
        );
    }
}
