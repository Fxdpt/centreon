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

declare(strict_types=1);

namespace Tests\Core\Security\Application\ProviderConfiguration\Local\UseCase\FindConfiguration;

use Core\Application\Common\UseCase\ResponseStatusInterface;
use Core\Security\Application\ProviderConfiguration\Local\UseCase\FindConfiguration\FindConfigurationPresenterInterface;
use Core\Security\Application\ProviderConfiguration\Local\UseCase\FindConfiguration\FindConfigurationResponse;
use Symfony\Component\HttpFoundation\Response;

class FindConfigurationPresenterFake implements FindConfigurationPresenterInterface
{
    /**
     * @var FindConfigurationResponse
     */
    public $response;

    /**
     * @var ResponseStatusInterface|null
     */
    private $responseStatus;

    /**
     * @var mixed[]
     */
    private $responseHeaders;

    /**
     * @param FindConfigurationResponse $response
     */
    public function present(mixed $response): void
    {
        $this->response = $response;
    }

    /**
     * @return Response
     */
    public function show(): Response
    {
        return new Response();
    }

    /**
     * @inheritDoc
     */
    public function setResponseStatus(?ResponseStatusInterface $responseStatus): void
    {
        $this->responseStatus = $responseStatus;
    }

    /**
     * @inheritDoc
     */
    public function getResponseStatus(): ?ResponseStatusInterface
    {
        return $this->responseStatus;
    }

    /**
     * @inheritDoc
     */
    public function setResponseHeaders(array $responseHeaders): void
    {
        $this->responseHeaders = $responseHeaders;
    }

    /**
     * @inheritDoc
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * @inheritDoc
     */
    public function getPresentedData(): mixed
    {
        return $this->response;
    }
}
