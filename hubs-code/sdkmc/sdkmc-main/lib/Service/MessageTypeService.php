<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use OCA\SdkMc\Event\SerializeMailMessageEvent;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Account;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;

class MessageTypeService {
    public function __construct(
        private IMailManager $mailManager,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<Message> $emailList
     * @return array<mixed>
     */
    public function enhanceMessages(array $emailList, IMAPClientFactory $clientFactory, Account $account, Mailbox $mailbox): array {
        $client = $clientFactory->getClient($account);
        try {
            $data = array_map(function ($m) use ($client, $account, $mailbox) {
                $result = $m->jsonSerialize();
                if (!is_array($result)) {
                    $this->logger->warning('Failed to serialize message UID ' . $m->getUid() . ' in mailbox ' . $mailbox->getName());
                    return null;
                }

                try {
                    $source = $this->mailManager->getSource($client, $account, $mailbox->getName(), $m->getUid());
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to fetch IMAP source for message UID ' . $m->getUid() . ' in mailbox ' . $mailbox->getName() . ': ' . $e->getMessage());
                    return null;
                }
                if (!is_string($source)) {
                    $this->logger->warning('Could not fetch IMAP source for message UID ' . $m->getUid() . ' in mailbox ' . $mailbox->getName() . ', message may have been deleted from IMAP');
                    return null;
                }

                $result['itsl'] = [];
                $result['itsl']['sdk'] = json_decode($this->fetchHeader($source, 'X-Sdk'), true);
                $result['itsl']['messageType'] = $this->fetchHeader($source, 'X-MessageType');
                $result['itsl']['received'] = $this->fetchHeader($source, 'Received');
                $result['itsl']['noReply'] = $this->fetchHeader($source, 'X-NoReply');
                $result['itsl']['noReply'] = $result['itsl']['noReply'] === '' ? '0' : $result['itsl']['noReply'];
                $this->eventDispatcher->dispatchTyped(new SerializeMailMessageEvent($m, $account, $result));
                return $result;
            }, $emailList);
            $emailList = array_values(array_filter($data, fn ($item) => $item !== null));
        } finally {
            $client->logout();
        }
        return $emailList;
    }

    public function getMessageTypeFromEmail(string $fromEmail, string $toEmail): string {
        $map = [
            'sdk' => 'sdk_message',
            'fax' => 'fax_message',
            'sms' => 'sms_message',
            'personlig' => 'internal_message',
            'gruppbox' => 'internal_message',
            'securemail' => 'secure_email',
        ];
        $tld = $this->getTldFromEmail($fromEmail);
        if (!array_key_exists($tld, $map)) {
            $tld = 'personlig';
        }
        $result = $map[$tld];

        if ($result === 'internal_message' && $toEmail !== '') {
            $tld = $this->getTldFromEmail($toEmail);
            if (!array_key_exists($tld, $map)) {
                throw new Exception('Message type does not exist');
            }
            $result = $map[$tld];
        }

        return $result;
    }

    public function getTldFromEmail(string $email): string {
        $recipient = explode('<', $email);
        $recipient = explode('>', end($recipient));
        $recipient = $recipient[0];
        $tmp = explode('@', $recipient);
        $domain = end($tmp);
        $tmp = explode('.', $domain);
        $extension = end($tmp);
        return $extension;
    }

    public function fetchHeader(string $source, string $headerName): string {
        $header = explode("\r\n\r\n", $source);
        $header = $header[0];
        $match = [];
        preg_match('/^' . $headerName . ': (.*)(\n\s+(.*))*/im', $header, $match);
        if (!array_key_exists(0, $match)) {
            return '';
        }
        $header = str_replace("\r\n ", '', $match[0]);
        $header = trim($header);
        return substr($header, mb_strlen($headerName) + 2);
    }
}
