<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCP\Http\Client\IClientService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IURLGenerator;

class UpdateAddressBookService {
    public function __construct(
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
    ) {
    }

    private function getUrlForEndpoint(string $endpoint): string {
        $url = $this->urlGenerator->linkToRoute('sdkmc.address_book.show', ['type' => $endpoint]);
        if (str_starts_with($url, '/index.php/')) {
            $url = substr($url, 10);
        }
        return $this->urlGenerator->getAbsoluteURL($url);
    }

    public function updateAddressBook(): void {
        $client = $this->clientService->newClient();
        $base = $this->appConfig->getAppValueString('addressBookBaseUrl', 'https://open-test.digg.se/addressbook/api/');
        if ($base === '') {
            return;
        }

        $endpoints = ['addresses', 'organizations', 'codesystems'];

        foreach ($endpoints as $endpoint) {
            $address = "$base$endpoint";
            $data = [];
            $links = [];
            $meta = [];

            do {
                $response = $client->get($address);
                $body = $response->getBody();
                if (!is_string($body)) {
                    throw new Exception('Was unable to get response while attempting to update the address book');
                }
                $struct = json_decode($body);
                if (!is_object($struct) || !property_exists($struct, 'links') || !property_exists($struct, 'meta') || !property_exists($struct, 'data') || !is_object($struct->links) || !is_array($struct->data)) {
                    throw new Exception('Was unable to get response while attempting to update the address book');
                }
                $links = $struct->links;
                $meta = $struct->meta;
                $data = array_merge($data, $struct->data);
                $address = !property_exists($links, 'next') || !is_string($links->next) || trim($links->next) === '' ? null : $links->next;
            } while ($address !== null);

            $url = $this->getUrlForEndpoint($endpoint);
            $links = ['self' => $url, 'last' => $url, 'first' => $url]; // links is updated to point to our local copy of the api
            $this->appConfig->setAppValueArray('addressBook' . ucfirst($endpoint), ['meta' => $meta, 'links' => $links, 'data' => $data], true, false);
        }
    }
}
